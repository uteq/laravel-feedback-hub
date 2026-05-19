<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;
use Uteq\FeedbackHub\Clients\TelegramFeedbackClient;

class FeedbackHubTelegramTestCommand extends Command
{
    protected $signature = 'feedback-hub:telegram-test {message=Feedback Hub testbericht}';

    protected $description = 'Send a test message to the configured Feedback Hub Telegram channel.';

    public function handle(TelegramFeedbackClient $telegram): int
    {
        if (! $telegram->isConfigured()) {
            $this->components->error('Telegram is not configured.');

            return self::FAILURE;
        }

        $result = $telegram->sendMessage((string) $this->argument('message'));

        $this->components->info('Telegram message sent: '.$result['message_id']);

        return self::SUCCESS;
    }
}
