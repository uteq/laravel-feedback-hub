<?php

namespace Uteq\FeedbackHub\Support;

use Illuminate\Support\Str;
use Uteq\FeedbackHub\Models\FeedbackReport;

class IssueBodyBuilder
{
    public function __construct(
        private FeedbackPayloadSanitizer $sanitizer,
    ) {}

    public function build(FeedbackReport $report): string
    {
        $lines = [
            "**Reference:** {$report->reference}",
            '**Project:** '.$this->redact((string) $report->project),
            "**Type:** {$report->type}",
            '**URL:** '.$this->redact((string) $report->page_url),
            '**Reporter:** '.$this->reporter($report),
            "**Admin:** {$report->adminUrl()}",
            "**Date:** {$report->created_at?->format('Y-m-d H:i')}",
            '',
        ];

        if ($report->description) {
            $lines[] = '## Description';
            $lines[] = '';
            $lines[] = $this->redact((string) $report->description);
            $lines[] = '';
        }

        if ($report->element_selector) {
            $lines[] = '**Element:** `'.$this->redact((string) $report->element_selector).'`';
            $lines[] = '';
        }

        if ($report->screenshot_path) {
            $lines[] = '**Screenshot:** stored in the app admin detail view.';
            $lines[] = '';
        }

        $this->appendJsonDetails($lines, 'Session data', $report->session_data);
        $this->appendJsonDetails($lines, 'Console errors', $report->console_errors);
        $this->appendJsonDetails($lines, 'Network requests', $report->network_requests);
        $this->appendJsonDetails($lines, 'Form state', $report->form_state);

        return implode("\n", $lines);
    }

    public function telegramMessage(FeedbackReport $report): string
    {
        $lines = [
            '<b>Nieuwe feedback</b>',
            $this->html($this->redact((string) $report->project)),
            '',
            '<b>'.$this->html((string) $report->reference).'</b> '.$this->html($this->summary((string) $report->title, 140)),
            $this->html($this->typeLabel((string) $report->type).' van '.$this->reporter($report)),
            'Pagina: '.$this->html($this->pageLabel((string) $report->page_url)),
        ];

        if ($report->description) {
            $lines[] = 'Omschrijving: '.$this->html($this->summary((string) $report->description, 220));
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{text: string, url: string}>
     */
    public function telegramButtons(FeedbackReport $report): array
    {
        return array_values(array_filter([
            $this->button($report->github_issue_url ? ($report->github_issue_number ? "GitHub #{$report->github_issue_number}" : 'GitHub') : null, $report->github_issue_url),
            $this->button($report->linear_issue_identifier ?: 'Linear', $report->linear_issue_url),
            $this->button('Admin', $report->adminUrl()),
        ]));
    }

    /**
     * @return array{text: string, url: string}|null
     */
    private function button(?string $text, ?string $url): ?array
    {
        if (! $text || ! $url || ! $this->isTelegramButtonUrl($url)) {
            return null;
        }

        return [
            'text' => $text,
            'url' => $url,
        ];
    }

    private function isTelegramButtonUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.localhost');
    }

    private function reporter(FeedbackReport $report): string
    {
        if ($report->reporter_name && $report->reporter_email) {
            return "{$report->reporter_name} ({$report->reporter_email})";
        }

        return $report->reporter_name ?: $report->reporter_email ?: 'Unknown';
    }

    private function redact(string $value): string
    {
        return $this->sanitizer->redactString($value);
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function summary(string $value, int $limit): string
    {
        return Str::limit(Str::squish($this->redact($value)), $limit);
    }

    private function pageLabel(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = preg_replace('/\/[0-9a-f]{8}-[0-9a-f-]{20,}(?=\/|$)/i', '/...', $path) ?: $path;

        return Str::limit($path, 90);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'bug' => 'Bug',
            'suggestion' => 'Suggestie',
            'question' => 'Vraag',
            default => $type,
        };
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>|null  $payload
     */
    private function appendJsonDetails(array &$lines, string $title, ?array $payload): void
    {
        if (empty($payload)) {
            return;
        }

        $lines[] = "<details><summary>{$title}</summary>";
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines[] = '```';
        $lines[] = '</details>';
        $lines[] = '';
    }
}
