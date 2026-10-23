<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['intent', 'transcript'] as $column) {
            if (! Schema::hasColumn('feedback_hub_reports', $column)) {
                Schema::table('feedback_hub_reports', function (Blueprint $table) use ($column): void {
                    $table->json($column)->nullable()->after($column === 'intent' ? 'description' : 'intent');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['transcript', 'intent'] as $column) {
            if (Schema::hasColumn('feedback_hub_reports', $column)) {
                Schema::table('feedback_hub_reports', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
