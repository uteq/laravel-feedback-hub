<?php

use Illuminate\Support\ServiceProvider;
use Uteq\FeedbackHub\FeedbackHubServiceProvider;

it('publishes the inspector and its relative screenshot module together', function (): void {
    $paths = ServiceProvider::pathsToPublish(FeedbackHubServiceProvider::class, 'feedback-hub-assets');

    foreach (['feedback-inspector.js', 'snapdom.mjs'] as $file) {
        $source = realpath(__DIR__.'/../../resources/js').'/'.$file;
        $published = collect($paths)->first(fn ($destination, $path) => realpath($path) === $source);

        expect($published)->toBe(resource_path('js/vendor/feedback-hub/'.$file));
    }
});
