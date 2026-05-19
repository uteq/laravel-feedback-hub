<?php

use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\User;

it('renders the admin feedback report index', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Knop werkt niet',
        'page_url' => 'https://example.test/dashboard',
        'reporter_name' => 'Maria Visser',
    ]);

    $this->actingAs($user)
        ->get(route('feedback-hub.index'))
        ->assertOk()
        ->assertSee($report->reference)
        ->assertSee('Knop werkt niet')
        ->assertSee('Maria Visser')
        ->assertSee(route('feedback-hub.show', $report), escape: false);
});

it('renders the admin feedback report detail', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'suggestion',
        'title' => 'Maak de knop duidelijker',
        'description' => 'De knop valt niet genoeg op.',
        'page_url' => 'https://example.test/dashboard?token=abc123',
        'element_selector' => 'button.primary',
        'element_rect' => ['x' => 10, 'y' => 20, 'width' => 120, 'height' => 40],
        'session_data' => ['url' => 'https://example.test/dashboard?token=[filtered]'],
        'reporter_name' => 'Maria Visser',
        'github_issue_url' => 'https://github.com/uteq/example/issues/12',
        'github_issue_number' => 12,
        'linear_issue_url' => 'https://linear.app/uteq/issue/UTEQ-12/test',
        'linear_issue_identifier' => 'UTEQ-12',
        'telegram_message_id' => '44',
    ]);

    $this->actingAs($user)
        ->get(route('feedback-hub.show', $report))
        ->assertOk()
        ->assertSee($report->reference)
        ->assertSee('Maak de knop duidelijker')
        ->assertSee('button.primary')
        ->assertSee('#12')
        ->assertSee('UTEQ-12')
        ->assertSee('44');
});

it('streams stored feedback screenshots from the configured disk', function (): void {
    Storage::fake('local');

    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    Storage::disk('local')->put('feedback-hub/screenshots/report.png', 'png-bytes');

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Screenshot test',
        'page_url' => 'https://example.test/dashboard',
        'screenshot_disk' => 'local',
        'screenshot_path' => 'feedback-hub/screenshots/report.png',
        'screenshot_mime' => 'image/png',
    ]);

    $response = $this->actingAs($user)
        ->get(route('feedback-hub.screenshot', $report))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    expect($response->streamedContent())->toBe('png-bytes');
});

it('returns not found for reports without a screenshot', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $report = FeedbackReport::query()->create([
        'project' => 'Test Project',
        'type' => 'bug',
        'title' => 'Geen screenshot',
        'page_url' => 'https://example.test/dashboard',
    ]);

    $this->actingAs($user)
        ->get(route('feedback-hub.screenshot', $report))
        ->assertNotFound();
});
