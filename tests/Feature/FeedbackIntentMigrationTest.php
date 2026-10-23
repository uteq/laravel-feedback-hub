<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Uteq\FeedbackHub\Models\FeedbackReport;

function intentMigration(): Migration
{
    return require __DIR__.'/../../database/migrations/2026_09_15_000000_add_intent_to_feedback_hub_reports_table.php';
}

it('adds the intent and transcript columns and tolerates repeated migration runs', function (): void {
    $migration = intentMigration();
    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('feedback_hub_reports', 'intent'))->toBeFalse()
        ->and(Schema::hasColumn('feedback_hub_reports', 'transcript'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('feedback_hub_reports', 'intent'))->toBeTrue()
        ->and(Schema::hasColumn('feedback_hub_reports', 'transcript'))->toBeTrue();
});

it('preserves existing intent and transcript data when the columns already exist', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Existing feedback',
        'page_url' => 'https://example.test',
        'intent' => ['goal' => 'Download a PDF'],
        'transcript' => [['role' => 'user', 'content' => 'The button does nothing']],
    ]);

    intentMigration()->up();

    expect($report->fresh()->intent)->toBe(['goal' => 'Download a PDF'])
        ->and($report->fresh()->transcript)->toBe([['role' => 'user', 'content' => 'The button does nothing']]);
});

it('adds a missing column when only one of the intent columns exists', function (): void {
    Schema::table('feedback_hub_reports', function (Blueprint $table): void {
        $table->dropColumn('transcript');
    });

    intentMigration()->up();

    expect(Schema::hasColumn('feedback_hub_reports', 'intent'))->toBeTrue()
        ->and(Schema::hasColumn('feedback_hub_reports', 'transcript'))->toBeTrue();
});
