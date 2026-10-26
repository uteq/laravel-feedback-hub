<?php

use Illuminate\Support\Facades\Http;
use Uteq\FeedbackHub\Models\FeedbackReport;

it('dry-runs delivery without creating local or external records', function (): void {
    Http::fake();

    $this->artisan('feedback-hub:test-delivery')
        ->expectsOutputToContain('Feedback Hub delivery dry-run passed.')
        ->expectsOutputToContain('Use --send to create real GitHub, Linear, Telegram and OS inbox deliveries.')
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

it('lists os inbox during a dry run without sending or exposing the key', function (?string $url, ?string $key, string $message): void {
    config(['feedback-hub.os_inbox.url' => $url, 'feedback-hub.os_inbox.key' => $key]);
    Http::fake();
    $this->artisan('feedback-hub:test-delivery')->expectsOutputToContain($message)->doesntExpectOutputToContain('os-test-key')->assertSuccessful();
    Http::assertNothingSent();
    expect(FeedbackReport::query()->count())->toBe(0);
})->with([
    [null, null, 'OS inbox: disabled'],
    ['https://os.example.test/inbox', null, 'OS inbox: missing configuration'],
    ['https://os.example.test/inbox', 'os-test-key', 'OS inbox: configured'],
]);

it('sends a supported report type to os inbox in send mode', function (): void {
    config([
        'feedback-hub.github.enabled' => false,
        'feedback-hub.linear.enabled' => false,
        'feedback-hub.telegram.enabled' => false,
        'feedback-hub.os_inbox.url' => 'https://os.example.test/inbox',
        'feedback-hub.os_inbox.key' => 'os-test-key',
    ]);
    Http::fake();
    $this->artisan('feedback-hub:test-delivery --send')->expectsOutputToContain('OS inbox: configured')->doesntExpectOutputToContain('os-test-key')->assertSuccessful();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => json_decode(collect($request->data())->firstWhere('name', 'payload')['contents'], true)['type'] === 'question');
});
