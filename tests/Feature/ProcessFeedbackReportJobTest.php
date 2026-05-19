<?php

use Illuminate\Support\Facades\Http;
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
        'page_url' => 'https://example.test',
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
