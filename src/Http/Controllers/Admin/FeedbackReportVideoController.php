<?php

namespace Uteq\FeedbackHub\Http\Controllers\Admin;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Uteq\FeedbackHub\Models\FeedbackReport;

class FeedbackReportVideoController extends Controller
{
    public function __invoke(FeedbackReport $report): BinaryFileResponse|StreamedResponse
    {
        abort_unless($report->video_disk && $report->video_path, 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($report->video_disk);
        abort_unless($disk->exists($report->video_path), 404);

        $headers = ['Content-Type' => $report->video_mime ?: 'video/webm'];

        // A local file answers Range requests, which browsers need to seek in (and Safari to play) a video.
        $local = rescue(fn () => $disk->path($report->video_path), null, false);
        if (is_string($local) && is_file($local)) {
            return response()->file($local, $headers);
        }

        return $disk->response($report->video_path, basename($report->video_path), $headers);
    }
}
