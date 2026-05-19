<?php

use Illuminate\Support\Facades\Http;

it('fails telegram discover when bot token is not configured', function (): void {
    $this->artisan('feedback-hub:telegram-discover')
        ->expectsOutputToContain('Telegram bot token is not configured.')
        ->assertFailed();
});

it('lists telegram chat ids visible to the bot', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    Http::fake([
        'api.telegram.org/bottelegram-token/getUpdates*' => Http::response([
            'ok' => true,
            'result' => [
                [
                    'update_id' => 1,
                    'channel_post' => [
                        'chat' => [
                            'id' => -100123,
                            'type' => 'channel',
                            'title' => 'Feedback Hub',
                        ],
                    ],
                ],
                [
                    'update_id' => 2,
                    'message' => [
                        'chat' => [
                            'id' => 42,
                            'type' => 'private',
                            'first_name' => 'Nathan',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-discover')
        ->expectsTable(['chat_id', 'type', 'title'], [
            ['-100123', 'channel', 'Feedback Hub'],
            ['42', 'private', 'Nathan'],
        ])
        ->expectsOutputToContain('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to the chat_id for the feedback channel.')
        ->assertSuccessful();

    Http::assertSentCount(1);
});
