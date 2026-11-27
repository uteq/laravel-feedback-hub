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

        $context = $mode === 'second' ? 'D-005 · Ophalen plannen' : 'D-004 · Levering plannen';
        $badge = $mode === 'second' ? 5 : 2;
        $mine = $mode === 'second' ? '/mijn-feedback-nieuw' : '/mijn-feedback';
        $hostProps = $mode === 'host-props' ? <<<'BLADE'
            submit-label="Discuss with AI" :notice="['title'=>'Our team receives this.', 'text'=>'AI reviews it first.']" :status="['state'=>'busy','label'=>'Reviewing','items'=>[['id'=>'one','title'=>'Delivery','line'=>'Reviewing','state'=>'busy']]]"
            BLADE : '';
        if ($mode === 'mobile-host') {
            $hostProps = 'mobile-trigger="host" mobile-anchor="h1"';
        }
        $html = BrowserTestLayout::render(<<<HTML
            <script>
                window.answers = []; window.followCalls = 0; window.followBodies = []; window.followHeaders = []; window.csrfRefreshes = 0;
                const originalFetch = window.fetch;
                window.fetch = (url, options) => {
                    if (String(url).endsWith('/feedback-hub/session/csrf')) {
                        window.csrfRefreshes++;
                        const response = new Response(JSON.stringify({token: 'fresh-csrf-fixture'}), {headers: {'Content-Type': 'application/json'}});
                        return Promise.resolve(response);
                    }
                    if (String(url).endsWith('/_follow-up/answers')) {
                        window.answers.push(JSON.parse(options.body));
                        return Promise.resolve(new Response('{}', {status: '{$mode}' === 'answer-error' ? 503 : 200}));
                    }
                    if (String(url).endsWith('/_follow-up')) {
                        window.followCalls++;
                        window.followBodies.push(JSON.parse(options.body)); window.followHeaders.push(options.headers);
                        if ('{$mode}' === 'csrf' && window.followCalls === 1) return Promise.resolve(new Response('{}', {status:419}));
                        if ('{$mode}' === 'timeout') return new Promise(() => {});
                        if ('{$mode}' === 'error') return Promise.resolve(new Response('{}', {status: 503}));
                        if (['accepted', 'accepted-summary', 'accepted-status', 'later-summary', 'later-error'].includes('{$mode}')) {
                            return Promise.resolve(new Response(JSON.stringify({
                                later: '{$mode}' !== 'accepted-status',
                                summary: '{$mode}'.includes('summary') ? {title:'Later bekeken',saw:'Een datum',wants:'Ook de tijd',where:'Levering plannen'} : null,
                                questions: [{id:'ignored',question:'Niet nu tonen',options:['A','B','C']}]
                            }), {status: '{$mode}'.startsWith('accepted') ? 202 : ('{$mode}' === 'later-error' ? 503 : 200), headers:{'Content-Type':'application/json'}}));
                        }
                        const response = new Response(JSON.stringify({
                            summary: {title: 'Tijd bij de levering', saw: 'Alleen een datum', wants: 'Ook de tijd', where: 'Levering plannen'},
                            questions: '{$mode}' === 'none' ? [] : [
                                {id: 'who', topic: 'Wie', question: 'Wie moet dit zien?', hint: 'Kies wat past', options: ['Iedereen', 'Alleen ik', 'Mijn team']},
                                {id: 'when', topic: 'Wanneer', question: 'Welke tijd?', hint: null, options: ['Aankomst', 'Vertrek', 'Beide']}
                            ], edit_url: '/mijn-feedback'
                        }), {headers: {'Content-Type': 'application/json'}});
                        if ('{$mode}' === 'late') return new Promise(resolve => window.deliverLate = () => resolve(response));
                        return Promise.resolve(response);
                    }
                    return originalFetch(url, options);
                };
            </script>
            <style>body {font-family: ui-sans-serif, system-ui, sans-serif;}</style><h1>Levering plannen</h1>
            <a href="/_follow/second" wire:navigate>Andere pagina</a>
            <label for="delivery-date">Leverdatum</label><input id="delivery-date" value="private-field-value">
            <x-feedback-hub::floating-button follow-up-url="/_follow-up" :follow-up-wait-ms="200" context-label="{$context}" {$hostProps} my-feedback-url="{$mine}" :my-feedback-badge="{$badge}" />
            HTML);

        return str_replace('<title>Feedback test</title>', '<title>Pagina '.$mode.'</title>', $html);
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
    $report = FeedbackReport::query()->firstOrFail();
    expect($report->title)->toBe('Hier moet ook de tijd bij');
    $page->assertScript('window.followBodies[0]', ['report' => $report->uuid])
        ->assertScript('window.answers[0]', ['report' => $report->uuid, 'question_id' => 'who', 'answer' => 'Iedereen', 'skipped' => false])
        ->assertScript('window.answers[1]', ['report' => $report->uuid, 'question_id' => 'when', 'answer' => 'Beide', 'skipped' => false]);
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
        ->click('button[aria-label="Feedback geven"]')->click('button:has-text("Verder met je tekst")')->assertValue('#feedback-hub-description', 'Bewaar dit')
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

it('keeps the original page context on a draft across Livewire navigation and still offers the menu', function (): void {
    $page = followPage();
    $page->script("const w = document.querySelector('.fh-widget')._x_dataStack[0]; w.elementSelector = '#delivery-date'; w.capturePageContext()");
    $page->fill('#feedback-hub-description', "Een\n  vaste tijd")
        ->click('button[aria-label="Sluiten"]')->click('a:has-text("Andere pagina")')
        ->assertSee('Andere pagina')->assertScript('location.pathname', '/_follow/second')
        ->click('button[aria-label="Feedback geven"]')
        ->assertVisible('[data-feedback-hub-choice]')->assertSee('Mijn feedback')->assertSee('Verder met je tekst')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].myFeedbackBadge", 5)
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].myFeedbackUrl", '/mijn-feedback-nieuw')
        ->click('button:has-text("Verder met je tekst")')->assertValue('#feedback-hub-description', "Een\n  vaste tijd")
        ->assertSee('D-004 · Levering plannen · Leverdatum')->click('button:has-text("Versturen")')
        ->assertSee('1/2');
    $report = FeedbackReport::query()->sole();
    expect(parse_url($report->page_url, PHP_URL_PATH))->toBe('/_follow/questions')
        ->and($report->session_data['title'])->toBe('Pagina questions')
        ->and($report->title)->toBe('Een vaste tijd');
});

it('updates the current-page props through the window event', function (): void {
    $page = followPage();
    $page->click('button[aria-label="Weggooien"]');
    $page->script("window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{contextLabel:'Nieuwe pagina',myFeedbackBadge:7,myFeedbackUrl:'/ander-overzicht'}}))");
    $page->click('button[aria-label="Feedback geven"]')->assertVisible('[data-feedback-hub-choice]')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].myFeedbackBadge", 7)
        ->assertAttribute('a:has-text("Mijn feedback")', 'href', '/ander-overzicht');
    $page->click('button:has-text("Schermafbeelding")')->assertSee('Gaat mee: Nieuwe pagina');
});

it('ignores a late follow-up response after the timeout screen', function (): void {
    $page = followPage('late');
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Heeft Uteq nog een vraag? Die komt in je inbox.');
    $page->script('window.deliverLate(); new Promise(resolve => setTimeout(resolve, 50))');
    $page->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].followUpState", 'done')
        ->assertNotPresent('.fh-answer:visible')->assertSee('Heeft Uteq nog een vraag? Die komt in je inbox.');
});

it('retries a follow-up 419 with a refreshed same-origin CSRF token', function (): void {
    $page = followPage('csrf');
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')->assertSee('1/2')
        ->assertScript('window.followCalls', 2)->assertScript('window.csrfRefreshes', 1)
        ->assertScript("window.followHeaders[1]['X-CSRF-TOKEN']", 'fresh-csrf-fixture');
    $report = FeedbackReport::query()->sole();
    $page->assertScript('window.followBodies[0]', ['report' => $report->uuid])
        ->assertScript('window.followBodies[1]', ['report' => $report->uuid]);
});

it('closes a question with Escape without answering or discarding the sent report', function (): void {
    $page = followPage();
    $page->script("window.toasts=[]; window.addEventListener('feedback-hub:toast', e=>window.toasts.push(e.detail.heading))");
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')->assertSee('1/2')
        ->keys('button[aria-label="Sluiten"]', 'Escape')->assertNotPresent('.fh-modal:visible')
        ->assertScript('window.answers.length', 0)
        ->assertScript("window.toasts.includes('Verstuurd. Terug te vinden bij Mijn feedback.')", true);
    expect(FeedbackReport::query()->count())->toBe(1);
});

it('rejects external follow-up endpoints before sending any CSRF token', function (): void {
    $page = followPage();
    $page->script("window.externalCalls=0; const prev=window.fetch; window.fetch=(url, opts)=>{if(String(url).includes('external.example')) window.externalCalls++; return prev(url,opts)}; document.querySelector('.fh-widget')._x_dataStack[0].followUpUrl='https://external.example/follow-up'");
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Heeft Uteq nog een vraag? Die komt in je inbox.')->assertScript('window.externalCalls', 0);
});

it('announces a delayed successful submission after closing and still requests follow-up', function (string $close): void {
    $page = followPage();
    $page->script(<<<'JS'
        window.toasts=[];
        window.addEventListener('feedback-hub:toast', e=>window.toasts.push(e.detail.heading));
        const prev=window.fetch;
        window.fetch=(url,opts)=>String(url).endsWith('/feedback-hub') && opts?.method==='POST'
            ? prev(url,opts).then(response=>new Promise(resolve=>window.finishSending=()=>resolve(response)))
            : prev(url,opts);
    JS);
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertScript('typeof window.finishSending', 'function');
    if ($close === 'escape') {
        $page->keys('button[aria-label="Sluiten"]', 'Escape');
    } elseif ($close === 'backdrop') {
        $page->script("document.querySelector('.fh-backdrop').dispatchEvent(new MouseEvent('click', {bubbles:true}))");
    } else {
        $page->click('button[aria-label="Sluiten"]');
    }
    $page->assertNotPresent('.fh-modal:visible');
    $page->script('window.finishSending()');
    $page->assertScript('window.followCalls', 1)
        ->assertScript("window.toasts.includes('Verstuurd. Terug te vinden bij Mijn feedback.')", true)
        ->assertNotPresent('.fh-modal:visible');
    expect(FeedbackReport::query()->count())->toBe(1);
})->with(['escape', 'cross', 'backdrop']);

it('treats accepted or explicitly deferred follow-up as a normal inbox completion', function (string $mode, bool $summary): void {
    $page = followPage($mode);
    $page->script("window.dangerToasts=0; window.addEventListener('feedback-hub:toast', e=>{if(e.detail.variant==='danger') window.dangerToasts++})");
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertSee('Heeft Uteq nog een vraag? Die komt in je inbox.')
        ->assertVisible('button:has-text("Klaar")')->assertNotPresent('.fh-answer:visible')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].followUpState", 'done')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].error", '')
        ->assertScript('window.dangerToasts', 0);
    if ($summary) {
        $page->assertSee('Zo hebben we het begrepen')->assertSee('Later bekeken');
    } else {
        $page->assertNotPresent('.fh-summary:visible');
    }
})->with([
    ['accepted', false],
    ['accepted-summary', true],
    ['accepted-status', false],
    ['later-summary', true],
    ['later-error', false],
]);

// Opt-in artifact pass: renders actual component states without adding screenshot
// output or timing requirements to the normal suite.
if (getenv('FEEDBACK_SCREENSHOTS')) {
    it('captures the built follow-up window at both reference widths', function (): void {
        $page = followPage();
        $page->script(<<<'JS'
            (async () => {
                document.querySelector('a')?.style.setProperty('display', 'none');
                document.querySelector('label[for="delivery-date"]').style.display = 'none';
                document.querySelector('#delivery-date').style.display = 'none';
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
                    if (state === 'keuze') { w.description = ''; w.showModal = false; w.showChoice = true; }
                    w.pageContext = {url:location.href, title:document.title, label:'D-004 · Levering plannen · Leverdatum', viewport:{width:680,height:220}};
                    w.elementRect = {x:20,y:75,width:310,height:76};
                    if (state.includes('video')) { w.videoUrl = window.fixtureVideoUrl; w.sentVideo = state === 'klaar-video'; }
                    else if (['vertellen','versturen-bezig','fout-versturen'].includes(state)) w.screenshot = window.fixtureScreenshot;
                    if (state === 'versturen-bezig') w.submitting = true; else w.submitting = false;
                    if (state === 'wachten-op-vragen') { w.sentReport = 'fixture'; w.followUpState = 'waiting'; }
                    if (state.startsWith('vraag')) { w.sentReport = 'fixture'; w.followUpState = 'question'; w.questionIndex = state === 'vraag-1' ? 0 : 1; }
                    if (state.startsWith('klaar')) { w.sentReport = 'fixture'; w.followUpState = 'done'; w.editUrl = '/mijn-feedback'; w.followUpLate = state === 'klaar-zonder-vragen'; }
                    if (state === 'fout-versturen') w.error = 'Niet verstuurd. Je tekst staat er nog.';

                JS);
                $page->script('new Promise(resolve => setTimeout(resolve, 300))');
                if (in_array($state, ['vertellen', 'vertellen-video', 'fout-versturen'], true)) {
                    $page->script("document.querySelector('#feedback-hub-description').focus({preventScroll:true})");
                    $page->assertScript('document.activeElement.id', 'feedback-hub-description');
                }
                if (in_array($state, ['vertellen', 'versturen-bezig', 'fout-versturen'], true)) {
                    $page->assertScript("document.querySelector('.fh-shot-outline').getBoundingClientRect().width > 20", true);
                }
                if ($state === 'vraag-2') {
                    $page->assertSee('2/2')->assertSee('Wat voor tijd wil je kiezen?');
                }
                if ($state === 'klaar-video') {
                    $page->assertSee('We bekijken je video nog. Vragen komen in je inbox.');
                }
                $page->screenshot(false, "build-t1-{$state}-{$size}");
            }
        }
    });
}

it('lets the user re-point and remove a screenshot while retaining the text', function (): void {
    $page = followPage();
    $page->fill('#feedback-hub-description', 'Dit moet anders');
    $page->script(<<<'JS'
        const w = document.querySelector('.fh-widget')._x_dataStack[0];
        const canvas = document.createElement('canvas'); canvas.width=innerWidth; canvas.height=innerHeight;
        window.fixtureShot=canvas.toDataURL();
        window.FeedbackHubInspector = {activate() {
            window.dispatchEvent(new CustomEvent('feedback-hub-captured', {detail:{
                screenshot:window.fixtureShot,elementSelector:'#delivery-date',elementRect:{x:20,y:60,width:160,height:40}
            }}));
        }};
        w.repoint();
    JS);
    $page->assertVisible('.fh-shot-outline')->assertVisible('button[aria-label="Opnieuw aanwijzen"]')
        ->assertSee('D-004 · Levering plannen · Leverdatum')
        ->click('button[aria-label="Opnieuw aanwijzen"]')->assertVisible('.fh-shot-outline')
        ->assertValue('#feedback-hub-description', 'Dit moet anders')
        ->click('button[aria-label="Schermafbeelding verwijderen"]')
        ->assertNotPresent('.fh-shot-frame:visible')
        ->assertValue('#feedback-hub-description', 'Dit moet anders');
});

it('hands over a saved report immediately with its UUID and unchanged session payload', function (): void {
    $page = followPage();
    $page->script(<<<'JS'
        window.submitted = [];
        window.addEventListener('feedback-hub:submitted', e => { e.preventDefault(); window.submitted.push(e.detail); });
        const w = document.querySelector('.fh-widget')._x_dataStack[0];
        w.contextLabel = 'Delivery planning'; w.capturePageContext();
        w.sessionData = { title: 'Original title', viewport: {width: 800, height: 600}, marker: 'unchanged' };
    JS);
    $page->fill('#feedback-hub-description', 'Tijd ontbreekt')->click('button:has-text("Versturen")')
        ->assertNotPresent('.fh-modal:visible')->assertScript('window.followCalls', 0)
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].followUpState", null);
    $report = FeedbackReport::query()->sole();
    $page->assertScript('window.submitted', [['reportId' => $report->uuid]]);
    expect($report->session_data)->toBe(['title' => 'Original title', 'viewport' => ['width' => 800, 'height' => 600], 'marker' => 'unchanged']);
    $page->click('button[aria-label="Feedback geven"]')->click('button:has-text("Schermafbeelding")')
        ->assertValue('#feedback-hub-description', '')->assertSee('Versturen');
});

it('renders host status safely and dispatches open without opening the widget', function (): void {
    $page = followPage();
    $page->script(<<<'JS'
        document.querySelector('.fh-widget')._x_dataStack[0].close();
        window.opened=[]; window.addEventListener('feedback-hub:open', e=>window.opened.push(e.detail));
        window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{status:{state:'busy',label:'AI kijkt mee',items:[{id:'report-1',title:'<img src=x onerror=alert(1)>',line:'Bezig',state:'busy'}]}}}));
    JS);
    $page->assertVisible('.fh-button.busy')->assertVisible('.fh-pill .fh-spinner')
        ->click('.fh-pill')->assertScript('window.opened', [['id' => 'report-1']])
        ->assertNotPresent('.fh-modal:visible')->click('.fh-button')->assertVisible('.fh-pend .fh-spinner')
        ->assertSee('<img src=x onerror=alert(1)>')->assertNotPresent('.fh-pend img')
        ->click('.fh-pend')->assertScript('window.opened.length', 2);
    $page->script(<<<'JS'
        window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{myFeedbackBadge:3,status:{state:'attention',label:'1 vraag over je feedback',items:[{id:'report-1',title:'Tijd bij levering',line:'1 vraag voor jou',state:'attention'}]}}}));
    JS);
    $page->assertNotPresent('.fh-button.busy')->assertVisible('.fh-pend.att')
        ->assertScript("document.querySelector('.fh-button .fh-badge').textContent", '3');
    $page->script("document.querySelector('.fh-widget')._x_dataStack[0].showChoice=false");
    $page->assertVisible('.fh-pill.att');
    $page->script("window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{hidden:true}}))");
    $page->assertNotPresent('.fh-button:visible')->assertNotPresent('.fh-pill:visible')->assertNotPresent('.fh-choice:visible');
    $page->script("window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{hidden:false,status:null}}))");
    $page->assertVisible('.fh-button')->assertNotPresent('.fh-pill:visible');
});

it('refreshes host text and status on navigation and renders text rather than markup', function (): void {
    $page = followPage();
    $page->script(<<<'JS'
        document.querySelector('script[data-feedback-hub-props]').textContent=JSON.stringify({submitLabel:'<b>Discuss</b>',notice:{title:'<img src=x>',text:'<script>bad()</script>'},status:{state:'busy',label:'Thinking',items:[{id:'one',title:'One',line:'Thinking',state:'busy'}]}});
        document.dispatchEvent(new Event('livewire:navigated'));
    JS);
    $page->assertSee('<b>Discuss</b>')->assertSee('<img src=x>')->assertSee('<script>bad()</script>')
        ->assertNotPresent('.fh-uteq img')->assertNotPresent('.fh-uteq script')->assertNotPresent('.fh-primary b');
    $page->script("document.querySelector('.fh-widget')._x_dataStack[0].close()");
    $page->assertVisible('.fh-button.busy')->assertSee('Thinking');
});

it('renders the host initial props before any update or navigation event', function (): void {
    $page = visit('/_follow/host-props');
    $page->assertVisible('.fh-button.busy')->assertSee('Reviewing')->assertVisible('.fh-pill .fh-spinner');
    $page->click('.fh-button')->click('button:has-text("Schermafbeelding")')
        ->assertSee('Discuss with AI')->assertSee('Our team receives this.')->assertSee('AI reviews it first.');
});

it('prepares new screenshot and video choices with status present and clears undo', function (string $choice): void {
    $page = followPage();
    $page->fill('#feedback-hub-description', 'Discarded draft')->click('button[aria-label="Weggooien"]')->assertSee('Ongedaan maken');
    $page->script(<<<'JS'
        const w=document.querySelector('.fh-widget')._x_dataStack[0];
        w.followUpUrl=null; w.chatEnabled=true;
        w.status={state:'busy',items:[{id:'one',title:'One',line:'Reviewing',state:'busy'}]};
        w.canRecordVideo=()=>true;
        w.startRecording=() => { window.recordingPrepared={messages:w.chatMessages,undo:w.undoDraft}; w.openModal(); };
        true;
    JS);
    $page->click('.fh-button')->assertNotPresent('.fh-draft-toast:visible')->click('button:has-text("'.$choice.'")')
        ->assertSee('Waar liep je tegenaan?')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].description", '')
        ->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].undoDraft", null);
    $page->script("document.querySelector('.fh-widget')._x_dataStack[0].restoreDraft()");
    $page->assertScript("document.querySelector('.fh-widget')._x_dataStack[0].description", '');
})->with(['Schermafbeelding', 'Video opnemen']);

it('keeps five status rows scrollable in a short phone menu', function (): void {
    $page = followPage();
    $page->resize(390, 320);
    $page->script(<<<'JS'
        const w=document.querySelector('.fh-widget')._x_dataStack[0];w.close();
        w.status={state:'busy',items:Array.from({length:5},(_,i)=>({id:i,title:'Report '+i,line:'Reviewing',state:'busy'}))};
    JS);
    $page->click('.fh-button')->assertVisible('.fh-choice');
    $page->assertScript(<<<'JS'
        (()=>{const m=document.querySelector('.fh-choice'),r=m.getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight && m.scrollHeight>m.clientHeight && getComputedStyle(m).overflowY==='auto';})()
        JS, true);
    $page->click('a:has-text("Mijn feedback")')->assertScript('location.pathname', '/mijn-feedback');
});

it('renders structured pill emphasis as escaped text', function (): void {
    $page = followPage();
    $page->script(<<<'JS'
        const w=document.querySelector('.fh-widget')._x_dataStack[0];w.close();
        w.updateProps({status:{state:'attention',label:{strong:'<b>1 vraag</b>',text:'<img src=x>'},items:[{id:'one',title:'One',state:'attention'}]}});
    JS);
    $page->assertSee('<b>1 vraag</b>')->assertSee('<img src=x>')->assertNotPresent('.fh-pill b')->assertNotPresent('.fh-pill img')
        ->assertScript("getComputedStyle(document.querySelector('.fh-pill strong')).color", 'rgb(194, 65, 12)');
});

it('truncates long status rows and pills without horizontal overflow', function () {
    $page = visit('/_follow');
    foreach (['Moet ik deze feedback nog oppakken? '.str_repeat('lange titel ', 15), str_repeat('W', 300)] as $title) {
        $encoded = json_encode($title);
        $page->script("window.dispatchEvent(new CustomEvent('feedback-hub:update', {detail:{status:{state:'attention',label:$encoded,items:[{id:'long',title:$encoded,line:$encoded,state:'attention'}]}}}))");
        $page->assertVisible('.fh-pill')->assertScript("document.querySelector('.fh-pill').scrollWidth <= document.querySelector('.fh-pill').clientWidth", true);
        $page->click('.fh-button')->assertVisible('.fh-pend')
            ->assertScript("document.querySelector('.fh-choice').scrollWidth <= document.querySelector('.fh-choice').clientWidth", true)
            ->assertScript("['.tt','.sub'].every(s => { const e=document.querySelector('.fh-pend '+s); const c=getComputedStyle(e); return c.textOverflow==='ellipsis' && c.whiteSpace==='nowrap' && e.scrollWidth>e.clientWidth; })", true)
            ->assertScript("document.querySelector('.fh-pend .tt').title", $title)
            ->assertScript("document.querySelector('.fh-pend .pch').getBoundingClientRect().right <= document.querySelector('.fh-choice').getBoundingClientRect().right", true);
        $page->script("document.querySelector('.fh-widget')._x_dataStack[0].showChoice=false");
    }
});

it('lets a host open its phone menu below its anchor while keeping the desktop trigger', function (): void {
    $page = visit('/_follow/mobile-host');
    $page->resize(390, 844);
    $page->assertNotPresent('.fh-button:visible')->assertNotPresent('.fh-pill:visible');
    $page->script("window.dispatchEvent(new CustomEvent('open-feedback-hub'))");
    $page->assertVisible('.fh-choice')->assertSee('Schermafbeelding')
        ->assertScript("Math.abs(document.querySelector('.fh-choice').getBoundingClientRect().top - document.querySelector('h1').getBoundingClientRect().bottom - 8) < 1");
    $page->resize(1440, 900);
    $page->assertVisible('.fh-button')->assertScript("getComputedStyle(document.querySelector('.fh-choice')).bottom", '92px');
});

it('retains the floating phone trigger when the host option is absent', function (): void {
    $page = visit('/_follow/host-props');
    $page->resize(390, 844);
    $page->assertVisible('.fh-button')->assertVisible('.fh-pill');
});
