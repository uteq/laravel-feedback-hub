<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Exceptions\OsInboxRejectedException;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;

final class OsInboxFeedbackClient
{
    public function isConfigured(): bool
    {
        return filled(config('feedback-hub.os_inbox.url'))
            && filled(config('feedback-hub.os_inbox.key'));
    }

    /**
     * @throws OsInboxRejectedException on a 4xx answer (will not fix itself, do not retry)
     * @throws RequestException on a 5xx answer (retryable)
     */
    public function send(FeedbackReport $report): void
    {
        $payload = [
            'version' => 1,
            'uuid' => $report->uuid,
            'reference' => $report->reference,
            'project' => $report->project,
            'type' => $report->type,
            'title' => $report->title,
            'description' => $report->description,
            'page_url' => $report->page_url,
            'element_selector' => $report->element_selector,
            'console_errors' => array_values(array_slice($report->console_errors ?? [], -30)),
            'network_requests' => array_values(array_slice($report->network_requests ?? [], -30)),
            'browser' => (object) Arr::only($report->session_data ?? [], ['userAgent', 'viewport', 'locale']),
            'reporter_name' => $report->reporter_name,
            'reporter_email' => $report->reporter_email,
            'intent' => [
                'goal' => $report->intent['goal'] ?? null,
                'expected' => $report->intent['expected'] ?? null,
                'actual' => $report->intent['actual'] ?? null,
            ],
            'github_issue_url' => $report->github_issue_url,
            'linear_issue_url' => $report->linear_issue_url,
            'created_at' => $report->created_at->toIso8601String(),
        ];

        // The recording stays in the app (it can be large); the receiver gets a link that needs admin access.
        // Only present when there is a recording, so reports without one keep the exact version 1 payload.
        if ($videoUrl = $report->videoUrl()) {
            $payload['video_url'] = $videoUrl;
        }

        $request = Http::withToken((string) config('feedback-hub.os_inbox.key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->asMultipart();

        if ($report->screenshot_disk && $report->screenshot_path) {
            $disk = Storage::disk((string) $report->screenshot_disk);
            $path = (string) $report->screenshot_path;

            if ($disk->exists($path)) {
                $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'png';
                $request->attach('screenshot', $disk->get($path), $report->uuid.'.'.$extension);
            }
        }

        $response = $request->post((string) config('feedback-hub.os_inbox.url'), [
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        if ($response->clientError()) {
            $excerpt = $this->excerpt($response);

            Log::warning('os_inbox_rejected', ['report' => $report->getKey(), 'status' => $response->status(), 'excerpt' => $excerpt]);

            throw new OsInboxRejectedException($response->status(), $excerpt);
        }

        $response->throw();
    }

    private function excerpt(Response $response): string
    {
        $body = $response->json('message');

        if (! is_string($body) || $body === '') {
            $body = $response->body();
        }

        $body = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return mb_substr(app(FeedbackPayloadSanitizer::class)->redactString($body), 0, 300);
    }
}
