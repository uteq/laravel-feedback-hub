<?php

use Illuminate\Support\Facades\Blade;
use Uteq\FeedbackHub\Tests\Support\User;

it('does not render the widget for guests', function (): void {
    expect(Blade::render('<x-feedback-hub::floating-button />'))->not->toContain('data-feedback-hub-widget');
});

it('renders the widget for authenticated users', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $html = $this->actingAs($user)->app['view']->make('feedback-hub::components.floating-button')->render();

    expect($html)->toContain('data-feedback-hub-widget')
        ->and($html)->toContain('feedbackHubWidget')
        ->and($html)->toContain('.fh-modal { display: flex; flex-direction: column;')
        ->and($html)->toContain('.fh-body { flex: 1 1 auto; min-height: 0; overflow-y: auto;')
        ->and($html)->toContain('x-on:feedback-hub-screenshot-captured.window="handleScreenshot($event.detail)"');
});

it('redacts sensitive url values before the browser submits feedback', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $html = $this->actingAs($user)->app['view']->make('feedback-hub::components.floating-button')->render();

    expect($html)
        ->toContain('url: this.currentUrl()')
        ->toContain('const payload = this.redactPayload({')
        ->toContain('body: JSON.stringify(payload)')
        ->toContain('redactPayload(value, key = null)')
        ->toContain('redactText(value)')
        ->toContain('isSensitiveKey(key)')
        ->toContain("'hidden'")
        ->toContain('$1$2=[filtered]')
        ->not->toContain('url: window.location.href');
});
