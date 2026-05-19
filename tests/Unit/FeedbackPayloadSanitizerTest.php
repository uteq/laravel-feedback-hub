<?php

use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;

it('filters sensitive keys recursively', function (): void {
    $payload = [
        'name' => 'Nathan',
        'csrf_token' => 'secret',
        'nested' => [
            'api_key' => 'secret',
            'visible' => 'ok',
        ],
    ];

    expect(app(FeedbackPayloadSanitizer::class)->sanitize($payload))->toBe([
        'name' => 'Nathan',
        'csrf_token' => '[filtered]',
        'nested' => [
            'api_key' => '[filtered]',
            'visible' => 'ok',
        ],
    ]);
});
