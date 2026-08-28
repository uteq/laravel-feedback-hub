<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Clients\GitHubFeedbackClient;
use Uteq\FeedbackHub\Clients\LinearFeedbackClient;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;

it('creates github and linear issues before sending telegram', function (): void {
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
                        'id' => 'lin-id',
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

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    $report->refresh();

    expect($report->status)->toBe('synced')
        ->and($report->github_issue_number)->toBe(12)
        ->and($report->linear_issue_identifier)->toBe('UTEQ-12')
        ->and($report->telegram_message_id)->toBe('44');

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.linear.app/graphql')
        && $request->hasHeader('Authorization', 'linear-token'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org/bottelegram-token/sendMessage')
        && $request['parse_mode'] === 'HTML'
        && str_contains((string) $request['text'], '<b>Nieuwe feedback</b>')
        && str_contains((string) $request['text'], 'Pagina: /')
        && ! str_contains((string) $request['text'], 'https://github.com/uteq/example/issues/12')
        && str_contains((string) $request['reply_markup'], 'GitHub #12')
        && str_contains((string) $request['reply_markup'], 'UTEQ-12'));
});

it('does not create duplicate external issues on retry', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake();

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test',
        'github_issue_url' => 'https://github.com/uteq/example/issues/12',
        'github_issue_number' => 12,
        'linear_issue_id' => 'lin-id',
        'linear_issue_identifier' => 'UTEQ-12',
        'linear_issue_url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
        'telegram_message_id' => '44',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    Http::assertNothingSent();
    expect($report->refresh()->status)->toBe('synced');
});

it('sends stored screenshot as telegram photo', function (): void {
    config()->set('feedback-hub.github.enabled', false);
    config()->set('feedback-hub.linear.enabled', false);
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');
    config()->set('feedback-hub.storage_disk', 'local');

    Storage::fake('local');
    Storage::disk('local')->put('feedback-hub/screenshots/report.png', 'png-bytes');

    Http::fake([
        'api.telegram.org/bottelegram-token/sendPhoto' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 45],
        ]),
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
        'screenshot_disk' => 'local',
        'screenshot_path' => 'feedback-hub/screenshots/report.png',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    expect($report->refresh()->telegram_message_id)->toBe('45')
        ->and($report->status)->toBe('synced');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org/bottelegram-token/sendPhoto'));
});

it('uploads stored screenshot to linear and embeds it in the issue description', function (): void {
    config()->set('feedback-hub.github.enabled', false);
    config()->set('feedback-hub.telegram.enabled', false);
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');

    Storage::fake('local');
    Storage::disk('local')->put('feedback-hub/screenshots/report.png', 'png-bytes');

    Http::fake([
        'api.linear.app/graphql' => Http::sequence()
            ->push([
                'data' => [
                    'fileUpload' => [
                        'success' => true,
                        'uploadFile' => [
                            'uploadUrl' => 'https://uploads.linear.app/upload-target',
                            'assetUrl' => 'https://uploads.linear.app/asset.png',
                            'headers' => [
                                ['key' => 'Cache-Control', 'value' => 'public, max-age=31536000'],
                                ['key' => 'x-upload-token', 'value' => 'signed-token'],
                            ],
                        ],
                    ],
                ],
            ], 200)
            ->push([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'lin-id',
                            'identifier' => 'UTEQ-12',
                            'url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
                        ],
                    ],
                ],
            ], 200),
        'uploads.linear.app/*' => Http::response('', 200),
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
        'screenshot_disk' => 'local',
        'screenshot_path' => 'feedback-hub/screenshots/report.png',
        'screenshot_mime' => 'image/png',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    expect($report->refresh()->linear_issue_identifier)->toBe('UTEQ-12')
        ->and($report->status)->toBe('synced');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://uploads.linear.app/upload-target'
        && $request->method() === 'PUT'
        && $request->hasHeader('x-upload-token', 'signed-token'));
    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.linear.app/graphql') {
            return false;
        }

        $payload = $request->data();

        return str_contains((string) $payload['query'], 'issueCreate')
            && str_contains((string) data_get($payload, 'variables.input.description'), '![Screenshot](https://uploads.linear.app/asset.png)');
    });
});

it('resumes after a partial external failure without duplicating github', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test',
    ]);

    $githubCalls = 0;
    $linearCalls = 0;
    $telegramCalls = 0;

    Http::fake(function ($request) use (&$githubCalls, &$linearCalls, &$telegramCalls) {
        if (str_contains($request->url(), 'api.github.com/repos/uteq/example/issues')) {
            $githubCalls++;

            return Http::response([
                'html_url' => 'https://github.com/uteq/example/issues/12',
                'number' => 12,
            ], 201);
        }

        if (str_contains($request->url(), 'api.linear.app/graphql')) {
            $linearCalls++;

            if ($linearCalls === 1) {
                return Http::response(['errors' => [['message' => 'temporary failure']]], 200);
            }

            return Http::response([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'lin-id',
                            'identifier' => 'UTEQ-12',
                            'url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
                        ],
                    ],
                ],
            ]);
        }

        if (str_contains($request->url(), 'api.telegram.org/bottelegram-token/sendMessage')) {
            $telegramCalls++;

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => 44],
            ]);
        }

        return Http::response([], 404);
    });

    $exception = null;

    try {
        app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
            app(GitHubFeedbackClient::class),
            app(LinearFeedbackClient::class),
            app(TelegramFeedbackClient::class),
            app(IssueBodyBuilder::class),
        );
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    expect($exception)->toBeInstanceOf(Throwable::class)
        ->and($exception?->getMessage())->toContain('temporary failure');

    $report->refresh();

    expect($report->github_issue_number)->toBe(12)
        ->and($report->linear_issue_id)->toBeNull()
        ->and($report->telegram_message_id)->toBeNull()
        ->and($report->status)->toBe('failed');

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    $report->refresh();

    expect($report->github_issue_number)->toBe(12)
        ->and($report->linear_issue_identifier)->toBe('UTEQ-12')
        ->and($report->telegram_message_id)->toBe('44')
        ->and($report->status)->toBe('synced');

    expect($githubCalls)->toBe(1)
        ->and($linearCalls)->toBe(2)
        ->and($telegramCalls)->toBe(1);
});

it('uses one unique queue lock per report', function (): void {
    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test',
    ]);

    $job = new ProcessFeedbackReportJob($report);

    expect($job->uniqueId())->toBe('feedback-hub-report:'.$report->getKey())
        ->and($job->uniqueFor)->toBe(3600);
});

it('syncs and warns when telegram is enabled without a token', function (): void {
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.enabled', false);
    config()->set('feedback-hub.telegram.enabled', true);
    config()->set('feedback-hub.telegram.bot_token', null);
    config()->set('feedback-hub.telegram.chat_id', null);

    Http::fake([
        'api.github.com/repos/uteq/example/issues' => Http::response([
            'html_url' => 'https://github.com/uteq/example/issues/12',
            'number' => 12,
        ], 201),
    ]);

    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'telegram_not_configured')
    );

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    $report->refresh();

    expect($report->status)->toBe('synced')
        ->and($report->last_error)->toBeNull()
        ->and($report->github_issue_number)->toBe(12)
        ->and($report->telegram_message_id)->toBeNull();
});

it('still fails on a real telegram api error', function (): void {
    config()->set('feedback-hub.github.enabled', false);
    config()->set('feedback-hub.linear.enabled', false);
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake([
        'api.telegram.org/bottelegram-token/sendMessage' => Http::response([
            'ok' => false,
            'description' => 'Unauthorized',
        ], 401),
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
    ]);

    expect(fn () => app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    ))->toThrow(RequestException::class);

    expect($report->refresh()->status)->toBe('failed')
        ->and($report->last_error)->not->toBeNull();
});

it('keeps failing the report when github is not configured', function (): void {
    config()->set('feedback-hub.github.enabled', true);
    config()->set('feedback-hub.github.token', null);
    config()->set('feedback-hub.linear.enabled', false);
    config()->set('feedback-hub.telegram.enabled', false);

    Http::fake();

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
    ]);

    app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
        app(GitHubFeedbackClient::class),
        app(LinearFeedbackClient::class),
        app(TelegramFeedbackClient::class),
        app(IssueBodyBuilder::class),
    );

    expect($report->refresh()->status)->toBe('failed')
        ->and($report->last_error)->toBe('github_not_configured');

    Http::assertNothingSent();
});
