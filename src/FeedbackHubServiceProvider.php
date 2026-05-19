<?php

namespace Uteq\FeedbackHub;

use Illuminate\Support\ServiceProvider;
use Uteq\FeedbackHub\Console\FeedbackHubHealthCommand;
use Uteq\FeedbackHub\Console\FeedbackHubInstallCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTelegramDiscoverCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTelegramTestCommand;

class FeedbackHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/feedback-hub.php', 'feedback-hub');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'feedback-hub');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/feedback-hub.php' => config_path('feedback-hub.php'),
        ], 'feedback-hub-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_05_19_000000_create_feedback_hub_reports_table.php' => database_path('migrations/2026_05_19_000000_create_feedback_hub_reports_table.php'),
        ], 'feedback-hub-migrations');

        $this->publishes([
            __DIR__.'/../resources/js/feedback-inspector.js' => resource_path('js/vendor/feedback-hub/feedback-inspector.js'),
        ], 'feedback-hub-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FeedbackHubHealthCommand::class,
                FeedbackHubInstallCommand::class,
                FeedbackHubTelegramDiscoverCommand::class,
                FeedbackHubTelegramTestCommand::class,
            ]);
        }
    }
}
