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
        ->and($html)->toContain('feedbackHubWidget');
});

it('redacts sensitive url values before the browser submits feedback', function (): void {
    $user = User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);

    $html = $this->actingAs($user)->app['view']->make('feedback-hub::components.floating-button')->render();

    expect($html)
        ->toContain('url: this.currentUrl()')
        ->toContain('url: this.currentUrl(),')
        ->toContain('redactText(value)')
        ->toContain('$1$2=[filtered]')
        ->not->toContain('url: window.location.href');
});
