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

function renderFloatingButtonFor(User $user, string $template = '<x-feedback-hub::floating-button />'): string
{
    test()->actingAs($user);

    return Blade::render($template);
}

function widgetUser(): User
{
    return User::query()->create([
        'name' => 'Nathan Jansen',
        'email' => 'nathan@example.test',
    ]);
}

it('offers the intake chat when the AI provider is available', function (): void {
    config()->set('feedback-hub.ai.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    $html = renderFloatingButtonFor(widgetUser());

    expect($html)
        ->toContain("chatEndpoint: '".route('feedback-hub.chat')."'")
        ->toContain("csrfEndpoint: '".route('feedback-hub.csrf')."'")
        ->toContain('chatEnabled: true')
        ->toContain('id="feedback-hub-chat-input"')
        ->toContain('x-on:submit.prevent="sendTurn"')
        ->toContain('id="feedback-hub-goal"')
        ->toContain('id="feedback-hub-expected"')
        ->toContain('id="feedback-hub-actual"')
        ->toContain('intent: this.intent')
        ->toContain('transcript: this.chatMessages')
        ->toContain('role="alert"');
});

it('falls back to the plain form when the AI provider is unavailable', function (): void {
    config()->set('feedback-hub.ai.enabled', false);

    $html = renderFloatingButtonFor(widgetUser());

    expect($html)
        ->toContain('chatEnabled: false')
        ->toContain('id="feedback-hub-title"')
        ->toContain('id="feedback-hub-description"')
        ->toContain('x-on:click="submit"');
});

it('includes the shared widget script once when the component renders twice', function (): void {
    $html = renderFloatingButtonFor(widgetUser(), '<x-feedback-hub::floating-button /><x-feedback-hub::floating-button :floating="false" />');

    expect(substr_count($html, 'window.FeedbackHubWidget = (config) =>'))->toBe(1)
        ->and(substr_count($html, "Alpine.data('feedbackHubWidget'"))->toBe(1)
        ->and(substr_count($html, 'data-feedback-hub-widget'))->toBe(2)
        ->and(substr_count($html, 'class="fh-button"'))->toBe(1);
});

it('exposes the shared widget script as a standalone view', function (): void {
    $html = view('feedback-hub::partials.widget-script')->render();

    expect($html)
        ->toContain('<script>')
        ->toContain('window.FeedbackHubWidget = (config) =>')
        ->toContain('openModal()')
        ->toContain('closeModal()')
        ->toContain('notify(heading, text, variant)')
        ->toContain('async refreshCsrfToken()')
        ->not->toContain('Alpine.data(');
});
