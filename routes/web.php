<?php

use Illuminate\Support\Facades\Route;
use Uteq\FeedbackHub\Http\Controllers\Admin\FeedbackReportIndexController;
use Uteq\FeedbackHub\Http\Controllers\Admin\FeedbackReportScreenshotController;
use Uteq\FeedbackHub\Http\Controllers\Admin\FeedbackReportShowController;
use Uteq\FeedbackHub\Http\Controllers\StoreFeedbackReportController;

Route::prefix(config('feedback-hub.route_prefix', 'feedback-hub'))
    ->name('feedback-hub.')
    ->middleware(config('feedback-hub.route_middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::post('/', StoreFeedbackReportController::class)->name('store');
    });

Route::prefix(config('feedback-hub.route_prefix', 'feedback-hub'))
    ->name('feedback-hub.')
    ->middleware(config('feedback-hub.admin_middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::get('/', FeedbackReportIndexController::class)->name('index');
        Route::get('/{report}', FeedbackReportShowController::class)->name('show');
        Route::get('/{report}/screenshot', FeedbackReportScreenshotController::class)->name('screenshot');
    });
