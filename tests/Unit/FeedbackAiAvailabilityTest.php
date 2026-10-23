<?php

use Uteq\FeedbackHub\Support\FeedbackAiAvailability;

beforeEach(function (): void {
    config()->set('feedback-hub.ai.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');
});

it('is available when the module, the ai flag and the provider key are set', function (): void {
    expect(FeedbackAiAvailability::available())->toBeTrue();
});

it('is unavailable when the ai flag is off', function (): void {
    config()->set('feedback-hub.ai.enabled', false);

    expect(FeedbackAiAvailability::available())->toBeFalse();
});

it('is unavailable when the provider key is missing', function (): void {
    config()->set('ai.providers.anthropic.key', null);

    expect(FeedbackAiAvailability::available())->toBeFalse();
});

it('is unavailable when the module is disabled', function (): void {
    config()->set('feedback-hub.enabled', false);

    expect(FeedbackAiAvailability::available())->toBeFalse();
});

it('follows the configured provider name', function (): void {
    config()->set('feedback-hub.ai.provider', 'openai');
    config()->set('ai.providers.openai.key', null);

    expect(FeedbackAiAvailability::available())->toBeFalse();
});

it('is unavailable when laravel ai is not installed', function (): void {
    $availability = new class extends FeedbackAiAvailability
    {
        protected static function aiInstalled(): bool
        {
            return false;
        }
    };

    expect($availability::available())->toBeFalse();
});
