<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

class FeedbackHubTestDeliveryCommand extends Command
{
    protected $signature = 'feedback-hub:test-delivery {--send : Create real GitHub, Linear and Telegram deliveries}';

    protected $description = 'Validate Feedback Hub delivery payloads, optionally sending a real test report.';

    public function handle(IssueBodyBuilder $bodyBuilder): int
    {
        $send = (bool) $this->option('send');

        if (! $send) {
            $report = $this->sampleReport();

            $this->components->info('Feedback Hub delivery dry-run passed.');
            $this->line('Reference: '.$report->reference);
            $this->line('GitHub/Linear body bytes: '.strlen($bodyBuilder->build($report)));
            $this->line('Telegram body bytes: '.strlen($bodyBuilder->telegramMessage($report)));
            $this->line('Use --send to create real GitHub, Linear and Telegram deliveries.');

            return self::SUCCESS;
        }

        if (! $this->enabledIntegrationsAreConfigured()) {
            return self::FAILURE;
        }

        $report = $this->createStoredReport();

        app()->call([new ProcessFeedbackReportJob($report), 'handle']);

        $report->refresh();

        if ($report->status !== 'synced') {
            $this->components->error('Delivery test failed: '.$report->last_error);

            return self::FAILURE;
        }

        $this->components->info('Feedback Hub delivery test sent.');
        $this->line('Reference: '.$report->reference);
        $this->line('GitHub: '.$report->github_issue_url);
        $this->line('Linear: '.$report->linear_issue_url);
        $this->line('Telegram message: '.$report->telegram_message_id);

        return self::SUCCESS;
    }

    private function enabledIntegrationsAreConfigured(): bool
    {
        $ok = true;

        foreach ([
            'github' => ['GitHub', 'token', 'repo'],
            'linear' => ['Linear', 'token', 'team_id'],
            'telegram' => ['Telegram', 'bot_token', 'chat_id'],
        ] as $key => $definition) {
            $name = array_shift($definition);
            $fields = $definition;

            if (! (bool) config("feedback-hub.{$key}.enabled")) {
                $this->components->warn("{$name}: disabled");

                continue;
            }

            foreach ($fields as $field) {
                if (blank(config("feedback-hub.{$key}.{$field}"))) {
                    $this->components->error("{$name}: missing {$field}");
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function createStoredReport(): FeedbackReport
    {
        return FeedbackReport::query()->create($this->sampleAttributes());
    }

    private function sampleReport(): FeedbackReport
    {
        $report = new FeedbackReport($this->sampleAttributes());
        $report->uuid = (string) Str::uuid();
        $report->reference = 'FH-TEST-'.now()->format('YmdHis');
        $report->created_at = now();
        $report->updated_at = now();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAttributes(): array
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');

        return [
            'project' => (string) config('feedback-hub.project'),
            'type' => 'test',
            'title' => 'Feedback Hub delivery test '.$timestamp,
            'description' => 'Automated Feedback Hub delivery test.',
            'page_url' => url('/feedback-hub/test-delivery'),
            'element_selector' => 'body',
            'element_rect' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            'session_data' => [
                'url' => url('/feedback-hub/test-delivery'),
                'timestamp' => now()->toISOString(),
            ],
            'console_errors' => [],
            'network_requests' => [],
            'form_state' => [],
            'reporter_name' => 'Feedback Hub',
            'reporter_email' => 'feedback-hub@localhost',
        ];
    }
}
