<?php

use Illuminate\Support\Facades\Http;

it('fails when enabled integrations are missing configuration', function (): void {
    $this->artisan('feedback-hub:health')
        ->expectsOutputToContain('GitHub: missing configuration')
        ->expectsOutputToContain('Linear: missing configuration')
        ->expectsOutputToContain('Telegram: missing configuration')
        ->assertFailed();
});

it('validates live github linear and telegram configuration', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');
    config()->set('feedback-hub.linear.project_id', 'project-id');
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake([
        'api.github.com/repos/uteq/example' => Http::response([
            'has_issues' => true,
            'permissions' => [
                'admin' => false,
                'maintain' => false,
                'push' => false,
                'triage' => true,
            ],
        ]),
        'api.linear.app/graphql' => Http::response([
            'data' => [
                'viewer' => ['id' => 'viewer-id', 'name' => 'Nathan Jansen'],
                'team' => ['id' => 'team-id', 'name' => 'Uteq', 'key' => 'UTEQ'],
                'project' => ['id' => 'project-id', 'name' => 'Feedback'],
            ],
        ]),
        'api.telegram.org/bottelegram-token/getMe' => Http::response([
            'ok' => true,
            'result' => ['username' => 'feedback_bot'],
        ]),
        'api.telegram.org/bottelegram-token/getChat*' => Http::response([
            'ok' => true,
            'result' => ['title' => 'Feedback Hub'],
        ]),
    ]);

    $this->artisan('feedback-hub:health --live')
        ->expectsOutputToContain('GitHub live check passed for uteq/example')
        ->expectsOutputToContain('Linear live check passed for team Uteq')
        ->expectsOutputToContain('Telegram live check passed as @feedback_bot')
        ->expectsOutputToContain('Telegram chat check passed: Feedback Hub')
        ->assertSuccessful();

    Http::assertSentCount(4);
});

it('accepts github repo read access when issues are enabled', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.enabled', false);
    config()->set('feedback-hub.telegram.enabled', false);

    Http::fake([
        'api.github.com/repos/uteq/example' => Http::response([
            'has_issues' => true,
            'permissions' => [
                'admin' => false,
                'maintain' => false,
                'push' => false,
                'triage' => false,
                'pull' => true,
            ],
        ]),
    ]);

    $this->artisan('feedback-hub:health --live')
        ->expectsOutputToContain('GitHub live check passed for uteq/example')
        ->assertSuccessful();
});
