<?php

use Illuminate\Support\Facades\Http;

it('fails telegram test when telegram is not configured', function (): void {
    $this->artisan('feedback-hub:telegram-test')
        ->expectsOutputToContain('Telegram is not configured.')
        ->assertFailed();
});

it('sends a telegram test message', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');
    config()->set('feedback-hub.telegram.chat_id', '-100123');

    Http::fake([
        'api.telegram.org/bottelegram-token/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 123],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-test "Controlebericht"')
        ->expectsOutputToContain('Telegram message sent: 123')
        ->assertSuccessful();

    Http::assertSentCount(1);
});
