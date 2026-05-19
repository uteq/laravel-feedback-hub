<?php

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

class GitHubFeedbackClient
{
    public function isConfigured(): bool
    {
        return filled(config('feedback-hub.github.token'))
            && filled(config('feedback-hub.github.repo'));
    }

    /**
     * @return array{url: string, number: int}
     *
     * @throws RequestException
     */
    public function createIssue(FeedbackReport $report): array
    {
        $payload = [
            'title' => "[{$report->project}] [{$report->type}] {$report->title}",
            'body' => app(IssueBodyBuilder::class)->build($report),
            'labels' => $this->csv((string) config('feedback-hub.github.labels')),
        ];

        $assignees = $this->csv((string) config('feedback-hub.github.assignees'));
        if ($assignees !== []) {
            $payload['assignees'] = $assignees;
        }

        $response = Http::withToken((string) config('feedback-hub.github.token'))
            ->acceptJson()
            ->post("https://api.github.com/repos/{$this->repo()}/issues", $payload)
            ->throw();

        return [
            'url' => (string) $response->json('html_url'),
            'number' => (int) $response->json('number'),
        ];
    }

    private function repo(): string
    {
        return trim((string) config('feedback-hub.github.repo'), '/');
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
