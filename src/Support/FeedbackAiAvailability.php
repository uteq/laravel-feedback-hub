<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Support;

use Laravel\Ai\Contracts\Agent;

class FeedbackAiAvailability
{
    /**
     * Chat is only offered when the module and the AI flag are on and the
     * configured laravel/ai provider actually has a key. Without a key the
     * widget silently falls back to the classic form.
     */
    public static function available(): bool
    {
        if (! static::aiInstalled() || ! config('feedback-hub.enabled') || ! config('feedback-hub.ai.enabled')) {
            return false;
        }

        $provider = static::provider();

        return filled(config("ai.providers.{$provider}.key"));
    }

    public static function provider(): string
    {
        $provider = (string) config('feedback-hub.ai.provider');
        $key = config('feedback-hub.ai.key');

        if (blank($key)) {
            return $provider;
        }

        $config = config("ai.providers.{$provider}", ['driver' => $provider]);
        $config['key'] = $key;
        config()->set('ai.providers.feedback-hub', $config);

        return 'feedback-hub';
    }

    protected static function aiInstalled(): bool
    {
        return interface_exists(Agent::class);
    }
}
