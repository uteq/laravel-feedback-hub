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
        'api.telegram.org/bottelegram-token/getMe' => Http::response([
            'ok' => true,
            'result' => ['username' => 'feedback_bot'],
        ]),
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
                    'my_chat_member' => [
                        'chat' => [
                            'id' => -100456,
                            'type' => 'channel',
                            'title' => 'Feedback Membership',
                        ],
                    ],
                ],
                [
                    'update_id' => 3,
                    'message' => [
                        'chat' => [
                            'id' => 42,
                            'type' => 'private',
                            'first_name' => 'Nathan',
                        ],
                    ],
                ],
                [
                    'update_id' => 4,
                    'message' => [
                        'chat' => [
                            'id' => 42,
                            'type' => 'private',
                            'first_name' => 'Nathan',
                        ],
                        'forward_origin' => [
                            'type' => 'channel',
                            'chat' => [
                                'id' => -100789,
                                'type' => 'channel',
                                'title' => 'Forwarded Feedback Channel',
                            ],
                        ],
                    ],
                ],
                [
                    'update_id' => 5,
                    'message' => [
                        'chat' => [
                            'id' => 42,
                            'type' => 'private',
                            'first_name' => 'Nathan',
                        ],
                        'forward_from_chat' => [
                            'id' => -100987,
                            'type' => 'channel',
                            'title' => 'Legacy Forwarded Channel',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-discover')
        ->expectsOutputToContain('Bot: @feedback_bot')
        ->expectsTable(['chat_id', 'type', 'title'], [
            ['-100123', 'channel', 'Feedback Hub'],
            ['-100456', 'channel', 'Feedback Membership'],
            ['42', 'private', 'Nathan'],
            ['-100789', 'channel', 'Forwarded Feedback Channel'],
            ['-100987', 'channel', 'Legacy Forwarded Channel'],
        ])
        ->expectsOutputToContain('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to the chat_id for the feedback channel.')
        ->assertSuccessful();

    Http::assertSentCount(2);
});

it('shows the bot username when no telegram chats are visible yet', function (): void {
    config()->set('feedback-hub.telegram.bot_token', 'telegram-token');

    Http::fake([
        'api.telegram.org/bottelegram-token/getMe' => Http::response([
            'ok' => true,
            'result' => ['username' => 'feedback_bot'],
        ]),
        'api.telegram.org/bottelegram-token/getUpdates*' => Http::response([
            'ok' => true,
            'result' => [],
        ]),
    ]);

    $this->artisan('feedback-hub:telegram-discover')
        ->expectsOutputToContain('Bot: @feedback_bot')
        ->expectsOutputToContain('No Telegram chats found. Add the bot to the channel, send a message, then run this again.')
        ->assertFailed();

    Http::assertSentCount(2);
});
