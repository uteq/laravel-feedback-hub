<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            $table->json('activity')->nullable();
            $table->decimal('recording_seconds', 8, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            $table->dropColumn(['activity', 'recording_seconds']);
        });
    }
};
