<?php

namespace Uteq\FeedbackHub\Clients;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class TelegramFeedbackClient
{
    public function isConfigured(): bool
    {
        return $this->hasBotToken()
            && filled(config('feedback-hub.telegram.chat_id'));
    }

    public function hasBotToken(): bool
    {
        return filled(config('feedback-hub.telegram.bot_token'));
    }

    /**
     * @return array{message_id: string}
     *
     * @throws RequestException
     */
    public function sendMessage(string $text): array
    {
        $response = Http::asForm()
            ->post($this->endpoint('sendMessage'), [
                'chat_id' => (string) config('feedback-hub.telegram.chat_id'),
                'text' => $text,
                'disable_web_page_preview' => true,
            ])
            ->throw();

        if (! $response->json('ok')) {
            throw new RequestException($response);
        }

        return [
            'message_id' => (string) $response->json('result.message_id'),
        ];
    }

    /**
     * @return array{ok: bool, username?: string}
     */
    public function getMe(): array
    {
        $response = Http::get($this->endpoint('getMe'));

        if (! $response->successful() || ! $response->json('ok')) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'username' => (string) $response->json('result.username'),
        ];
    }

    /**
     * @return array{ok: bool, title?: string}
     */
    public function getChat(): array
    {
        return $this->resolveChat((string) config('feedback-hub.telegram.chat_id'));
    }

    /**
     * @return array{ok: bool, id?: string, title?: string, type?: string}
     */
    public function resolveChat(string $chatId): array
    {
        $response = Http::get($this->endpoint('getChat'), [
            'chat_id' => $chatId,
        ]);

        if (! $response->successful() || ! $response->json('ok')) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'id' => (string) $response->json('result.id'),
            'title' => (string) ($response->json('result.title') ?: $response->json('result.username')),
            'type' => (string) $response->json('result.type'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $limit = 10): array
    {
        $response = Http::get($this->endpoint('getUpdates'), [
            'limit' => $limit,
            'allowed_updates' => json_encode(['message', 'channel_post', 'my_chat_member', 'chat_member']),
        ])->throw();

        if (! $response->json('ok')) {
            throw new RequestException($response);
        }

        return $response->json('result', []);
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('feedback-hub.telegram.bot_token').'/'.$method;
    }
}
