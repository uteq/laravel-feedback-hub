<?php

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

class LinearFeedbackClient
{
    public function isConfigured(): bool
    {
        return filled(config('feedback-hub.linear.token'))
            && filled(config('feedback-hub.linear.team_id'));
    }

    /**
     * @return array{id: string, identifier: string, url: string}
     *
     * @throws RequestException
     */
    public function createIssue(FeedbackReport $report): array
    {
        $input = [
            'teamId' => (string) config('feedback-hub.linear.team_id'),
            'title' => "[{$report->project}] [{$report->type}] {$report->title}",
            'description' => app(IssueBodyBuilder::class)->build($report),
        ];

        if (filled(config('feedback-hub.linear.project_id'))) {
            $input['projectId'] = (string) config('feedback-hub.linear.project_id');
        }

        $labelIds = $this->csv((string) config('feedback-hub.linear.label_ids'));
        if ($labelIds !== []) {
            $input['labelIds'] = $labelIds;
        }

        $response = Http::withToken((string) config('feedback-hub.linear.token'))
            ->acceptJson()
            ->post('https://api.linear.app/graphql', [
                'query' => <<<'GRAPHQL'
                    mutation FeedbackHubIssueCreate($input: IssueCreateInput!) {
                        issueCreate(input: $input) {
                            success
                            issue {
                                id
                                identifier
                                url
                            }
                        }
                    }
                GRAPHQL,
                'variables' => ['input' => $input],
            ])
            ->throw();

        if ($response->json('errors')) {
            throw new RequestException($response);
        }

        return [
            'id' => (string) $response->json('data.issueCreate.issue.id'),
            'identifier' => (string) $response->json('data.issueCreate.issue.identifier'),
            'url' => (string) $response->json('data.issueCreate.issue.url'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
