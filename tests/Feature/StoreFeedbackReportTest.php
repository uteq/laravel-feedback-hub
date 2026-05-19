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
