<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as DefaultTenant;
use Stancl\Tenancy\TenancyServiceProvider;
use Uteq\FeedbackHub\FeedbackHubServiceProvider;

final class TenantOnlyMigrationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...(class_exists(TenancyServiceProvider::class) ? [TenancyServiceProvider::class] : []),
            FeedbackHubServiceProvider::class,
        ];
    }

    protected function tenantHost(Application $app): void
    {
        if (! class_exists(TenancyServiceProvider::class)) {
            $this->markTestSkipped('Optional stancl integration requires the stancl provider.');
        }

        $tenant = new class extends DefaultTenant implements TenantWithDatabase
        {
            use HasDatabase;
        };
        $app['config']->set('tenancy.tenant_model', $tenant::class);
        $app['config']->set('tenancy.migration_parameters', [
            '--force' => true,
            '--path' => ['database/migrations/tenant'],
            '--realpath' => false,
        ]);
    }

    protected function registeredTenantHost(Application $app): void
    {
        $this->tenantHost($app);
        $app['config']->set('tenancy.migration_parameters.--path', [$this->migrationPath()]);
        $app['config']->set('tenancy.migration_parameters.--realpath', true);
    }

    private function migrationPath(): string
    {
        return (string) realpath(dirname(__DIR__, 2).'/database/migrations');
    }

    #[DefineEnvironment('tenantHost')]
    public function test_tenant_migrations_are_never_registered_with_the_central_migrator(): void
    {
        $paths = array_map(fn (string $path): string => realpath($path) ?: $path, $this->app['migrator']->paths());
        $this->assertNotContains($this->migrationPath(), $paths);
        $this->assertSame([
            $this->migrationPath(), database_path('migrations/tenant'),
        ], config('tenancy.migration_parameters.--path'));
        $this->assertTrue(config('tenancy.migration_parameters.--realpath'));
        $this->assertTrue(config('tenancy.migration_parameters.--force'));
    }

    #[DefineEnvironment('registeredTenantHost')]
    public function test_existing_tenant_registration_and_migration_names_are_preserved(): void
    {
        $this->assertSame([$this->migrationPath()], config('tenancy.migration_parameters.--path'));
        $files = $this->app['migrator']->getMigrationFiles(config('tenancy.migration_parameters.--path'));
        $this->assertSame(array_keys($this->app['migrator']->getMigrationFiles([$this->migrationPath()])), array_keys($files));
        $this->assertNotEmpty($files);
    }

    #[DefineEnvironment('tenantHost')]
    public function test_multi_database_hosts_publish_migrations_only_to_tenant_paths(): void
    {
        $paths = ServiceProvider::pathsToPublish(null, 'feedback-hub-migrations');
        $this->assertNotEmpty($paths);
        foreach ($paths as $target) {
            $this->assertStringStartsWith(database_path('migrations/tenant'), $target);
        }
    }

    public function test_single_database_hosts_publish_to_the_normal_migration_path(): void
    {
        $paths = ServiceProvider::pathsToPublish(null, 'feedback-hub-migrations');
        $this->assertNotEmpty($paths);
        foreach ($paths as $target) {
            $this->assertStringStartsWith(database_path('migrations'), $target);
            $this->assertFalse(str_starts_with($target, database_path('migrations/tenant')));
        }
    }

    public function test_single_database_hosts_keep_their_migrations(): void
    {
        $paths = array_map(fn (string $path): string => realpath($path) ?: $path, $this->app['migrator']->paths());
        $this->assertContains($this->migrationPath(), $paths);
        config(['database.default' => 'testing', 'database.connections.testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        $this->artisan('migrate')->assertSuccessful();
        $files = $this->app['migrator']->getMigrationFiles([$this->migrationPath()]);
        $ran = $this->app['migration.repository']->getRan();
        foreach (array_keys($files) as $migration) {
            $this->assertContains($migration, $ran);
        }
        $this->assertTrue(Schema::hasTable('feedback_hub_reports'));

        if (class_exists(TenancyServiceProvider::class)) {
            $this->assertSame(DefaultTenant::class, config('tenancy.tenant_model'));
            $this->assertTrue($this->app->providerIsLoaded(TenancyServiceProvider::class));
            $this->assertNotContains($this->migrationPath(), (array) config('tenancy.migration_parameters.--path'));
        }
    }
}
