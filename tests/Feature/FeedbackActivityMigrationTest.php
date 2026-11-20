<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Uteq\FeedbackHub\Models\FeedbackReport;

function activityMigration(): Migration
{
    return require __DIR__.'/../../database/migrations/2026_11_20_000001_add_activity_to_feedback_hub_reports_table.php';
}

it('adds the activity and duration columns and tolerates repeated migration runs', function (): void {
    $migration = activityMigration();
    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('feedback_hub_reports', 'activity'))->toBeFalse()
        ->and(Schema::hasColumn('feedback_hub_reports', 'recording_seconds'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('feedback_hub_reports', 'activity'))->toBeTrue()
        ->and(Schema::hasColumn('feedback_hub_reports', 'recording_seconds'))->toBeTrue();
});

it('preserves existing activity and duration data when the columns already exist', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Existing feedback',
        'page_url' => 'https://example.test',
        'activity' => [['t' => 0.5, 'type' => 'click', 'label' => 'Save', 'selector' => '#save', 'path' => '/']],
        'recording_seconds' => 1.2,
    ]);

    activityMigration()->up();

    expect($report->fresh()->activity)->toBe($report->activity)
        ->and($report->fresh()->recording_seconds)->toBe(1.2);
});

it('adds a missing column when only one of the activity columns exists', function (): void {
    Schema::table('feedback_hub_reports', function (Blueprint $table): void {
        $table->dropColumn('recording_seconds');
    });

    activityMigration()->up();

    expect(Schema::hasColumn('feedback_hub_reports', 'activity'))->toBeTrue()
        ->and(Schema::hasColumn('feedback_hub_reports', 'recording_seconds'))->toBeTrue();
});
