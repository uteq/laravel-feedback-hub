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

it('adds an intent block to the issue body and a goal line to telegram', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Download faalt',
        'page_url' => 'https://example.test/facturen',
        'intent' => ['goal' => 'Factuur als PDF opslaan', 'expected' => 'Een download', 'actual' => 'Niets gebeurt'],
        'transcript' => [['role' => 'user', 'content' => 'TRANSCRIPT-MARKER']],
    ]);

    $builder = app(IssueBodyBuilder::class);
    $body = $builder->build($report);
    $telegram = $builder->telegramMessage($report);

    expect($body)
        ->toContain("## Intent\n\n**Doel:** Factuur als PDF opslaan\n**Verwacht:** Een download\n**Werkelijk:** Niets gebeurt")
        ->not->toContain('TRANSCRIPT-MARKER');

    expect($telegram)
        ->toContain('Doel: Factuur als PDF opslaan')
        ->not->toContain('TRANSCRIPT-MARKER');
});

it('omits the intent block when there is no intent', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Zonder intent',
        'page_url' => 'https://example.test',
    ]);

    $builder = app(IssueBodyBuilder::class);

    expect($builder->build($report))->not->toContain('## Intent')
        ->and($builder->telegramMessage($report))->not->toContain('Doel:');
});

it('redacts intent in external messages and escapes the telegram goal', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Download failed',
        'page_url' => 'https://example.test',
        'intent' => [
            'goal' => '<PDF> token=goal-secret',
            'expected' => 'Bearer expected-secret',
            'actual' => 'api_key=actual-secret',
        ],
    ]);

    $builder = app(IssueBodyBuilder::class);

    expect($builder->build($report))
        ->toContain('**Doel:** <PDF> token=[filtered]')
        ->toContain('**Verwacht:** Bearer [filtered]')
        ->toContain('**Werkelijk:** api_key=[filtered]')
        ->not->toContain('goal-secret', 'expected-secret', 'actual-secret');

    expect($builder->telegramMessage($report))
        ->toContain('Doel: &lt;PDF&gt; token=[filtered]')
        ->not->toContain('goal-secret');
});
