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

    $asset = file_get_contents($assetPath);

    expect($configPath)->toBeFile()
        ->and($assetPath)->toBeFile()
        ->and($migrationPath)->toBeFile()
        ->and($asset)
        ->toContain("document.addEventListener('click', this.boundHandleDocumentClick, true)")
        ->toContain('pointer-events:none')
        ->toContain('navigator.mediaDevices?.getDisplayMedia')
        ->toContain("document.createElement('video')")
        ->toContain("canvas.getContext('2d').drawImage(video")
        ->toContain('createPlaceholderScreenshot()')
        ->toContain('Screenshot niet beschikbaar')
        ->not->toContain('ImageCapture')
        ->toContain("document.readyState === 'loading'")
        ->toContain('initializeFeedbackHubInspector();')
        ->toContain("window.dispatchEvent(new CustomEvent('feedback-hub-captured'")
        ->toContain("window.dispatchEvent(new CustomEvent('feedback-hub-screenshot-captured'");

    expect(strpos($asset, 'stream = await navigator.mediaDevices.getDisplayMedia'))
        ->toBeLessThan(strpos($asset, 'await new Promise((resolve) => requestAnimationFrame(resolve));'));
});
