<?php

use Illuminate\Support\Facades\Http;

it('fails telegram resolve when bot token is not configured', function (): void {
    $this->artisan('feedback-hub:telegram-resolve @feedback')
        ->expectsOutputToContain('Telegram bot token is not configured.')
        ->assertFailed();
});

it('fails telegram resolve when chat is missing', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    $this->artisan('feedback-hub:telegram-resolve')
        ->expectsOutputToContain('Telegram chat is required. Use a public @channel username or --chat=-100123.')
        ->assertFailed();
});

it('resolves a public telegram channel username', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    Http::fake([
        'api.telegram.org/bottelegram-token/getChat*' => Http::response([
            'ok' => true,
            'result' => [
                'id' => -100123,
                'type' => 'channel',
                'title' => 'Feedback Hub',
            ],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-resolve @feedback')
        ->expectsTable(['chat_id', 'type', 'title'], [
            ['-100123', 'channel', 'Feedback Hub'],
        ])
        ->expectsOutputToContain('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to -100123.')
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.telegram.org/bottelegram-token/getChat')
            && $request['chat_id'] === '@feedback';
    });
});

it('resolves a numeric telegram chat id through an option', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    Http::fake([
        'api.telegram.org/bottelegram-token/getChat*' => Http::response([
            'ok' => true,
            'result' => [
                'id' => -100123,
                'type' => 'channel',
                'title' => 'Feedback Hub',
            ],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-resolve --chat=-100123')
        ->expectsTable(['chat_id', 'type', 'title'], [
            ['-100123', 'channel', 'Feedback Hub'],
        ])
        ->expectsOutputToContain('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to -100123.')
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.telegram.org/bottelegram-token/getChat')
            && $request['chat_id'] === '-100123';
    });
});

it('fails telegram resolve when the chat cannot be resolved', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    Http::fake([
        'api.telegram.org/bottelegram-token/getChat*' => Http::response([
            'ok' => false,
            'description' => 'Bad Request: chat not found',
        ], 400),
    ]);

    $this->artisan('feedback-hub:telegram-resolve @missing')
        ->expectsOutputToContain('Telegram chat could not be resolved. For private channels, add the bot as admin and use telegram-discover.')
        ->assertFailed();
});
