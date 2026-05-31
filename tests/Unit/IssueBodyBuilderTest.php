<?php

use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

it('redacts sensitive values before building external issue and telegram bodies', function (): void {
    $report = FeedbackReport::query()->create([
        'project' => 'Test <Project>',
        'type' => 'bug',
        'title' => 'Knop faalt token=title-secret',
        'description' => 'Console toont Bearer body-secret',
        'page_url' => 'https://example.test/dashboard?token=url-secret&safe=ok',
        'element_selector' => 'button[data-api_key="element-secret"]',
        'session_data' => ['url' => 'https://example.test/dashboard?api_key=[filtered]'],
        'reporter_name' => 'Nathan <Jansen>',
        'reporter_email' => 'info@uteq.nl',
        'github_issue_url' => 'https://github.com/uteq/example/issues/12',
        'github_issue_number' => 12,
        'linear_issue_identifier' => 'UTEQ-12',
        'linear_issue_url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
    ]);

    $builder = app(IssueBodyBuilder::class);

    $issueBody = $builder->build($report);
    $telegramBody = $builder->telegramMessage($report);
    $telegramButtons = $builder->telegramButtons($report);

    expect($issueBody)
        ->toContain('token=[filtered]')
        ->toContain('Bearer [filtered]')
        ->toContain('api_key=[filtered]')
        ->not->toContain('title-secret')
        ->not->toContain('body-secret')
        ->not->toContain('url-secret')
        ->not->toContain('element-secret');

    expect($telegramBody)
        ->toContain('<b>Nieuwe feedback</b>')
        ->toContain('Test &lt;Project&gt;')
        ->toContain('token=[filtered]')
        ->toContain('Nathan &lt;Jansen&gt;')
        ->toContain('Pagina: /dashboard')
        ->not->toContain('URL:')
        ->not->toContain('https://example.test/dashboard')
        ->not->toContain('title-secret')
        ->not->toContain('url-secret');

    expect($telegramButtons[0])->toBe([
        'text' => 'GitHub #12',
        'url' => 'https://github.com/uteq/example/issues/12',
    ])
        ->and($telegramButtons[1])->toBe([
            'text' => 'UTEQ-12',
            'url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
        ])
        ->and($telegramButtons)->toHaveCount(2);
});
