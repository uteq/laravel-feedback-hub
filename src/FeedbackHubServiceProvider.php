<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub;

use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Tenancy;
use Uteq\FeedbackHub\Console\FeedbackHubHealthCommand;
use Uteq\FeedbackHub\Console\FeedbackHubInstallCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTelegramDiscoverCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTelegramResolveCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTelegramTestCommand;
use Uteq\FeedbackHub\Console\FeedbackHubTestDeliveryCommand;

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
        $this->registerMigrations();

        $this->publishes([
            __DIR__.'/../config/feedback-hub.php' => config_path('feedback-hub.php'),
        ], 'feedback-hub-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_05_19_000000_create_feedback_hub_reports_table.php' => database_path((! $this->usesTenantDatabases() ? 'migrations/' : 'migrations/tenant/').'2026_05_19_000000_create_feedback_hub_reports_table.php'),
            __DIR__.'/../database/migrations/2026_09_15_000000_add_intent_to_feedback_hub_reports_table.php' => database_path((! $this->usesTenantDatabases() ? 'migrations/' : 'migrations/tenant/').'2026_09_15_000000_add_intent_to_feedback_hub_reports_table.php'),
        ], 'feedback-hub-migrations');

        $this->publishes([
            __DIR__.'/../resources/js/feedback-inspector.js' => resource_path('js/vendor/feedback-hub/feedback-inspector.js'),
        ], 'feedback-hub-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FeedbackHubHealthCommand::class,
                FeedbackHubInstallCommand::class,
                FeedbackHubTelegramDiscoverCommand::class,
                FeedbackHubTelegramResolveCommand::class,
                FeedbackHubTelegramTestCommand::class,
                FeedbackHubTestDeliveryCommand::class,
            ]);
        }
    }

    private function registerMigrations(): void
    {
        $path = realpath(__DIR__.'/../database/migrations');

        if ($path === false) {
            return;
        }

        if (! $this->usesTenantDatabases()) {
            $this->loadMigrationsFrom($path);

            return;
        }

        $parameters = (array) config('tenancy.migration_parameters', []);
        $paths = (array) ($parameters['--path'] ?? [database_path('migrations/tenant')]);
        $paths = array_map(
            function (mixed $candidate) use ($parameters): string {
                if (! is_string($candidate)) {
                    throw new \InvalidArgumentException('Tenant migration paths must be strings.');
                }

                return ($parameters['--realpath'] ?? false) ? $candidate : base_path($candidate);
            },
            $paths,
        );

        if (! in_array($path, array_map(fn (string $candidate): string => realpath($candidate) ?: $candidate, $paths), true)) {
            array_unshift($paths, $path);
        }

        $parameters['--path'] = array_values($paths);
        $parameters['--realpath'] = true;
        config(['tenancy.migration_parameters' => $parameters]);
    }

    private function usesTenantDatabases(): bool
    {
        // Same rule as Mortel\Tenancy\CurrentTenant::isMultiDatabase(): stancl must run before its model counts.
        if (! class_exists(Tenancy::class) || ! app()->bound(Tenancy::class)) {
            return false;
        }

        $model = config('tenancy.tenant_model');

        return is_string($model) && is_a($model, TenantWithDatabase::class, true);
    }
}
