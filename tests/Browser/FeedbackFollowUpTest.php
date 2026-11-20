<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Uteq\FeedbackHub\Models\FeedbackReport;
use Uteq\FeedbackHub\Tests\Support\BrowserTestLayout;
use Uteq\FeedbackHub\Tests\Support\User;

beforeEach(function (): void {
    Queue::fake();
    config()->set('feedback-hub.ai.enabled', false);
    $user = User::query()->create(['name' => 'Tester', 'email' => 'follow-up@example.test']);
    Route::middleware('web')->get('/_follow/{mode?}', function (string $mode = 'questions') use ($user) {
        test()->actingAs($user);

        return BrowserTestLayout::render(<<<HTML
            <script>
                window.answers = []; window.followCalls = 0;
                const originalFetch = window.fetch;
                window.fetch = (url, options) => {
                    if (String(url).endsWith('/_follow-up/answers')) {
                        window.answers.push(JSON.parse(options.body));
                        return Promise.resolve(new Response('{}', {status: '{$mode}' === 'answer-error' ? 503 : 200}));
                    }
                    if (String(url).endsWith('/_follow-up')) {
                        window.followCalls++;
                        if ('{$mode}' === 'timeout') return new Promise(() => {});
                        if ('{$mode}' === 'error') return Promise.resolve(new Response('{}', {status: 503}));
                        return Promise.resolve(new Response(JSON.stringify({
                            summary: {title: 'Tijd bij de levering', saw: 'Alleen een datum', wants: 'Ook de tijd', where: 'Levering plannen'},
                            questions: '{$mode}' === 'none' ? [] : [
                                {id: 'who', topic: 'Wie', question: 'Wie moet dit zien?', hint: 'Kies wat past', options: ['Iedereen', 'Alleen ik', 'Mijn team']},
                                {id: 'when', topic: 'Wanneer', question: 'Welke tijd?', hint: null, options: ['Aankomst', 'Vertrek', 'Beide']}
                            ], edit_url: '/mijn-feedback'
                        }), {headers: {'Content-Type': 'application/json'}}));
                    }
                    return originalFetch(url, options);
                };
            </script>
            <style>body {font-family: ui-sans-serif, system-ui, sans-serif;}</style><h1>Levering plannen</h1>
            <x-feedback-hub::floating-button follow-up-url="/_follow-up" :follow-up-wait-ms="200" context-label="D-004 · Levering plannen" my-feedback-url="/mijn-feedback" :my-feedback-badge="2" />
            HTML);
    });
});

function followPage(string $mode = 'questions'): mixed
{
    $page = visit('/_follow/'.$mode);
    $page->assertScript("!!document.querySelector('[data-feedback-hub-widget]')._x_dataStack");
    $page->script("document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0].openModal()");

    return $page;
}

it('sends one field then shows two questions and the summary', function (): void {
    $page = followPage();
    $page->assertNotPresent('#feedback-hub-title')->assertNotPresent('.fh-types')
        ->fill('#feedback-hub-description', 'Hier moet ook de tijd bij')
        ->click('button:has-text("Versturen")')
        ->assertSee('1/2')->click('button:has-text("Iedereen")')
        ->assertSee('2/2')->click('button:has-text("Beide")')
        ->assertSee('Zo hebben we het begrepen')->assertSee('Ook de tijd')
        ->assertScript('window.answers.length', 2)->assertScript('window.answers[0].answer', 'Iedereen');
    expect(FeedbackReport::query()->firstOrFail()->title)->toBe('Hier moet ook de tijd bij');
    $page->click('button:has-text("Klaar")')->assertNotPresent('.fh-modal:visible');
});

it('allows another answer and skipping', function (): void {
    $page = followPage('answer-error');
    $page->fill('#feedback-hub-description', 'Andere tijd')->click('button:has-text("Versturen")')
        ->assertSee('1/2')->click('button:has-text("Iets anders")')->fill('input[aria-label="Je antwoord"]', 'De planner')
        ->click('button:has-text("Verstuur")')->assertSee('2/2')->click('button:has-text("Overslaan")')
        ->assertSee('Zo hebben we het begrepen')
        ->assertScript('window.answers[0].answer', 'De planner')
        ->assertScript('window.answers[1].skipped', true)
        ->assertScript('window.answers[1].answer', null);
});

it('finishes after a timeout or error and never asks the late questions', function (string $mode): void {
    followPage($mode)->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Heeft Uteq nog een vraag? Die komt in je inbox.')->assertVisible('button:has-text("Klaar")');
})->with(['timeout', 'error']);

it('keeps a draft and supports discarding with undo', function (): void {
    $page = followPage();
    $page->fill('#feedback-hub-description', 'Bewaar dit')->click('button[aria-label="Sluiten"]')
        ->click('button[aria-label="Feedback geven"]')->assertValue('#feedback-hub-description', 'Bewaar dit')
        ->click('button[aria-label="Weggooien"]')->assertSee('Ongedaan maken')
        ->click('button:has-text("Ongedaan maken")')->assertValue('#feedback-hub-description', 'Bewaar dit');
});

it('closes while waiting and announces that the report was sent', function (): void {
    $page = followPage('timeout');
    $page->script("window.toasts = []; window.addEventListener('feedback-hub:toast', e => window.toasts.push(e.detail.heading)); document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0].followUpWaitMs = 10000");
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Even kijken')->keys('button[aria-label="Sluiten"]', 'Escape')
        ->assertNotPresent('.fh-modal:visible')
        ->assertScript("window.toasts.includes('Verstuurd. Terug te vinden bij Mijn feedback.')", true);
});

it('shows the summary directly when there are no questions', function (): void {
    followPage('none')->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Zo hebben we het begrepen')->assertNotPresent('p:visible:has-text("Heeft Uteq")')
        ->assertScript("new URL(document.querySelector('a.fh-secondary').href).pathname", '/mijn-feedback');
});

// Opt-in artifact pass: renders actual component states without adding screenshot
// output or timing requirements to the normal suite.
if (getenv('FEEDBACK_SCREENSHOTS')) {
    it('captures the built follow-up window at both reference widths', function (): void {
        $page = followPage();
        $page->script(<<<'JS'
            (async () => {
                const canvas = document.createElement('canvas');
                canvas.width = 680; canvas.height = 220;
                const c = canvas.getContext('2d');
                c.fillStyle = '#f8fafc'; c.fillRect(0, 0, 680, 220);
                c.fillStyle = '#172026'; c.font = '22px sans-serif'; c.fillText('D-004 · Levering plannen', 30, 45);
                c.font = '16px sans-serif'; c.fillText('Leverdatum', 30, 100); c.fillText('vrijdag 18 december 2026', 30, 135);
                window.fixtureScreenshot = canvas.toDataURL();
                const stream = canvas.captureStream(15), chunks = [];
                const recorder = new MediaRecorder(stream);
                const stopped = new Promise(resolve => recorder.onstop = resolve);
                recorder.ondataavailable = e => chunks.push(e.data);
                recorder.start();
                await new Promise(resolve => setTimeout(resolve, 250));
                recorder.stop(); await stopped;
                window.fixtureVideoUrl = URL.createObjectURL(new Blob(chunks, {type: recorder.mimeType}));
                stream.getTracks().forEach(t => t.stop());
            })()
        JS);
        foreach (['wide' => 1440, 'narrow' => 390] as $size => $width) {
            $page->resize($width, $size === 'narrow' ? 844 : 900);
            foreach (['keuze', 'vertellen', 'vertellen-video', 'versturen-bezig', 'wachten-op-vragen', 'vraag-1', 'vraag-2', 'klaar', 'klaar-video', 'klaar-zonder-vragen', 'fout-versturen'] as $state) {
                $encoded = json_encode($state);
                $page->script(<<<JS
                    const w = document.querySelector('[data-feedback-hub-widget]')._x_dataStack[0];
                    w.reset(); w.showModal = true; w.description = 'Hier moet ook de tijd bij.';
                    w.followUpSummary = {title: 'Tijd bij de levering', saw: 'Alleen een datum', wants: 'Ook de tijd', where: 'D-004 · Levering plannen'};
                    w.followUpQuestions = [
                        {id:'who', question:'Wie moet die tijd zien?', hint:'Bij Leverdatum', options:['Alleen wij, de binnendienst','Ook de klant, in de bevestiging','De chauffeur, op zijn ritlijst']},
                        {id:'when', question:'Wat voor tijd wil je kiezen?', hint:'Bij Leverdatum', options:['Een blok van 2 uur, zoals 7 tot 9','Een vaste tijd, zoals 8:00','Ochtend of middag']}
                    ];
                    const state = {$encoded};
                    if (state === 'keuze') { w.showModal = false; w.showChoice = true; }
                    if (state.includes('video')) w.videoUrl = window.fixtureVideoUrl;
                    else if (['vertellen','versturen-bezig','fout-versturen'].includes(state)) w.screenshot = window.fixtureScreenshot;
                    if (state === 'versturen-bezig') w.submitting = true; else w.submitting = false;
                    if (state === 'wachten-op-vragen') { w.sentReport = 'fixture'; w.followUpState = 'waiting'; }
                    if (state.startsWith('vraag')) { w.sentReport = 'fixture'; w.followUpState = 'question'; w.questionIndex = state === 'vraag-1' ? 0 : 1; }
                    if (state.startsWith('klaar')) { w.sentReport = 'fixture'; w.followUpState = 'done'; w.editUrl = '/mijn-feedback'; w.followUpLate = state === 'klaar-zonder-vragen'; }
                    if (state === 'fout-versturen') w.error = 'Niet verstuurd. Je tekst staat er nog.';

                JS);
                $page->script('new Promise(resolve => setTimeout(resolve, 300))');
                $page->screenshot(false, "build-t1-{$state}-{$size}");
            }
        }
    });
}
