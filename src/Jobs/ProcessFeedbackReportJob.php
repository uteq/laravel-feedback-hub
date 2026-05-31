<?php

namespace Uteq\FeedbackHub\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Uteq\FeedbackHub\Clients\GitHubFeedbackClient;
use Uteq\FeedbackHub\Clients\LinearFeedbackClient;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;
use Uteq\FeedbackHub\Models\FeedbackReport;
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
                    $this->markFailed('telegram_not_configured');

                    return;
                }

                $this->report->refresh();

                $result = $this->sendTelegramNotification($telegram, $bodyBuilder);
                $this->report->forceFill([
                    'telegram_message_id' => $result['message_id'],
                    'telegram_sent_at' => now(),
                ])->save();
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

    private function markFailed(string $message): void
    {
        $this->report->refresh()->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr($message, 0, 5000),
        ])->save();
    }
}
