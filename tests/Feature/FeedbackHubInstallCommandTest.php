<?php

it('publishes config migration and inspector asset', function (): void {
    $configPath = config_path('feedback-hub.php');
    $assetPath = resource_path('js/vendor/feedback-hub/feedback-inspector.js');
    $migrationPath = database_path('migrations/2026_05_19_000000_create_feedback_hub_reports_table.php');

    @unlink($configPath);
    @unlink($assetPath);
    @unlink($migrationPath);

    $this->artisan('feedback-hub:install')
        ->expectsOutputToContain('Feedback Hub files published.')
        ->assertSuccessful();

    expect($configPath)->toBeFile()
        ->and($assetPath)->toBeFile()
        ->and($migrationPath)->toBeFile();
});
