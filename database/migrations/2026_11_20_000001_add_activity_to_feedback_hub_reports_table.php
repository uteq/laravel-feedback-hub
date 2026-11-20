<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('feedback_hub_reports', 'activity')) {
                $table->json('activity')->nullable();
            }
            if (! Schema::hasColumn('feedback_hub_reports', 'recording_seconds')) {
                $table->decimal('recording_seconds', 8, 1)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            foreach (['activity', 'recording_seconds'] as $column) {
                if (Schema::hasColumn('feedback_hub_reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
