<?php

namespace Uteq\FeedbackHub\Support;

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
        $links = array_filter([
            $report->github_issue_url ? "GitHub: {$report->github_issue_url}" : null,
            $report->linear_issue_url ? "Linear: {$report->linear_issue_url}" : null,
            "Admin: {$report->adminUrl()}",
        ]);

        return implode("\n", array_filter([
            'Nieuwe feedback voor '.$this->redact((string) $report->project),
            "{$report->reference}: ".$this->redact((string) $report->title),
            "Type: {$report->type}",
            'Melder: '.$this->reporter($report),
            'URL: '.$this->redact((string) $report->page_url),
            implode("\n", $links),
        ]));
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
