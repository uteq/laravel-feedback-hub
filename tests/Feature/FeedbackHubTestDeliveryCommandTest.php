<?php

use Illuminate\Support\Facades\Http;
use Uteq\FeedbackHub\Models\FeedbackReport;

it('dry-runs delivery without creating local or external records', function (): void {
    Http::fake();

    $this->artisan('feedback-hub:test-delivery')
        ->expectsOutputToContain('Feedback Hub delivery dry-run passed.')
        ->expectsOutputToContain('Use --send to create real GitHub, Linear and Telegram deliveries.')
        ->assertSuccessful();

    expect(FeedbackReport::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('fails send mode when enabled integrations are missing configuration', function (): void {
    $this->artisan('feedback-hub:test-delivery --send')
        ->expectsOutputToContain('GitHub: missing token')
        ->expectsOutputToContain('GitHub: missing repo')
        ->expectsOutputToContain('Linear: missing token')
        ->expectsOutputToContain('Linear: missing team_id')
        ->expectsOutputToContain('Telegram: missing bot_token')
        ->expectsOutputToContain('Telegram: missing chat_id')
        ->assertFailed();

    expect(FeedbackReport::query()->count())->toBe(0);
});

it('sends a real delivery test through the configured clients', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake([
        'api.github.com/repos/uteq/example/issues' => Http::response([
            'html_url' => 'https://github.com/uteq/example/issues/12',
            'number' => 12,
        ], 201),
        'api.linear.app/graphql' => Http::response([
            'data' => [
                'issueCreate' => [
                    'success' => true,
                    'issue' => [
                        'id' => 'linear-id',
                        'identifier' => 'UTEQ-12',
                        'url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
                    ],
                ],
            ],
        ]),
        'api.telegram.org/bottelegram-token/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44],
        ]),
    ]);

    $this->artisan('feedback-hub:test-delivery --send')
        ->expectsOutputToContain('Feedback Hub delivery test sent.')
        ->expectsOutputToContain('GitHub: https://github.com/uteq/example/issues/12')
        ->expectsOutputToContain('Linear: https://linear.app/uteq/issue/UTEQ-12/test')
        ->expectsOutputToContain('Telegram message: 44')
        ->assertSuccessful();

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->status)->toBe('synced')
        ->and($report->github_issue_number)->toBe(12)
        ->and($report->linear_issue_identifier)->toBe('UTEQ-12')
        ->and($report->telegram_message_id)->toBe('44');

    Http::assertSentCount(3);
});
