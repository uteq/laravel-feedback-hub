<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Uteq\FeedbackHub\Support\VideoUploads;

/**
 * Receives one piece of a screen recording as the raw request body (application/octet-stream), so PHP's
 * upload_max_filesize never applies. The first piece (no `upload` in the query) starts a new upload; every
 * next piece names the upload and the offset it starts at. The answer is the offset to continue from.
 */
class StoreFeedbackVideoChunkController extends Controller
{
    public function __invoke(Request $request, VideoUploads $uploads): JsonResponse
    {
        abort_unless((bool) config('feedback-hub.enabled') && (bool) config('feedback-hub.video.enabled', true), 404);

        $validated = validator($request->query(), [
            'upload' => ['nullable', 'uuid'],
            'offset' => ['required', 'integer', 'min:0'],
        ])->validate();

        $bytes = $request->getContent();
        $maxChunk = max(1, (int) config('feedback-hub.video.chunk_kilobytes', 512)) * 2 * 1024;

        if ($bytes === '' || strlen($bytes) > $maxChunk) {
            return response()->json(['message' => 'Invalid chunk size.'], 422);
        }

        $user = $request->user();
        abort_if($user === null, 401);

        $id = $validated['upload'] ?? null;

        if ($id === null) {
            if ((int) $validated['offset'] !== 0) {
                return response()->json(['message' => 'A new upload starts at offset 0.', 'offset' => 0], 409);
            }

            $id = $uploads->newUploadId($user);
        } elseif (! $uploads->exists($user, $id)) {
            return response()->json(['message' => 'Upload not found.'], 404);
        }

        $current = $uploads->size($user, $id);

        if ((int) $validated['offset'] !== $current) {
            return response()->json(['message' => 'Offset does not match the upload.', 'upload' => $id, 'offset' => $current], 409);
        }

        if ($current + strlen($bytes) > $uploads->maxBytes()) {
            $uploads->discard($user, $id);

            return response()->json(['message' => 'De video is te groot.'], 413);
        }

        return response()->json(['upload' => $id, 'offset' => $uploads->append($user, $id, $bytes)]);
    }
}
