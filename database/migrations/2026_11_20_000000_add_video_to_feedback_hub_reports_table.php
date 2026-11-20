<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('feedback_hub_reports', 'video_path')) {
            return;
        }

        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            $table->string('video_disk')->nullable()->after('screenshot_size');
            $table->string('video_path')->nullable()->after('video_disk');
            $table->string('video_mime')->nullable()->after('video_path');
            $table->unsignedBigInteger('video_size')->nullable()->after('video_mime');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('feedback_hub_reports', 'video_path')) {
            return;
        }

        Schema::table('feedback_hub_reports', function (Blueprint $table): void {
            $table->dropColumn(['video_disk', 'video_path', 'video_mime', 'video_size']);
        });
    }
};
