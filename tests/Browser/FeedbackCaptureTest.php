<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Uteq\FeedbackHub\Jobs\ProcessFeedbackReportJob;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\BrowserTestLayout;
use Uteq\FeedbackHub\Tests\Support\User;

beforeEach(function (): void {
    Queue::fake();
    config()->set('ai.providers.anthropic.key', null);
    $user = User::query()->create(['name' => 'Browser Tester', 'email' => 'browser@example.test']);

    Route::get('/_fh/blank', fn () => '<!DOCTYPE html><html><body>Blank</body></html>');
    Route::get('/_fh/{mode}/feedback-inspector.js', fn () => response(
        file_get_contents(__DIR__.'/../../resources/js/feedback-inspector.js'),
        200, ['Content-Type' => 'text/javascript'],
    ));
    Route::get('/_fh/actual-snapdom.mjs', fn () => response(
        file_get_contents(__DIR__.'/../../resources/js/snapdom.mjs'),
        200, ['Content-Type' => 'text/javascript'],
    ));
    Route::get('/_fh/{mode}/snapdom.mjs', function (string $mode) {
        if ($mode === 'missing') {
            return response('Not found', 404, ['Content-Type' => 'text/javascript']);
        }
        if ($mode === 'hanging') {
            return response('await new Promise(() => {}); export const snapdom = {};', 200, ['Content-Type' => 'text/javascript']);
        }

        // Observe the real clone after the production redaction plugin has run.
        return response(<<<'JS'
            import { snapdom as realSnapdom } from '/_fh/actual-snapdom.mjs';
            export const snapdom = { toPng(root, options) {
                options.plugins.push({ name: 'test-clone-observer', afterClone({ clone }) {
                    window.cloneState = {
                        fields: [...clone.querySelectorAll('[data-private]')].map(n => ({ value: n.value, attribute: n.getAttribute('value'), text: n.tagName === 'TEXTAREA' ? n.textContent : '' })),
                        ordinary: clone.querySelector('[name=ordinary]').value,
                        widget: !!clone.querySelector('[data-feedback-hub-widget]'),
                        banner: !!clone.querySelector('#feedback-hub-instruction-banner'),
                        blocker: !!clone.querySelector('#feedback-hub-interaction-blocker'),
                        highlight: clone.querySelector('#feedback-hub-highlight-overlay')?.style.display,
                    };
                }});
                return realSnapdom.toPng(root, options);
            }};
            JS, 200, ['Content-Type' => 'text/javascript']);
    });
    Route::middleware('web')->get('/_fh/page/{mode?}', function (?string $mode = null) use ($user) {
        test()->actingAs($user);
        $module = $mode ? '<script type="module" src="/_fh/'.$mode.'/feedback-inspector.js"></script>' : '';

        return BrowserTestLayout::render(<<<HTML
            <script>
                window.toastEvents = []; window.mortelEvents = []; window.fluxCalls = []; window.displayMediaCalls = 0;
                Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getDisplayMedia() { window.displayMediaCalls++; return Promise.reject(new Error('Unexpected permission prompt')); } } });
                window.addEventListener('feedback-hub:toast', e => { window.toastEvents.push({ ...e.detail, cancelable: e.cancelable }); if (window.cancelToast) e.preventDefault(); });
                window.addEventListener('mortel-toast', e => window.mortelEvents.push(e.detail));
                window.addEventListener('click', e => { if (e.target.closest('#capture-target')) window.captureStart = performance.now(); }, true);
                window.addEventListener('feedback-hub-captured', async e => {
                    window.captureMs = performance.now() - window.captureStart;
                    const image = new Image(); image.src = e.detail.screenshot; await image.decode();
                    window.captured = { ...e.detail, width: image.naturalWidth, height: image.naturalHeight };
                });
            </script>
            <h1 id="capture-target">Een onderdeel om feedback over te geven</h1>
            <input data-private type="password" value="password-fixture">
            <input data-private name="api_token" value="token-fixture">
            <input data-private id="APIKEY" value="key-fixture">
            <input data-private name="passwd" value="passwd-fixture">
            <input data-private id="client_SECRET" value="secret-fixture">
            <input data-private name="csrf" value="csrf-fixture">
            <input data-private id="Authorization" value="authorization-fixture">
            <input data-private name="cookie" value="cookie-fixture">
            <input data-private id="api_key" value="api-key-fixture">
            <textarea data-private name="Password_note">textarea-fixture</textarea>
            <input name="ordinary" value="ordinary-fixture">
            <x-feedback-hub::floating-button />
            {$module}
            HTML);
    });
});

function feedbackPage(?string $mode = null): mixed
{
    $page = visit('/_fh/blank');
    $page->navigate('/_fh/page'.($mode ? '/'.$mode : ''));
    $page->assertScript("typeof window.Alpine !== 'undefined' && !!document.querySelector('[data-feedback-hub-widget]')._x_dataStack");

    return $page;
}

function submitBrowserFeedback(mixed $page): void
{
    $page->click('button[aria-label="Feedback geven"]')
        ->fill('#feedback-hub-title', 'Knop werkt niet')
        ->fill('#feedback-hub-description', 'De knop reageert niet.')
        ->click('button:has-text("Versturen")');
}

it('uses only Flux when the host provides it', function (): void {
    $page = feedbackPage();
    $page->script('window.Flux = { toast(detail) { window.fluxCalls.push(detail); } };');
    submitBrowserFeedback($page);
    $page->assertScript('window.fluxCalls.length === 1')
        ->assertScript('window.fluxCalls[0].variant', 'success')
        ->assertScript('window.toastEvents.length === 0 && window.mortelEvents.length === 0');
});

it('submits through the package and dispatches one compatible success toast', function (): void {
    $page = feedbackPage();
    submitBrowserFeedback($page);
    $page->assertScript('window.toastEvents.length === 1 && window.mortelEvents.length === 1')
        ->assertScript('window.toastEvents[0].cancelable')
        ->assertScript('window.mortelEvents[0].heading', 'Feedback verzonden')
        ->assertScript('window.mortelEvents[0].text.startsWith("Referentie: FH-")')
        ->assertScript('window.mortelEvents[0].variant', 'success')
        ->assertScript('window.toastEvents[0].text === window.mortelEvents[0].text');
    expect(FeedbackReport::query()->count())->toBe(1);
    Queue::assertPushed(ProcessFeedbackReportJob::class);
});

it('dispatches a nonempty danger toast after a failed submission', function (): void {
    Route::middleware('web')->post('/feedback-hub', fn () => response()->json(['message' => 'Opslaan mislukt'], 500));
    $page = feedbackPage();
    submitBrowserFeedback($page);
    $page->assertScript('window.toastEvents.length === 1 && window.mortelEvents.length === 1')
        ->assertScript('window.mortelEvents[0].variant', 'danger')
        ->assertScript('window.mortelEvents[0].text.length > 0');
});

it('lets the host cancel the Mortel toast after handling the package event', function (): void {
    $page = feedbackPage();
    $page->script('window.cancelToast = true;');
    submitBrowserFeedback($page);
    $page->assertScript('window.toastEvents.length === 1')
        ->assertScript('window.toastEvents[0].cancelable')
        ->assertScript('window.mortelEvents.length === 0');
});

it('uses the heading as required toast text when the body is empty', function (): void {
    $page = feedbackPage();
    $page->script("window.FeedbackHubWidget({}).notify('Klaar', '', 'success')");
    $page->assertScript('window.mortelEvents[0].text', 'Klaar');
});

it('captures a viewport PNG without a browser prompt and redacts only the clone', function (): void {
    $page = feedbackPage('normal');
    $page->assertScript('!!window.FeedbackHubInspector?.highlightOverlay');
    $page->script(<<<'JS'
        Object.defineProperty(window, 'devicePixelRatio', { configurable: true, get: () => 2 });
        document.querySelector('#capture-target').style.marginTop = '500px';
        const spacer = document.createElement('div');
        spacer.style.height = '3000px';
        document.body.appendChild(spacer);
        window.scrollTo(0, 400);
        JS);
    $page->assertScript('window.devicePixelRatio', 2)
        ->assertScript('window.scrollY', 400)
        ->assertScript('document.documentElement.scrollHeight > innerHeight + 2000');
    $page->click('button[aria-label="Feedback geven"]');
    $page->assertScript('window.scrollY', 400);
    $page->click('#capture-target');
    $page->assertScript('!!window.captured')
        ->assertScript('window.captured.screenshot.startsWith("data:image/png")')
        ->assertScript('window.captured.width === innerWidth && window.captured.height === innerHeight')
        ->assertScript('window.displayMediaCalls', 0)
        ->assertScript('window.cloneState.fields.length', 10)
        ->assertScript('window.cloneState.fields.every(n => n.value === "" && n.attribute === "" && n.text === "")')
        ->assertScript('window.cloneState.ordinary', 'ordinary-fixture')
        ->assertScript('!window.cloneState.widget && !window.cloneState.banner && !window.cloneState.blocker')
        ->assertScript('window.cloneState.highlight', 'block')
        ->assertScript('window.FeedbackHubInspector.highlightOverlay.style.display', 'none')
        ->assertScript('[...document.querySelectorAll("[data-private]")].every(n => n.value.endsWith("fixture"))');
    fwrite(STDOUT, "\nCapture click-to-event: ".$page->script('window.captureMs')." ms\n");
});

it('returns the placeholder when the screenshot module cannot load or never finishes loading', function (string $mode): void {
    $page = feedbackPage($mode);
    $page->assertScript('!!window.FeedbackHubInspector?.highlightOverlay');
    $page->script(<<<'JS'
        window.FeedbackHubInspector.screenshotTimeout = 50;
        window.placeholder = window.FeedbackHubInspector.createPlaceholderScreenshot();
        window.captureStart = performance.now();
        window.FeedbackHubInspector.selectedElement = document.querySelector('#capture-target');
        window.FeedbackHubInspector.captureAndOpen();
        JS);
    $page->assertScript('!!window.captured')
        ->assertScript('window.captured.screenshot === window.placeholder')
        ->assertScript('window.displayMediaCalls', 0)
        ->assertScript('window.FeedbackHubInspector.highlightOverlay.style.display', 'none');
})->with(['missing', 'hanging']);
