<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\BrowserTestLayout;
use Uteq\FeedbackHub\Tests\Support\User;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    config()->set('ai.providers.anthropic.key', null);
    // Small chunks, so even a one-second test recording travels in several pieces.
    config()->set('feedback-hub.video.chunk_kilobytes', 1);
    $user = User::query()->create(['name' => 'Browser Tester', 'email' => 'browser@example.test']);

    Route::get('/_fhv/blank', fn () => '<!DOCTYPE html><html><body>Blank</body></html>');
    Route::middleware('web')->get('/_fhv/page/{media?}', function (string $media = 'canvas') use ($user) {
        test()->actingAs($user);

        return BrowserTestLayout::render(<<<HTML
            <script>
                window.mediaCalls = { display: 0, mic: 0 };
                window.chunkRequests = 0;
                const realFetch = window.fetch;
                window.fetch = (url, options) => { if (String(url).includes('/video/chunks')) window.chunkRequests++; return realFetch(url, options); };
                const canvasStream = () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 160; canvas.height = 90;
                    const context = canvas.getContext('2d');
                    let frame = 0;
                    window.paint = setInterval(() => { context.fillStyle = 'hsl(' + (frame++ * 30) + ',70%,50%)'; context.fillRect(0, 0, 160, 90); }, 40);
                    window.displayStream = canvas.captureStream(15);
                    return window.displayStream;
                };
                const media = '{$media}';
                if (media !== 'none') {
                    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
                        getDisplayMedia() { window.mediaCalls.display++; return media === 'denied' ? Promise.reject(new DOMException('Permission denied', 'NotAllowedError')) : Promise.resolve(canvasStream()); },
                        getUserMedia() { window.mediaCalls.mic++; return Promise.reject(new DOMException('Permission denied', 'NotAllowedError')); },
                    } });
                } else {
                    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
                }
            </script>
            <h1>Een pagina om op te nemen</h1>
            <x-feedback-hub::floating-button />
            HTML);
    });
});

function videoFeedbackPage(string $media = 'canvas'): mixed
{
    $page = visit('/_fhv/blank');
    $page->navigate('/_fhv/page/'.$media);
    $page->assertScript("typeof window.Alpine !== 'undefined' && !!document.querySelector('[data-feedback-hub-widget]')._x_dataStack");
    $page->click('button[aria-label="Feedback geven"]');

    return $page;
}

it('records the screen, shows the recording and sends it with the feedback', function (): void {
    $page = videoFeedbackPage();

    $page->click('button:has-text("Neem je scherm op")')
        ->assertScript('window.mediaCalls.display', 1)
        ->assertScript('window.mediaCalls.mic', 1)
        ->assertVisible('[data-feedback-hub-recording]')
        ->assertScript('getComputedStyle(document.querySelector(".fh-backdrop")).display', 'none')
        ->assertNotPresent('button[aria-label="Feedback geven"]:visible');

    $page->script('new Promise(resolve => setTimeout(resolve, 1200))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src?.startsWith("blob:")')
        ->assertScript('window.displayStream.getTracks().every(track => track.readyState === "ended")')
        ->assertNotPresent('[data-feedback-hub-recording]:visible');

    $page->fill('#feedback-hub-title', 'Zo gaat het mis')
        ->click('button:has-text("Versturen")')
        ->assertScript('document.querySelector(".fh-backdrop").style.display === "none"');

    $page->assertScript('window.chunkRequests > 1');

    $report = FeedbackReport::query()->sole();
    expect($report->title)->toBe('Zo gaat het mis')
        ->and($report->video_mime)->toBeIn(['video/webm', 'video/mp4'])
        ->and($report->video_size)->toBeGreaterThan(1024);
    Storage::disk('local')->assertExists($report->video_path);
});

it('stops by itself when the browser stops sharing or the time is up', function (string $how): void {
    if ($how === 'time') {
        config()->set('feedback-hub.video.max_seconds', 1);
    }

    $page = videoFeedbackPage();
    $page->click('button:has-text("Neem je scherm op")')
        ->assertVisible('[data-feedback-hub-recording]');

    if ($how === 'browser') {
        $page->script(<<<'JS'
            new Promise(resolve => setTimeout(() => {
                const track = window.displayStream.getVideoTracks()[0];
                track.stop();
                track.dispatchEvent(new Event('ended'));
                resolve();
            }, 600))
            JS);
    }

    $page->assertScript('new Promise(resolve => { const check = () => document.querySelector(".fh-modal video")?.src ? resolve(true) : setTimeout(check, 50); check(); })')
        ->assertNotPresent('[data-feedback-hub-recording]:visible');
})->with(['browser', 'time']);

it('returns to the form with a clear message when the person does not allow recording', function (): void {
    $page = videoFeedbackPage('denied');

    $page->click('button:has-text("Neem je scherm op")')
        ->assertVisible('.fh-modal')
        ->assertSee('Opnemen is niet gelukt')
        ->assertNotPresent('.fh-modal video');
});

it('lets the person remove the recording before sending', function (): void {
    $page = videoFeedbackPage();
    $page->click('button:has-text("Neem je scherm op")');
    $page->script('new Promise(resolve => setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src')
        ->click('button:has-text("Video weghalen")')
        ->assertNotPresent('.fh-modal video')
        ->fill('#feedback-hub-title', 'Toch zonder video')
        ->click('button:has-text("Versturen")')
        ->assertScript('document.querySelector(".fh-backdrop").style.display === "none"');

    expect(FeedbackReport::query()->sole()->video_path)->toBeNull();
});

it('hides the record button when the browser cannot record', function (): void {
    videoFeedbackPage('none')
        ->assertVisible('.fh-modal')
        ->assertDontSee('Neem je scherm op');
});

it('hides the record button when video is switched off', function (): void {
    config()->set('feedback-hub.video.enabled', false);

    videoFeedbackPage()
        ->assertVisible('.fh-modal')
        ->assertDontSee('Neem je scherm op');
});
