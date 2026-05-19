<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;

class FeedbackHubTelegramDiscoverCommand extends Command
{
    protected $signature = 'feedback-hub:telegram-discover {--limit=10 : Maximum updates to inspect}';

    protected $description = 'List Telegram chat IDs visible to the configured Feedback Hub bot.';

    public function handle(TelegramFeedbackClient $telegram): int
    {
        if (! $telegram->hasBotToken()) {
            $this->components->error('Telegram bot token is not configured.');

            return self::FAILURE;
        }

        $rows = $this->chatRows($telegram->getUpdates((int) $this->option('limit')));

        if ($rows === []) {
            $this->components->warn('No Telegram chats found. Add the bot to the channel, send a message, then run this again.');

            return self::FAILURE;
        }

        $this->table(['chat_id', 'type', 'title'], $rows);
        $this->line('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to the chat_id for the feedback channel.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $updates
     * @return array<int, array{chat_id: string, type: string, title: string}>
     */
    private function chatRows(array $updates): array
    {
        $rows = [];

        foreach ($updates as $update) {
            $message = $update['channel_post'] ?? $update['message'] ?? null;
            $chat = is_array($message) ? ($message['chat'] ?? null) : null;

            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }

            $chatId = (string) $chat['id'];
            $rows[$chatId] = [
                'chat_id' => $chatId,
                'type' => (string) ($chat['type'] ?? ''),
                'title' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''),
            ];
        }

        return array_values($rows);
    }
}
