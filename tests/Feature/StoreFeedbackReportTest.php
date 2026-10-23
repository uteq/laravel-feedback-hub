<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\User;

it('stores feedback quickly and queues external processing', function (): void {
    Queue::fake();
    Storage::fake('local');

    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $startedAt = microtime(true);

    $response = $this->actingAs($user)->postJson(route('feedback-hub.store'), [
        'type' => 'bug',
        'title' => 'Knop werkt niet token=title-secret',
        'description' => 'Bij klikken gebeurt niets. Bearer description-secret',
        'url' => 'https://example.test/dashboard?token=url-secret&safe=ok',
        'element_selector' => 'button.primary',
        'element_rect' => ['x' => 1, 'y' => 2, 'width' => 100, 'height' => 40],
        'screenshot' => 'data:image/png;base64,'.base64_encode('fake-image'),
        'session_data' => ['url' => 'https://example.test/dashboard?api_key=session-secret'],
        'form_state' => [
            'email' => ['type' => 'email', 'filled' => true],
            'api_token' => 'secret',
        ],
    ]);

    $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

    $response->assertCreated()
        ->assertJsonPath('success', true);

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->reference)->toStartWith('FH-')
        ->and($report->reporter_name)->toBe('Nathan Jansen')
        ->and($report->title)->toBe('Knop werkt niet token=[filtered]')
        ->and($report->description)->toBe('Bij klikken gebeurt niets. Bearer [filtered]')
        ->and($report->page_url)->toBe('https://example.test/dashboard?token=[filtered]&safe=ok')
        ->and($report->session_data['url'])->toBe('https://example.test/dashboard?api_key=[filtered]')
        ->and($report->form_state['api_token'])->toBe('[filtered]')
        ->and($report->screenshot_path)->not->toBeNull()
        ->and($elapsedMilliseconds)->toBeLessThan(1000);

    Storage::disk('local')->assertExists($report->screenshot_path);
    Queue::assertPushed(ProcessFeedbackReportJob::class);
});

it('requires authentication for submit', function (): void {
    $this->postJson(route('feedback-hub.store'), [
        'type' => 'bug',
        'title' => 'Test',
        'url' => 'https://example.test',
    ])->assertUnauthorized();
});

it('casts intent and transcript to arrays', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Test',
        'page_url' => 'https://example.test',
        'intent' => ['goal' => 'Factuur downloaden', 'expected' => 'PDF', 'actual' => 'Niets'],
        'transcript' => [['role' => 'user', 'content' => 'Knop doet niks']],
    ]);

    expect($report->fresh()->intent)->toBe(['goal' => 'Factuur downloaden', 'expected' => 'PDF', 'actual' => 'Niets'])
        ->and($report->fresh()->transcript)->toBe([['role' => 'user', 'content' => 'Knop doet niks']]);
});

it('stores intent and transcript from the chat flow, redacted', function (): void {
    Queue::fake();

    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $this->actingAs($user)->postJson(route('feedback-hub.store'), [
        'type' => 'bug',
        'title' => 'Download faalt',
        'url' => 'https://example.test/facturen',
        'intent' => [
            'goal' => 'Factuur downloaden token=goal-secret',
            'expected' => 'Een PDF',
            'actual' => 'Niets',
        ],
        'transcript' => [
            ['role' => 'assistant', 'content' => 'Waar liep je tegenaan?'],
            ['role' => 'user', 'content' => 'Knop doet niks, Bearer chat-secret'],
        ],
    ])->assertCreated();

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->intent)->toBe(['goal' => 'Factuur downloaden token=[filtered]', 'expected' => 'Een PDF', 'actual' => 'Niets'])
        ->and($report->transcript)->toBe([
            ['role' => 'assistant', 'content' => 'Waar liep je tegenaan?'],
            ['role' => 'user', 'content' => 'Knop doet niks, Bearer [filtered]'],
        ]);
});

it('validates intent and transcript shape', function (): void {
    Queue::fake();

    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $this->actingAs($user)->postJson(route('feedback-hub.store'), [
        'type' => 'bug',
        'title' => 'Test',
        'url' => 'https://example.test',
        'intent' => ['goal' => str_repeat('x', 2001)],
        'transcript' => [['role' => 'system', 'content' => 'nee']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['intent.goal', 'transcript.0.role']);
});

it('keeps the form flow working without intent or transcript', function (): void {
    Queue::fake();

    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $this->actingAs($user)->postJson(route('feedback-hub.store'), [
        'type' => 'suggestion',
        'title' => 'Alleen formulier',
        'url' => 'https://example.test',
    ])->assertCreated();

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->intent)->toBeNull()->and($report->transcript)->toBeNull();
});

it('stores null intent and transcript when the form sends empty values', function (): void {
    Queue::fake();

    $user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);

    $this->actingAs($user)->postJson(route('feedback-hub.store'), [
        'type' => 'bug',
        'title' => 'Leeg formulier',
        'url' => 'https://example.test',
        'intent' => ['goal' => '', 'expected' => '', 'actual' => ''],
        'transcript' => [],
    ])->assertCreated();

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->intent)->toBeNull()->and($report->transcript)->toBeNull();
});
