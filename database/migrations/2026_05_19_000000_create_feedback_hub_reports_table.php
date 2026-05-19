<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_hub_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();
            $table->string('project');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('page_url', 2048);
            $table->string('element_selector', 500)->nullable();
            $table->json('element_rect')->nullable();
            $table->json('session_data')->nullable();
            $table->json('console_errors')->nullable();
            $table->json('network_requests')->nullable();
            $table->json('form_state')->nullable();
            $table->string('screenshot_disk')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('screenshot_mime')->nullable();
            $table->unsignedInteger('screenshot_size')->nullable();
            $table->nullableMorphs('reporter');
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            $table->string('status')->default('pending');
            $table->string('github_issue_url')->nullable();
            $table->unsignedInteger('github_issue_number')->nullable();
            $table->timestamp('github_synced_at')->nullable();
            $table->string('linear_issue_id')->nullable();
            $table->string('linear_issue_identifier')->nullable();
            $table->string('linear_issue_url')->nullable();
            $table->timestamp('linear_synced_at')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->timestamp('telegram_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['project', 'status']);
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_hub_reports');
    }
};
