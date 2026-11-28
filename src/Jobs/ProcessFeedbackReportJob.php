<?php

namespace Uteq\FeedbackHub\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Uteq\FeedbackHub\Clients\GitHubFeedbackClient;
use Uteq\FeedbackHub\Clients\LinearFeedbackClient;
use Uteq\FeedbackHub\Clients\OsInboxFeedbackClient;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;
use Uteq\FeedbackHub\Exceptions\OsInboxRejectedException;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

class ProcessFeedbackReportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public FeedbackReport $report,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return 'feedback-hub-report:'.$this->report->getKey();
    }

    public function handle(
        GitHubFeedbackClient $github,
        LinearFeedbackClient $linear,
        TelegramFeedbackClient $telegram,
        IssueBodyBuilder $bodyBuilder,
    ): void {
        $this->report->refresh();
        $missingConfiguration = [];

        try {
            if ($this->integrationEnabled('github') && ! $this->report->github_issue_url) {
                if (! $github->isConfigured()) {
                    $missingConfiguration[] = 'github_not_configured';
                } else {
                    $result = $github->createIssue($this->report);
                    $this->report->forceFill([
                        'github_issue_url' => $result['url'],
                        'github_issue_number' => $result['number'],
                        'github_synced_at' => now(),
                    ])->save();
                }
            }

            if ($this->integrationEnabled('linear') && ! $this->report->linear_issue_id) {
                if (! $linear->isConfigured()) {
                    $missingConfiguration[] = 'linear_not_configured';
                } else {
                    $result = $linear->createIssue($this->report);
                    $this->report->forceFill([
                        'linear_issue_id' => $result['id'],
                        'linear_issue_identifier' => $result['identifier'],
                        'linear_issue_url' => $result['url'],
                        'linear_synced_at' => now(),
                    ])->save();
                }
            }

            if ($missingConfiguration !== []) {
                $this->markFailed(implode(', ', $missingConfiguration));

                return;
            }

            if ($this->integrationEnabled('telegram') && ! $this->report->telegram_message_id) {
                if (! $telegram->isConfigured()) {
                    // Een ontbrekende configuratie is een keuze van de beheerder, geen storing:
                    // het rapport blijft geslaagd en alleen de telegram-stap wordt overgeslagen.
                    Log::warning('telegram_not_configured', ['report' => $this->report->getKey()]);
                } else {
                    $this->report->refresh();

                    $result = $this->sendTelegramNotification($telegram, $bodyBuilder);
                    $this->report->forceFill([
                        'telegram_message_id' => $result['message_id'],
                        'telegram_sent_at' => now(),
                    ])->save();
                }
            }

            if (filled(config('feedback-hub.os_inbox.url'))) {
                $osInbox = app(OsInboxFeedbackClient::class);

                if (! $osInbox->isConfigured()) {
                    Log::warning('os_inbox_not_configured', ['report' => $this->report->getKey()]);
                } else {
                    try {
                        $osInbox->send($this->report);
                    } catch (OsInboxRejectedException $rejection) {
                        $this->handleOsInboxRejection($rejection, $telegram);

                        return;
                    }
                }
            }

            $this->report->forceFill([
                'status' => 'synced',
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());
    }

    private function integrationEnabled(string $key): bool
    {
        return (bool) config("feedback-hub.{$key}.enabled");
    }

    /**
     * @return array{message_id: string}
     */
    private function sendTelegramNotification(TelegramFeedbackClient $telegram, IssueBodyBuilder $bodyBuilder): array
    {
        $message = $bodyBuilder->telegramMessage($this->report);
        $buttons = $bodyBuilder->telegramButtons($this->report);

        if ($this->report->screenshot_disk && $this->report->screenshot_path) {
            $disk = Storage::disk((string) $this->report->screenshot_disk);

            if ($disk->exists((string) $this->report->screenshot_path)) {
                return $telegram->sendPhoto(
                    $disk->get((string) $this->report->screenshot_path),
                    basename((string) $this->report->screenshot_path),
                    $message,
                    'HTML',
                    $buttons,
                );
            }
        }

        return $telegram->sendMessage($message, 'HTML', $buttons);
    }

    /**
     * A 4xx from the OS inbox will not fix itself: fail the report without retrying and tell the Telegram chat once.
     * Re-dispatching the job after the receiver is fixed works: Telegram is skipped (message id is set), the send runs again.
     */
    private function handleOsInboxRejection(OsInboxRejectedException $rejection, TelegramFeedbackClient $telegram): void
    {
        $this->markFailed($rejection->getMessage());

        if (! $this->integrationEnabled('telegram') || ! $telegram->isConfigured()) {
            return;
        }

        try {
            $telegram->sendMessage(
                sprintf(
                    '⚠️ %s is niet aangekomen in UteqOS (HTTP %d): %s',
                    e((string) ($this->report->reference ?? $this->report->getKey())),
                    $rejection->status,
                    e($rejection->excerpt),
                ),
                'HTML',
                [],
                $this->report->telegram_message_id ? (string) $this->report->telegram_message_id : null,
            );
        } catch (Throwable $exception) {
            Log::warning('os_inbox_rejection_alert_failed', [
                'report' => $this->report->getKey(),
                'error' => mb_substr(app(FeedbackPayloadSanitizer::class)->redactString($exception->getMessage()), 0, 300),
            ]);
        }
    }

    private function markFailed(string $message): void
    {
        $this->report->refresh()->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr(app(FeedbackPayloadSanitizer::class)->redactString($message), 0, 5000),
        ])->save();
    }
}
