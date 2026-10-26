<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Models\FeedbackReport;

final class OsInboxFeedbackClient
{
    public function isConfigured(): bool
    {
        return filled(config('feedback-hub.os_inbox.url'))
            && filled(config('feedback-hub.os_inbox.key'));
    }

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
            Log::warning('os_inbox_rejected', ['report' => $report->getKey(), 'status' => $response->status()]);

            return;
        }

        $response->throw();
    }
}
