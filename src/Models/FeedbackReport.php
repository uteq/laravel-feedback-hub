<?php

namespace Uteq\FeedbackHub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class FeedbackReport extends Model
{
    protected $table = 'feedback_hub_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'element_rect' => 'array',
            'session_data' => 'array',
            'console_errors' => 'array',
            'network_requests' => 'array',
            'form_state' => 'array',
            'intent' => 'array',
            'transcript' => 'array',
            'github_issue_number' => 'integer',
            'github_synced_at' => 'datetime',
            'linear_synced_at' => 'datetime',
            'telegram_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FeedbackReport $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->reference ??= static::nextReference();
            $report->project ??= (string) config('feedback-hub.project');
            $report->status ??= 'pending';
        });
    }

    public function reporter(): MorphTo
    {
        return $this->morphTo();
    }

    public function adminUrl(): string
    {
        return route('feedback-hub.show', $this);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    private static function nextReference(): string
    {
        $year = now()->year;
        $prefix = "FH-{$year}-";
        $lastReference = static::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $lastReference ? ((int) substr($lastReference, -5)) + 1 : 1;

        return $prefix.sprintf('%05d', $next);
    }
}
