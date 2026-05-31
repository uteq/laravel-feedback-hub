<?php

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
     * @return array{id: string, identifier: string, url: string, screenshot_url: string|null}
     *
     * @throws RequestException
     */
    public function createIssue(FeedbackReport $report): array
    {
        $description = app(IssueBodyBuilder::class)->build($report);
        $screenshotUrl = $this->uploadScreenshot($report);

        if ($screenshotUrl) {
            $description .= "\n\n## Screenshot\n\n![Screenshot]({$screenshotUrl})";
        }

        $input = [
            'teamId' => (string) config('feedback-hub.linear.team_id'),
            'title' => "[{$report->project}] [{$report->type}] {$report->title}",
            'description' => $description,
        ];

        if (filled(config('feedback-hub.linear.project_id'))) {
            $input['projectId'] = (string) config('feedback-hub.linear.project_id');
        }

        $labelIds = $this->csv((string) config('feedback-hub.linear.label_ids'));
        if ($labelIds !== []) {
            $input['labelIds'] = $labelIds;
        }

        $response = Http::withHeaders([
            'Authorization' => (string) config('feedback-hub.linear.token'),
        ])
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
            'screenshot_url' => $screenshotUrl,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function uploadScreenshot(FeedbackReport $report): ?string
    {
        if (! $report->screenshot_disk || ! $report->screenshot_path) {
            return null;
        }

        $disk = Storage::disk((string) $report->screenshot_disk);
        $path = (string) $report->screenshot_path;

        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);
        $size = strlen($contents);
        $mime = $report->screenshot_mime ?: $this->mimeFromPath($path);
        $filename = $this->screenshotFilename($report, $path, $mime);

        $response = Http::withHeaders([
            'Authorization' => (string) config('feedback-hub.linear.token'),
        ])
            ->acceptJson()
            ->post('https://api.linear.app/graphql', [
                'query' => <<<'GRAPHQL'
                    mutation FeedbackHubFileUpload($contentType: String!, $filename: String!, $size: Int!) {
                        fileUpload(contentType: $contentType, filename: $filename, size: $size) {
                            success
                            uploadFile {
                                uploadUrl
                                assetUrl
                                headers {
                                    key
                                    value
                                }
                            }
                        }
                    }
                GRAPHQL,
                'variables' => [
                    'contentType' => $mime,
                    'filename' => $filename,
                    'size' => $size,
                ],
            ])
            ->throw();

        if ($response->json('errors')) {
            throw new RequestException($response);
        }

        $uploadFile = $response->json('data.fileUpload.uploadFile');
        if (! $response->json('data.fileUpload.success') || ! is_array($uploadFile)) {
            throw new RuntimeException('Linear file upload URL creation failed.');
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ];

        foreach ($uploadFile['headers'] ?? [] as $header) {
            if (isset($header['key'], $header['value'])) {
                $headers[$header['key']] = $header['value'];
            }
        }

        Http::withHeaders($headers)
            ->withBody($contents, $mime)
            ->put((string) $uploadFile['uploadUrl'])
            ->throw();

        return (string) $uploadFile['assetUrl'];
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function screenshotFilename(FeedbackReport $report, string $path, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'png'),
        };

        return strtolower((string) $report->reference).'-screenshot.'.$extension;
    }
}
