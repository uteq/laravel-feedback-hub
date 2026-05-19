<?php

use Uteq\FeedbackHub\Support\FeedbackPayloadSanitizer;

it('filters sensitive keys recursively', function (): void {
    $payload = [
        'name' => 'Nathan',
        'csrf_token' => 'secret',
        'hidden_customer_id' => 'secret',
        'nested' => [
            'api_key' => 'secret',
            'visible' => 'ok',
        ],
    ];

    expect(app(FeedbackPayloadSanitizer::class)->sanitize($payload))->toBe([
        'name' => 'Nathan',
        'csrf_token' => '[filtered]',
        'hidden_customer_id' => '[filtered]',
        'nested' => [
            'api_key' => '[filtered]',
            'visible' => 'ok',
        ],
    ]);
});

it('redacts sensitive values inside urls and log strings', function (): void {
    $payload = [
        'url' => 'https://example.test/callback?token=abc123&safe=ok&api_key=secret',
        'message' => 'Request failed with Bearer super-secret-token and password=hunter2',
        'nested' => [
            'request_url' => 'https://example.test/path?csrf_token=csrf&search=visible',
        ],
    ];

    expect(app(FeedbackPayloadSanitizer::class)->sanitize($payload))->toBe([
        'url' => 'https://example.test/callback?token=[filtered]&safe=ok&api_key=[filtered]',
        'message' => 'Request failed with Bearer [filtered] and password=[filtered]',
        'nested' => [
            'request_url' => 'https://example.test/path?csrf_token=[filtered]&search=visible',
        ],
    ]);
});
