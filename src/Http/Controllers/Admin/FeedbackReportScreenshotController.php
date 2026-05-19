<?php

namespace Uteq\FeedbackHub\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Uteq\FeedbackHub\Models\FeedbackReport;

class FeedbackReportScreenshotController extends Controller
{
    public function __invoke(FeedbackReport $report): StreamedResponse
    {
        abort_unless($report->screenshot_disk && $report->screenshot_path, 404);
        abort_unless(Storage::disk($report->screenshot_disk)->exists($report->screenshot_path), 404);

        return Storage::disk($report->screenshot_disk)->response(
            $report->screenshot_path,
            basename($report->screenshot_path),
            ['Content-Type' => $report->screenshot_mime ?: 'image/png'],
        );
    }
}
