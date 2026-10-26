<?php

use Illuminate\Http\Client\ConnectionException;
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

it('creates github and linear issues before sending telegram and os inbox', function (): void {
    config()->set('feedback-hub.os_inbox.url', 'https://os.example.test/inbox');
    config()->set('feedback-hub.os_inbox.key', 'os-test-key');
    config()->set('feedback-hub.github.token', 'github-token');
    config()->set('feedback-hub.github.repo', 'uteq/example');
    config()->set('feedback-hub.linear.token', 'linear-token');
    config()->set('feedback-hub.linear.team_id', 'team-id');
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake([
        'os.example.test/inbox' => Http::response([], 201),
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

    Http::assertSentCount(4);
    expect(Http::recorded()->map(fn ($pair) => $pair[0]->url())->all())->toBe([
        'https://api.github.com/repos/uteq/example/issues',
        'https://api.linear.app/graphql',
        'https://api.telegram.org/bottelegram-token/sendMessage',
        'https://os.example.test/inbox',
    ]);
    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://os.example.test/inbox') {
            return false;
        }
        $payload = json_decode(collect($request->data())->firstWhere('name', 'payload')['contents'], true, flags: JSON_THROW_ON_ERROR);

        return $payload['github_issue_url'] === 'https://github.com/uteq/example/issues/12'
            && $payload['linear_issue_url'] === 'https://linear.app/uteq/issue/UTEQ-12/test';
    });
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

it('does not store the telegram bot token from a connection error', function (): void {
    config([
        'feedback-hub.github.enabled' => false,
        'feedback-hub.linear.enabled' => false,
        'feedback-hub.telegram.enabled' => true,
        'feedback-hub.telegram.bot_token' => '123456:ABC-secret_token',
        'feedback-hub.telegram.chat_id' => '42',
    ]);

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: timed out for https://api.telegram.org/bot123456:ABC-secret_token/sendMessage',
    ));

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Bug report',
        'page_url' => 'https://example.test/dashboard',
    ]);

    try {
        app(ProcessFeedbackReportJob::class, ['report' => $report])->handle(
            app(GitHubFeedbackClient::class),
            app(LinearFeedbackClient::class),
            app(TelegramFeedbackClient::class),
            app(IssueBodyBuilder::class),
        );
    } catch (ConnectionException) {
    }

    expect($report->refresh()->last_error)
        ->toContain('/bot[filtered]/sendMessage')
        ->not->toContain('ABC-secret_token');
});

describe('os inbox delivery', function (): void {
    beforeEach(function (): void {
        config([
            'feedback-hub.github.enabled' => false,
            'feedback-hub.linear.enabled' => false,
            'feedback-hub.telegram.enabled' => false,
            'feedback-hub.os_inbox.url' => 'https://os.example.test/inbox',
            'feedback-hub.os_inbox.key' => 'os-test-key',
        ]);
        $this->report = FeedbackReport::query()->create([
            'project' => 'Test Project',
            'type' => 'bug',
            'title' => 'Cannot save',
            'description' => 'Saving fails.',
            'page_url' => 'https://example.test/edit',
            'element_selector' => '#save',
            'reporter_name' => 'Reporter',
            'reporter_email' => 'reporter@example.test',
            'intent' => ['goal' => 'Save', 'expected' => 'Saved', 'actual' => 'Error', 'private' => 'omit'],
            'session_data' => ['userAgent' => 'Test browser', 'viewport' => ['width' => 1280, 'height' => 720], 'locale' => 'nl', 'private' => 'omit'],
            'console_errors' => range(1, 35),
            'network_requests' => range(1, 35),
            'transcript' => [['role' => 'user', 'content' => 'private chat']],
            'form_state' => ['private' => 'form value'],
        ]);
    });

    it('does not send when the url is empty', function (): void {
        config(['feedback-hub.os_inbox.url' => null]);
        Http::fake();
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertNothingSent();
        expect($this->report->refresh()->status)->toBe('synced');
    });

    it('posts only the versioned allowlist as multipart with authentication and timeouts', function (): void {
        Http::fake(function (Request $request, array $options) {
            expect($options['connect_timeout'])->toBe(5)
                ->and($options['timeout'])->toBe(20);

            return Http::response([], 201);
        });
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toBe('https://os.example.test/inbox')
                ->and($request->method())->toBe('POST')
                ->and($request->hasHeader('Authorization', 'Bearer os-test-key'))->toBeTrue()
                ->and($request->hasHeader('Accept', 'application/json'))->toBeTrue()
                ->and($request->isMultipart())->toBeTrue()
                ->and($request->hasFile('screenshot'))->toBeFalse();
            $payload = json_decode(collect($request->data())->firstWhere('name', 'payload')['contents'], true, flags: JSON_THROW_ON_ERROR);
            expect($payload)->toBe([
                'version' => 1,
                'uuid' => $this->report->uuid,
                'reference' => $this->report->reference,
                'project' => 'Test Project',
                'type' => 'bug',
                'title' => 'Cannot save',
                'description' => 'Saving fails.',
                'page_url' => 'https://example.test/edit',
                'element_selector' => '#save',
                'console_errors' => range(6, 35),
                'network_requests' => range(6, 35),
                'browser' => ['userAgent' => 'Test browser', 'viewport' => ['width' => 1280, 'height' => 720], 'locale' => 'nl'],
                'reporter_name' => 'Reporter',
                'reporter_email' => 'reporter@example.test',
                'intent' => ['goal' => 'Save', 'expected' => 'Saved', 'actual' => 'Error'],
                'github_issue_url' => null,
                'linear_issue_url' => null,
                'created_at' => $this->report->created_at->toIso8601String(),
            ]);

            return true;
        });
        expect($this->report->refresh()->status)->toBe('synced');
    });

    it('omits absent browser keys and sends an empty browser as an object', function (?array $session, array $expected): void {
        $this->report->update(['session_data' => $session]);
        Http::fake();
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertSent(function (Request $request) use ($expected): bool {
            $payload = json_decode(collect($request->data())->firstWhere('name', 'payload')['contents'], flags: JSON_THROW_ON_ERROR);
            expect($payload->browser)->toBeInstanceOf(stdClass::class)
                ->and((array) $payload->browser)->toBe($expected);

            return true;
        });
    })->with([[null, []], [['locale' => 'nl', 'private' => 'omit'], ['locale' => 'nl']]]);

    it('attaches the screenshot from the report disk only when present', function (bool $present): void {
        config(['feedback-hub.storage_disk' => 'current']);
        Storage::fake('original');
        Storage::fake('current');
        Storage::disk('current')->put('screenshots/report.png', 'wrong-disk');
        if ($present) {
            Storage::disk('original')->put('screenshots/report.png', 'png-bytes');
        }
        $this->report->update(['screenshot_disk' => 'original', 'screenshot_path' => 'screenshots/report.png']);
        Http::fake();
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertSent(function (Request $request) use ($present): bool {
            expect($request->hasFile('screenshot'))->toBe($present);
            if ($present) {
                expect($request->hasFile('screenshot', 'png-bytes', $this->report->uuid.'.png'))->toBeTrue();
            }

            return true;
        });
    })->with([true, false]);

    it('warns and skips when the key is missing', function (): void {
        config(['feedback-hub.os_inbox.key' => null]);
        Http::fake();
        Log::shouldReceive('warning')->once()->with('os_inbox_not_configured', ['report' => $this->report->getKey()]);
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertNothingSent();
        expect($this->report->refresh()->status)->toBe('synced')
            ->and($this->report->last_error)->toBeNull();
    });

    it('warns without failing or retrying for a client error', function (int $status): void {
        Http::fake(['os.example.test/inbox' => Http::response(['message' => 'Rejected'], $status)]);
        Log::shouldReceive('warning')->once()->with('os_inbox_rejected', ['report' => $this->report->getKey(), 'status' => $status]);
        app()->call([new ProcessFeedbackReportJob($this->report), 'handle']);
        Http::assertSentCount(1);
        expect($this->report->refresh()->status)->toBe('synced')
            ->and($this->report->last_error)->toBeNull();
    })->with([401, 422, 429]);

    it('fails and rethrows server errors for the existing job retries', function (): void {
        Http::fake(['os.example.test/inbox' => Http::response([], 503)]);
        expect(fn () => app()->call([new ProcessFeedbackReportJob($this->report), 'handle']))->toThrow(RequestException::class);
        expect($this->report->refresh()->status)->toBe('failed');
        Http::assertSentCount(1);
    });

    it('fails and rethrows connection errors for the existing job retries', function (): void {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        expect(fn () => app()->call([new ProcessFeedbackReportJob($this->report), 'handle']))->toThrow(ConnectionException::class);
        expect($this->report->refresh()->status)->toBe('failed');
    });
});
