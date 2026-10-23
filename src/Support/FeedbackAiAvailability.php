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

        $provider = (string) config('feedback-hub.ai.provider');

        return filled(config("ai.providers.{$provider}.key"));
    }

    protected static function aiInstalled(): bool
    {
        return interface_exists(Agent::class);
    }
}
