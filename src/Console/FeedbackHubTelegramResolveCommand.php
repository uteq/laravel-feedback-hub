<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;

class FeedbackHubTelegramResolveCommand extends Command
{
    protected $signature = 'feedback-hub:telegram-resolve {chat : Public @channel username or numeric chat ID}';

    protected $description = 'Resolve a Telegram channel username or chat ID for Feedback Hub configuration.';

    public function handle(TelegramFeedbackClient $telegram): int
    {
        if (! $telegram->hasBotToken()) {
            $this->components->error('Telegram bot token is not configured.');

            return self::FAILURE;
        }

        $chat = trim((string) $this->argument('chat'));
        $result = $telegram->resolveChat($chat);

        if (! ($result['ok'] ?? false)) {
            $this->components->error('Telegram chat could not be resolved. For private channels, add the bot as admin and use telegram-discover.');

            return self::FAILURE;
        }

        $this->table(['chat_id', 'type', 'title'], [[
            $result['id'] ?? $chat,
            $result['type'] ?? '',
            $result['title'] ?? '',
        ]]);

        $this->line('Set FEEDBACK_HUB_TELEGRAM_CHAT_ID to '.($result['id'] ?? $chat).'.');

        return self::SUCCESS;
    }
}
