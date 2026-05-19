<?php

namespace Uteq\FeedbackHub\Console;

use Illuminate\Console\Command;

class FeedbackHubInstallCommand extends Command
{
    protected $signature = 'feedback-hub:install';

    protected $description = 'Publish Feedback Hub config, migrations and frontend asset.';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'feedback-hub-config',
            '--force' => false,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'feedback-hub-assets',
            '--force' => false,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'feedback-hub-migrations',
            '--force' => false,
        ]);

        $this->components->info('Feedback Hub files published.');
        $this->line('Add this to your Vite app entry:');
        $this->line("import './vendor/feedback-hub/feedback-inspector';");
        $this->line('Add <x-feedback-hub::floating-button /> to your authenticated layout.');

        return self::SUCCESS;
    }
}
