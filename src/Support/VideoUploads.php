<?php

declare(strict_types=1);

namespace Uteq\FeedbackHub\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Chunked screen recordings from the widget. Each user gets an own staging directory, so an upload id
 * only resolves for the person who uploaded it. A chunk is appended only when it starts exactly where
 * the file ends, which makes a retried chunk harmless and a skipped one visible.
 */
class VideoUploads
{
    /** MIME types finfo reports for what MediaRecorder produces in Chrome, Edge, Firefox and Safari. */
    public const MIME_EXTENSIONS = [
        'video/webm' => 'webm',
        'video/x-matroska' => 'webm',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    public function maxBytes(): int
    {
        return max(1, (int) config('feedback-hub.video.max_kilobytes', 30720)) * 1024;
    }

    public function newUploadId(Authenticatable $user): string
    {
        $this->removeStale($user);

        $id = (string) Str::uuid();
        File::ensureDirectoryExists($this->directory($user));
        File::put($this->partPath($user, $id), '');

        return $id;
    }

    public function exists(Authenticatable $user, string $id): bool
    {
        return Str::isUuid($id) && File::exists($this->partPath($user, $id));
    }

    public function size(Authenticatable $user, string $id): int
    {
        clearstatcache(true, $this->partPath($user, $id));

        return (int) File::size($this->partPath($user, $id));
    }

    public function append(Authenticatable $user, string $id, string $bytes): int
    {
        File::append($this->partPath($user, $id), $bytes);

        return $this->size($user, $id);
    }

    public function discard(Authenticatable $user, string $id): void
    {
        if (Str::isUuid($id)) {
            File::delete($this->partPath($user, $id));
        }
    }

    /**
     * Moves a finished upload to the configured disk. Null when the upload is missing or not a video.
     *
     * @return array{disk: string, path: string, mime: string, size: int}|null
     */
    public function finish(Authenticatable $user, string $id): ?array
    {
        if (! $this->exists($user, $id)) {
            return null;
        }

        $part = $this->partPath($user, $id);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($part);
        $size = $this->size($user, $id);

        if (! isset(self::MIME_EXTENSIONS[$mime]) || $size === 0 || $size > $this->maxBytes()) {
            $this->discard($user, $id);

            return null;
        }

        $mime = $mime === 'video/x-matroska' ? 'video/webm' : $mime;
        $disk = (string) config('feedback-hub.storage_disk');
        $path = trim((string) config('feedback-hub.video.path', 'feedback-hub/videos'), '/').'/'.uniqid('report_', true).'.'.self::MIME_EXTENSIONS[$mime];

        $stream = fopen($part, 'rb');
        Storage::disk($disk)->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->discard($user, $id);

        return ['disk' => $disk, 'path' => $path, 'mime' => $mime, 'size' => $size];
    }

    private function removeStale(Authenticatable $user): void
    {
        foreach (File::glob($this->directory($user).'/*.part') as $file) {
            if (File::lastModified($file) < now()->subDay()->getTimestamp()) {
                File::delete($file);
            }
        }
    }

    private function directory(Authenticatable $user): string
    {
        $owner = ($user instanceof Model ? $user->getMorphClass() : $user::class).'|'.$user->getAuthIdentifier();

        return storage_path('app/feedback-hub/video-uploads/'.hash('sha256', $owner));
    }

    private function partPath(Authenticatable $user, string $id): string
    {
        return $this->directory($user).'/'.$id.'.part';
    }
}
