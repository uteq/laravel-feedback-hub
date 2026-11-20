<?php

use Illuminate\Support\Facades\Queue;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;
use Uteq\FeedbackHub\Tests\Support\User;

beforeEach(function (): void {
    Queue::fake();
    $this->actingAs(User::query()->create(['name' => 'Tester', 'email' => 'activity@example.test']));
});

function activityPayload(array $overrides = []): array
{
    return array_replace([
        'type' => 'bug', 'title' => 'Opname', 'url' => 'https://example.test/page',
        'activity' => [['t' => 0.5, 'type' => 'click', 'label' => 'token=secret', 'selector' => '#save', 'path' => '/page?private=value#fragment']],
        'recording_seconds' => 1.2,
    ], $overrides);
}

it('stores sanitized activity separately from session context', function (): void {
    $this->postJson(route('feedback-hub.store'), activityPayload())->assertCreated();
    $report = FeedbackReport::query()->firstOrFail();
    expect($report->activity)->toBe([['t' => 0.5, 'type' => 'click', 'label' => 'token=[filtered]', 'selector' => '#save', 'path' => '/page']])
        ->and($report->recording_seconds)->toBe(1.2)
        ->and($report->session_data)->toBeNull();
    expect(app(IssueBodyBuilder::class)->build($report))->not->toContain('#save');
});

it('rejects unbounded or unknown activity data', function (string $field, mixed $value): void {
    $payload = activityPayload();
    data_set($payload, $field, $value);
    $this->postJson(route('feedback-hub.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['activity', array_fill(0, 501, ['t' => 0, 'type' => 'click', 'label' => '', 'selector' => '', 'path' => '/'])],
    ['activity.0.label', str_repeat('a', 61)],
    ['activity.0.selector', str_repeat('a', 201)],
    ['activity.0.path', str_repeat('a', 501)],
    ['activity.0.t', 'no'],
    ['activity.0.type', 'input'],
    ['activity.0', ['t' => 0, 'type' => 'click', 'label' => '', 'selector' => '', 'path' => '/', 'value' => 'private']],
]);

it('still requires a title without any new attributes', function (): void {
    $this->postJson(route('feedback-hub.store'), ['type' => 'bug', 'url' => 'https://example.test'])->assertJsonValidationErrors('title');
});
