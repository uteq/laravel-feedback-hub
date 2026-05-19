<?php

use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

it('redacts sensitive values before building external issue and telegram bodies', function (): void {
    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Knop faalt token=title-secret',
        'description' => 'Console toont Bearer body-secret',
        'page_url' => 'https://example.test/dashboard?token=url-secret&safe=ok',
        'element_selector' => 'button[data-api_key="element-secret"]',
        'session_data' => ['url' => 'https://example.test/dashboard?api_key=[filtered]'],
    ]);

    $builder = app(IssueBodyBuilder::class);

    $issueBody = $builder->build($report);
    $telegramBody = $builder->telegramMessage($report);

    expect($issueBody)
        ->toContain('token=[filtered]')
        ->toContain('Bearer [filtered]')
        ->toContain('api_key=[filtered]')
        ->not->toContain('title-secret')
        ->not->toContain('body-secret')
        ->not->toContain('url-secret')
        ->not->toContain('element-secret');

    expect($telegramBody)
        ->toContain('token=[filtered]')
        ->not->toContain('title-secret')
        ->not->toContain('url-secret');
});
