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
        'title' => 'Knop werkt niet',
        'description' => 'Bij klikken gebeurt niets.',
        'url' => 'https://example.test/dashboard',
        'element_selector' => 'button.primary',
        'element_rect' => ['x' => 1, 'y' => 2, 'width' => 100, 'height' => 40],
        'screenshot' => 'data:image/png;base64,'.base64_encode('fake-image'),
        'session_data' => ['url' => 'https://example.test/dashboard'],
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
