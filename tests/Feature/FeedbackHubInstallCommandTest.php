<?php

it('publishes config migrations and screenshot assets', function (): void {
    $configPath = config_path('feedback-hub.php');
    $assetPath = resource_path('js/vendor/feedback-hub/feedback-inspector.js');
    $snapdomPath = resource_path('js/vendor/feedback-hub/snapdom.mjs');
    $migrationPath = database_path('migrations/2026_05_19_000000_create_feedback_hub_reports_table.php');
    $intentMigrationPath = database_path('migrations/2026_09_15_000000_add_intent_to_feedback_hub_reports_table.php');

    @unlink($intentMigrationPath);
    @unlink($configPath);
    @unlink($assetPath);
    @unlink($snapdomPath);
    @unlink($migrationPath);

    $this->artisan('feedback-hub:install')
        ->expectsOutputToContain('Feedback Hub files published.')
        ->assertSuccessful();

    expect($configPath)->toBeFile()
        ->and($assetPath)->toBeFile()
        ->and($snapdomPath)->toBeFile()
        ->and($migrationPath)->toBeFile()
        ->and($intentMigrationPath)->toBeFile()
        ->and(file_get_contents($assetPath))->toBe(file_get_contents(__DIR__.'/../../resources/js/feedback-inspector.js'))
        ->toContain("document.addEventListener('click', this.boundHandleDocumentClick, true)")
        ->toContain('pointer-events:none')
        ->toContain('createPlaceholderScreenshot()')
        ->toContain('Screenshot niet beschikbaar')
        ->toContain("document.readyState === 'loading'")
        ->toContain('initializeFeedbackHubInspector();')
        ->toContain("window.dispatchEvent(new CustomEvent('feedback-hub-captured'")
        ->toContain("window.dispatchEvent(new CustomEvent('feedback-hub-screenshot-captured'")
        ->not->toContain('getDisplayMedia')
        ->and(file_get_contents($snapdomPath))->toBe(file_get_contents(__DIR__.'/../../resources/js/snapdom.mjs'));
});
