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
    Route::middleware('web')->get('/_fhv/{page}/{media?}', function (string $page, string $media = 'canvas') use ($user) {
        test()->actingAs($user);
        $component = $media === 'follow' ? '<x-feedback-hub::floating-button follow-up-url="/_video-follow-up" />' : '<x-feedback-hub::floating-button />';

        return BrowserTestLayout::render(<<<HTML
            <script>
                window.mediaCalls = { display: 0, mic: 0 };
                window.chunkRequests = 0;
                const realFetch = window.fetch;
                window.failChunks = 0;
                window.chunkProgress = [];
                window.fetch = (url, options) => {
                    if (String(url).includes('/video/chunks')) {
                        window.chunkRequests++;
                        const label = document.querySelector('[data-feedback-hub-submit-label]')?.textContent;
                        if (label) window.chunkProgress.push(label.trim());
                        if (window.failChunks > 0) { window.failChunks--; return Promise.resolve(new Response('{}', { status: 503, headers: { 'Content-Type': 'application/json' } })); }
                    }
                    return realFetch(url, options);
                };
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
                // Firefox records a whole window or screen; mozInnerScreenX/Y tell where the page sits in it.
                // Here: a 1000x700 window with a 200px side panel and a 100px toolbar (red) around an 800x600 page (green).
                if (media.startsWith('firefox')) {
                    const geometry = { mozInnerScreenX: 200, mozInnerScreenY: 100, screenX: 0, screenY: 0, outerWidth: 1000, outerHeight: 700, innerWidth: 800, innerHeight: 600 };
                    Object.entries(geometry).forEach(([key, value]) => Object.defineProperty(window, key, { configurable: true, get: () => value }));
                }
                const firefoxStream = (width, height) => {
                    const canvas = document.createElement('canvas');
                    canvas.width = width; canvas.height = height;
                    const context = canvas.getContext('2d');
                    window.paint = setInterval(() => {
                        context.fillStyle = 'rgb(220, 30, 30)'; context.fillRect(0, 0, width, height);
                        context.fillStyle = 'rgb(30, 200, 60)'; context.fillRect(200, 100, 800, 600);
                    }, 40);
                    window.displayStream = canvas.captureStream(15);
                    return window.displayStream;
                };
                const displayFor = () => media === 'firefox-window' ? firefoxStream(1000, 700) : (media === 'firefox-other' ? firefoxStream(640, 480) : canvasStream());
                if (media !== 'none') {
                    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
                        getDisplayMedia() { window.mediaCalls.display++; return media === 'denied' ? Promise.reject(new DOMException('Permission denied', 'NotAllowedError')) : Promise.resolve(displayFor()); },
                        getUserMedia() { window.mediaCalls.mic++; return Promise.reject(new DOMException('Permission denied', 'NotAllowedError')); },
                    } });
                } else {
                    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
                }
            </script>
            <h1>Pagina {$page}</h1>
            <a href="/_fhv/second/{$media}" wire:navigate>Naar de tweede pagina</a>
            {$component}
            HTML);
    });
});

function videoFeedbackPage(string $media = 'canvas'): mixed
{
    $page = visit('/_fhv/blank');
    $page->navigate('/_fhv/first/'.$media);
    $page->assertScript("typeof window.Alpine !== 'undefined' && !!document.querySelector('[data-feedback-hub-widget]')._x_dataStack");
    $page->click('button[aria-label="Feedback geven"]');

    return $page;
}

function startVideo(mixed $page): mixed
{
    return $page->click('button:has-text("Video opnemen")')
        ->assertVisible('[data-feedback-hub-recording]');
}

it('asks first whether the person wants a screenshot or a video', function (): void {
    $page = videoFeedbackPage();

    $page->assertVisible('[data-feedback-hub-choice]')
        ->assertSee('Schermafbeelding')
        ->assertSee('Video opnemen')
        ->assertNotPresent('.fh-modal:visible');

    $page->click('button:has-text("Schermafbeelding")')
        ->assertNotPresent('[data-feedback-hub-choice]:visible')
        ->assertVisible('.fh-modal')
        ->assertScript('window.mediaCalls.display', 0);
});

it('asks only for this tab when the browser supports it', function (): void {
    $page = videoFeedbackPage();
    $page->script(<<<'JS'
        const original = navigator.mediaDevices.getDisplayMedia;
        navigator.mediaDevices.getDisplayMedia = (options) => { window.displayOptions = options; return original(options); };
        JS);
    startVideo($page)
        ->assertScript('window.displayOptions.preferCurrentTab', true)
        ->assertScript('window.displayOptions.video.displaySurface', 'browser')
        ->assertScript('window.displayOptions.monitorTypeSurfaces', 'exclude');
});

it('keeps recording while the person moves to another page', function (): void {
    $page = videoFeedbackPage();
    startVideo($page);

    $page->click('a:has-text("Naar de tweede pagina")')
        ->assertSee('Pagina second')
        ->assertVisible('[data-feedback-hub-recording]')
        ->assertScript('window.displayStream.getVideoTracks()[0].readyState', 'live')
        ->assertScript("document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0].activity.some(e => e.type === 'navigate' && e.path === '/_fhv/second/canvas')");

    $page->script('new Promise(resolve => setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src?.startsWith("blob:")')
        ->assertScript('Alpine.$data(document.querySelector("[data-feedback-hub-widget]")).videoUrl !== null');
});

it('warns before the page is left while recording', function (): void {
    $page = videoFeedbackPage();
    $page->assertScript('(() => { const e = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; })()', false);

    startVideo($page)
        ->assertScript('(() => { const e = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; })()', true);
});

it('records the screen, shows the recording and sends it with the feedback', function (): void {
    $page = videoFeedbackPage();

    startVideo($page)
        ->assertScript('window.mediaCalls.display', 1)
        ->assertScript('window.mediaCalls.mic', 1)
        ->assertVisible('[data-feedback-hub-recording]')
        ->assertScript('getComputedStyle(document.querySelector(".fh-backdrop")).display', 'none')
        ->assertNotPresent('[data-feedback-hub-choice]:visible')
        ->assertNotPresent('button[aria-label="Feedback geven"]:visible');

    $page->script("window.FeedbackHubInspector = { getSelector: el => el.id ? '#' + el.id : el.tagName.toLowerCase(), redactText: value => value }; document.body.insertAdjacentHTML('afterbegin', '<button id=outside>Opslaan</button><label for=private-field>Naam</label><input id=private-field value=never-record-this>')");
    $page->click('#outside')->click('#private-field');
    $page->script("history.pushState({}, '', '/_fhv/changed?token=private'); history.replaceState({}, '', '/_fhv/replaced'); history.back()");
    $page->assertScript("document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0].activity.some(e => e.type === 'navigate')");
    $page->script('new Promise(resolve => setTimeout(resolve, 1200))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src?.startsWith("blob:")')
        ->assertScript('window.displayStream.getTracks().every(track => track.readyState === "ended")')
        ->assertNotPresent('[data-feedback-hub-recording]:visible');

    $page->assertScript('window.chunkRequests', 0)
        ->assertNotPresent('.fh-modal img[alt="Feedback screenshot"]:visible');

    $page->fill('#feedback-hub-title', 'Zo gaat het mis')
        ->click('button:has-text("Versturen")')
        ->assertScript('document.querySelector(".fh-backdrop").style.display === "none"');

    $page->assertScript('window.chunkRequests > 1');

    $report = FeedbackReport::query()->sole();
    expect($report->title)->toBe('Zo gaat het mis')
        ->and($report->video_mime)->toBeIn(['video/webm', 'video/mp4'])
        ->and($report->video_size)->toBeGreaterThan(1024);
    expect(collect($report->activity)->pluck('type')->all())->toContain('click', 'navigate');
    expect(json_encode($report->activity))->not->toContain('never-record-this', '?token', 'Stop');
    expect($report->recording_seconds)->toBeGreaterThan(0);
    Storage::disk('local')->assertExists($report->video_path);
});

it('stops by itself when the browser stops sharing or the time is up', function (string $how): void {
    if ($how === 'time') {
        config()->set('feedback-hub.video.max_seconds', 1);
    }

    $page = videoFeedbackPage();
    startVideo($page);

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

    $page->click('button:has-text("Video opnemen")')
        ->assertVisible('.fh-modal')
        ->assertSee('Opnemen is niet gelukt')
        ->assertNotPresent('.fh-modal video');
});

it('lets the person remove the recording before sending', function (): void {
    $page = videoFeedbackPage();
    startVideo($page);
    $page->script('new Promise(resolve => setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src')
        ->click('button:has-text("Video verwijderen")')
        ->assertNotPresent('.fh-modal video')
        ->fill('#feedback-hub-title', 'Toch zonder video')
        ->click('button:has-text("Versturen")')
        ->assertScript('document.querySelector(".fh-backdrop").style.display === "none"');

    expect(FeedbackReport::query()->sole()->video_path)->toBeNull();
});

it('skips the choice when the browser cannot record', function (): void {
    videoFeedbackPage('none')
        ->assertVisible('.fh-modal')
        ->assertDontSee('Video opnemen');
});

it('skips the choice when video is switched off', function (): void {
    config()->set('feedback-hub.video.enabled', false);

    videoFeedbackPage()
        ->assertVisible('.fh-modal')
        ->assertDontSee('Video opnemen');
});

it('offers to record again from the review', function (): void {
    $page = videoFeedbackPage();
    startVideo($page);
    $page->script('new Promise(resolve => setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->click('button:has-text("Opnieuw opnemen")')
        ->assertVisible('[data-feedback-hub-recording]')
        ->assertScript('window.mediaCalls.display', 2);
});

/** Size and two pixel colours (corner and centre) of a frame of the recording in the review. */
function recordedFrame(mixed $page): array
{
    return $page->script(<<<'JS'
        new Promise(async (resolve) => {
            const video = document.createElement('video');
            video.muted = true;
            video.src = document.querySelector('.fh-modal video').src;
            await new Promise(done => { video.onloadeddata = done; });
            video.currentTime = 0.3;
            await new Promise(done => { video.onseeked = done; setTimeout(done, 1000); });
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            const context = canvas.getContext('2d');
            context.drawImage(video, 0, 0);
            const pixel = (x, y) => [...context.getImageData(x, y, 1, 1).data.slice(0, 3)];
            resolve({ width: video.videoWidth, height: video.videoHeight, corner: pixel(8, 8), centre: pixel(canvas.width / 2, canvas.height / 2) });
        })
        JS);
}

it('records only the page when Firefox shares the whole window', function (): void {
    $page = videoFeedbackPage('firefox-window');
    startVideo($page);
    $page->script('new Promise(resolve => setTimeout(resolve, 900))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src');

    $frame = recordedFrame($page);

    expect($frame['width'])->toBe(800)
        ->and($frame['height'])->toBe(600)
        ->and($frame['corner'][1])->toBeGreaterThan(150)
        ->and($frame['corner'][0])->toBeLessThan(100)
        ->and($frame['centre'][1])->toBeGreaterThan(150);
    $page->assertScript('window.displayStream.getTracks().every(track => track.readyState === "ended")');
});

it('keeps the recording as it is when it does not match the window or screen', function (): void {
    $page = videoFeedbackPage('firefox-other');
    startVideo($page);
    $page->script('new Promise(resolve => setTimeout(resolve, 900))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src');

    $frame = recordedFrame($page);

    expect($frame['width'])->toBe(640)
        ->and($frame['height'])->toBe(480);
});

it('retries a failed piece and shows how far sending is', function (): void {
    $page = videoFeedbackPage();
    startVideo($page);
    $page->script('new Promise(resolve => setTimeout(resolve, 1200))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertScript('!!document.querySelector(".fh-modal video")?.src');

    $page->script('window.failChunks = 2;');
    $page->fill('#feedback-hub-title', 'Lang proces')
        ->click('button:has-text("Versturen")')
        ->assertScript('document.querySelector(".fh-backdrop").style.display === "none"');

    $page->assertScript('window.chunkProgress.some(label => /^Video versturen \\d+%$/.test(label))');

    $report = FeedbackReport::query()->sole();
    expect($report->video_size)->toBeGreaterThan(1024);
    Storage::disk('local')->assertExists($report->video_path);
});

it('can submit video only through the opt-in follow-up window with activity', function (): void {
    $page = videoFeedbackPage('follow');
    $page->script(<<<'JS'
        const widget = document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0];
        widget.followUpUrl = '/_video-follow-up';
        const real = window.fetch;
        window.fetch = (url, options) => String(url).endsWith('/_video-follow-up')
            ? Promise.resolve(new Response(JSON.stringify({summary: null, questions: [], edit_url: null}), {headers: {'Content-Type': 'application/json'}}))
            : real(url, options);
        document.body.insertAdjacentHTML('afterbegin', '<button id="video-click">Planning</button>');
    JS);
    startVideo($page)->click('#video-click');
    $page->script("history.pushState({}, '', '/_fhv/video-follow-up'); new Promise(resolve => setTimeout(resolve, 600))");
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')
        ->assertVisible('.fh-video')
        ->click('button:has-text("Versturen")')->assertSee('Je hoeft niets meer te doen.');
    $report = FeedbackReport::query()->sole();
    expect($report->title)->toBe('Video-feedback')
        ->and($report->description)->toBeNull()
        ->and(collect($report->activity)->pluck('type')->all())->toContain('click', 'navigate');
});

it('logs only interactive text and deactivates history wrappers retained by another script', function (): void {
    $page = videoFeedbackPage();
    $page->script(<<<'JS'
        window.FeedbackHubInspector = {getSelector: el => '#' + el.id, redactText: value => value};
        document.body.insertAdjacentHTML('afterbegin', '<div id="noninteractive">Private customer text</div><button id="interactive"><span>  Planning   opslaan </span></button>');
    JS);
    startVideo($page)->click('#noninteractive')->click('#interactive span');
    $page->script(<<<'JS'
        const previous = history.pushState;
        history.pushState = function (...args) { return previous.apply(this, args); };
        history.pushState({}, '', '/_fhv/during');
    JS);
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')->assertVisible('.fh-video')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.find(e=>e.selector==='#noninteractive').label", '')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.find(e=>e.selector==='#interactive').label", 'Planning opslaan');
    $page->script("window.countBefore = document.querySelector('.fh-widget')._x_dataStack[0].activity.length; history.pushState({}, '', '/_fhv/after'); history.replaceState({}, '', '/_fhv/after-replace')");
    $page->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.length === window.countBefore", true);
});

it('announces a sent report after closing during the chunk upload and follows up silently', function (): void {
    $page = videoFeedbackPage('follow');
    $page->script(<<<'JS'
        window.toasts=[]; window.followRequests=[];
        window.addEventListener('feedback-hub:toast', e=>window.toasts.push(e.detail.heading));
        const previous = window.fetch;
        let delayed=false;
        window.fetch = (url, options) => {
            if (String(url).endsWith('/_video-follow-up')) {
                window.followRequests.push(JSON.parse(options.body));
                return Promise.resolve(new Response(JSON.stringify({summary:null, questions:[],edit_url:null}), {headers:{'Content-Type':'application/json'}}));
            }
            if (!delayed && String(url).includes('/video/chunks')) {
                delayed=true;
                return previous(url,options).then(response=>new Promise(resolve=>window.finishChunk=()=>resolve(response)));
            }
            return previous(url,options);
        };
    JS);
    startVideo($page);
    $page->script('new Promise(resolve=>setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')->assertVisible('.fh-video')
        ->click('button:has-text("Versturen")')->assertScript('typeof window.finishChunk', 'function')
        ->click('button[aria-label="Sluiten"]')->assertNotPresent('.fh-modal:visible');
    $page->script('window.finishChunk()');
    $page->assertScript('window.followRequests.length', 1)
        ->assertScript("window.toasts.includes('Verstuurd. Terug te vinden bij Mijn feedback.')", true)
        ->assertNotPresent('.fh-modal:visible');
    $page->assertScript('window.followRequests[0]', ['report' => FeedbackReport::query()->sole()->uuid]);
});

it('records the starting page before navigation and Back without any click', function (): void {
    $page = videoFeedbackPage();
    $page->script("document.title = 'Begin token=secret ' + 'x'.repeat(100); window.expectedStartTitle = ('Begin token=[filtered] ' + 'x'.repeat(100)).slice(0,60)");
    startVideo($page)->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.length", 1)
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity[0].t", 0)
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity[0].type", 'start')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity[0].selector", '')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity[0].path", '/_fhv/first/canvas')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity[0].label === window.expectedStartTitle", true);
    $page->script("history.pushState({}, '', '/_fhv/before-click?private=value'); history.back()");
    $page->assertScript('location.pathname', '/_fhv/first/canvas')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.map(e=>e.type)", ['start', 'navigate', 'navigate'])
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].activity.map(e=>e.path)", ['/_fhv/first/canvas', '/_fhv/before-click', '/_fhv/first/canvas']);
    // Let the canvas media fixture produce frames before the recording is stopped.
    $page->script('new Promise(resolve => setTimeout(resolve, 600))');
    $page->click('[data-feedback-hub-recording] button:has-text("Stop")')->assertVisible('.fh-video')
        ->fill('#feedback-hub-title', 'Startpagina')->click('button:has-text("Versturen")')
        ->assertNotPresent('.fh-modal:visible');
    $report = FeedbackReport::query()->sole();
    expect(array_column($report->activity, 'type'))->toBe(['start', 'navigate', 'navigate'])
        ->and($report->activity[0]['path'])->toBe('/_fhv/first/canvas')
        ->and($report->activity[0]['label'])->toHaveLength(60);
});
