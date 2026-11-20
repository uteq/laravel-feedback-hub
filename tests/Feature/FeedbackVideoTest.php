<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Uteq\FeedbackHub\Clients\OsInboxFeedbackClient;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Support\IssueBodyBuilder;
use Uteq\FeedbackHub\Tests\Support\User;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    File::deleteDirectory(storage_path('app/feedback-hub/video-uploads'));

    $this->recording = (string) file_get_contents(__DIR__.'/../Fixtures/recording.webm');
    $this->user = User::query()->create(['name' => 'Nathan Jansen', 'email' => 'nathan@example.test']);
});

function sendChunk(mixed $test, ?User $user, string $bytes, int $offset, ?string $upload = null): mixed
{
    if ($user) {
        $test->actingAs($user);
    }

    $query = array_filter(['upload' => $upload, 'offset' => $offset], fn ($value) => $value !== null);

    return $test->call('POST', route('feedback-hub.video-chunk', $query), [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
    ], $bytes);
}

/** Sends the recording in two chunks and returns the upload id. */
function uploadRecording(mixed $test, string $recording): string
{
    $half = intdiv(strlen($recording), 2);

    $first = sendChunk($test, $test->user, substr($recording, 0, $half), 0)->assertOk()->assertJsonPath('offset', $half);

    $upload = (string) $first->json('upload');

    sendChunk($test, $test->user, substr($recording, $half), $half, $upload)->assertOk()->assertJsonPath('offset', strlen($recording));

    return $upload;
}

function feedbackWithVideo(string $upload): array
{
    return [
        'type' => 'bug',
        'title' => 'Knop werkt niet',
        'url' => 'https://example.test/dashboard',
        'video_upload' => $upload,
    ];
}

it('receives a recording in chunks and stores it with the report', function (): void {
    $upload = uploadRecording($this, $this->recording);

    $this->actingAs($this->user)->postJson(route('feedback-hub.store'), feedbackWithVideo($upload))->assertCreated();

    $report = FeedbackReport::query()->firstOrFail();

    expect($report->video_disk)->toBe('local')
        ->and($report->video_path)->toStartWith('feedback-hub/videos/')
        ->and($report->video_path)->toEndWith('.webm')
        ->and($report->video_mime)->toBe('video/webm')
        ->and($report->video_size)->toBe(strlen($this->recording))
        ->and(Storage::disk('local')->get($report->video_path))->toBe($this->recording)
        ->and(File::glob(storage_path('app/feedback-hub/video-uploads/*/*')))->toBe([]);

    Queue::assertPushed(ProcessFeedbackReportJob::class);
});

it('rejects a chunk that does not continue where the upload stands', function (): void {
    $upload = sendChunk($this, $this->user, 'abc', 0)->json('upload');

    sendChunk($this, $this->user, 'def', 10, $upload)->assertStatus(409)->assertJsonPath('offset', 3);
    sendChunk($this, $this->user, 'def', 3, $upload)->assertOk()->assertJsonPath('offset', 6);
});

it('stops an upload that grows beyond the configured maximum', function (): void {
    config()->set('feedback-hub.video.max_kilobytes', 1);

    $upload = sendChunk($this, $this->user, str_repeat('a', 1000), 0)->assertOk()->json('upload');
    sendChunk($this, $this->user, str_repeat('a', 100), 1000, $upload)->assertStatus(413);

    expect(File::glob(storage_path('app/feedback-hub/video-uploads/*/*')))->toBe([]);
});

it('requires authentication for video chunks', function (): void {
    sendChunk($this, null, 'abc', 0)->assertUnauthorized();
});

it('does not let someone attach another person\'s upload', function (): void {
    $upload = uploadRecording($this, $this->recording);
    $other = User::query()->create(['name' => 'Ander', 'email' => 'ander@example.test']);

    $this->actingAs($other)->postJson(route('feedback-hub.store'), feedbackWithVideo($upload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('video_upload');

    expect(FeedbackReport::query()->count())->toBe(0);
});

it('refuses an upload that is not a video', function (): void {
    $upload = uploadRecording($this, str_repeat('not a video ', 50));

    $this->actingAs($this->user)->postJson(route('feedback-hub.store'), feedbackWithVideo($upload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('video_upload');
});

it('streams the recording to admins with range support', function (): void {
    Storage::disk('local')->put('feedback-hub/videos/test.webm', $this->recording);
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Video',
        'page_url' => 'https://example.test',
        'video_disk' => 'local',
        'video_path' => 'feedback-hub/videos/test.webm',
        'video_mime' => 'video/webm',
        'video_size' => strlen($this->recording),
    ]);

    $this->actingAs($this->user)->get(route('feedback-hub.video', $report))
        ->assertOk()
        ->assertHeader('Content-Type', 'video/webm');

    $this->actingAs($this->user)->get(route('feedback-hub.video', $report), ['Range' => 'bytes=0-9'])
        ->assertStatus(206)
        ->assertHeader('Content-Length', '10');

    $this->actingAs($this->user)->get(route('feedback-hub.show', $report))
        ->assertOk()
        ->assertSee('<video', false)
        ->assertSee(route('feedback-hub.video', $report), false);
});

it('mentions the recording in the issue, the Telegram message and the OS inbox payload', function (): void {
    Http::fake(['https://os.example.test/*' => Http::response(['ok' => true])]);
    config()->set('feedback-hub.os_inbox.url', 'https://os.example.test/inbox');
    config()->set('feedback-hub.os_inbox.key', 'os-key');

    $report = FeedbackReport::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => 'bug',
        'title' => 'Video',
        'page_url' => 'https://example.test',
        'video_disk' => 'local',
        'video_path' => 'feedback-hub/videos/test.webm',
        'video_mime' => 'video/webm',
        'video_size' => 1234,
    ]);
    $videoUrl = route('feedback-hub.video', $report);

    $builder = app(IssueBodyBuilder::class);
    expect($builder->build($report))->toContain("**Video:** {$videoUrl}")
        ->and($builder->telegramMessage($report))->toContain('Met schermopname');

    app(OsInboxFeedbackClient::class)->send($report);

    Http::assertSent(function ($request) use ($videoUrl): bool {
        $payload = collect($request->data())->firstWhere('name', 'payload');

        return json_decode((string) $payload['contents'], true)['video_url'] === $videoUrl;
    });
});

it('leaves reports without a recording unchanged in the issue body', function (): void {
    $report = FeedbackReport::query()->create([
        'type' => 'bug',
        'title' => 'Zonder video',
        'page_url' => 'https://example.test',
    ]);

    expect(app(IssueBodyBuilder::class)->build($report))->not->toContain('**Video:**')
        ->and(app(IssueBodyBuilder::class)->telegramMessage($report))->not->toContain('schermopname');
});

it('lets enough chunks through for a long recording', function (): void {
    $middleware = app('router')->getRoutes()->getByName('feedback-hub.video-chunk')->gatherMiddleware();

    expect($middleware)->toContain('throttle:1200,1');
});
