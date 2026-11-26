@props(['floating' => true, 'followUpUrl' => null, 'followUpWaitMs' => 3000, 'contextLabel' => null, 'myFeedbackUrl' => null, 'myFeedbackBadge' => 0, 'submitLabel' => null, 'notice' => null, 'status' => null])

@php($chatEnabled = \Uteq\FeedbackHub\Support\FeedbackAiAvailability::available())
@php($video = [
    'enabled' => (bool) config('feedback-hub.video.enabled', true),
    'chunkEndpoint' => route('feedback-hub.video-chunk'),
    'chunkBytes' => max(1, (int) config('feedback-hub.video.chunk_kilobytes', 512)) * 1024,
    'maxSeconds' => max(1, (int) config('feedback-hub.video.max_seconds', 120)),
])

@auth
    @once
        <style>
            [x-cloak] { display: none !important; }
            .fh-button { position: fixed; right: 24px; bottom: 24px; z-index: 50; display: inline-flex; width: 56px; height: 56px; align-items: center; justify-content: center; border: 0; border-radius: 999px; background: #135d66; color: white; box-shadow: 0 16px 34px rgba(19, 93, 102, .26); cursor: pointer; transition: transform .16s ease, background .16s ease, box-shadow .16s ease; }
            .fh-button:hover { transform: translateY(-1px) scale(1.03); background: #0f4d55; box-shadow: 0 18px 40px rgba(19, 93, 102, .32); }
            .fh-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 20px; background: rgba(15, 23, 42, .52); }
            .fh-modal { display: flex; flex-direction: column; width: min(100%, 680px); max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); overflow: hidden; border: 1px solid rgba(15, 23, 42, .12); border-radius: 8px; background: white; color: #172026; box-shadow: 0 28px 80px rgba(15, 23, 42, .28); }
            .fh-header, .fh-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; border-bottom: 1px solid #e7eaee; }
            .fh-footer { justify-content: flex-end; border-top: 1px solid #e7eaee; border-bottom: 0; }
            .fh-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 18px; }
            .fh-title { margin: 0; font-size: 18px; line-height: 1.3; font-weight: 750; }
            .fh-label { display: block; margin-bottom: 7px; color: #334155; font-size: 13px; font-weight: 650; }
            .fh-field { margin-bottom: 16px; }
            .fh-input, .fh-textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; background: white; padding: 10px 12px; color: #172026; font: inherit; outline: none; }
            .fh-input:focus, .fh-textarea:focus { border-color: #135d66; box-shadow: 0 0 0 3px rgba(19, 93, 102, .14); }
            .fh-types { display: flex; flex-wrap: wrap; gap: 8px; }
            .fh-type { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #d7dde5; border-radius: 8px; background: #f8fafc; color: #334155; padding: 9px 12px; font: inherit; cursor: pointer; }
            .fh-type.is-active { border-color: #135d66; background: #edf7f8; color: #0f4d55; }
            .fh-secondary, .fh-primary, .fh-icon { border: 0; border-radius: 8px; padding: 10px 14px; font: inherit; font-weight: 700; cursor: pointer; }
            .fh-secondary { background: transparent; color: #475569; }
            .fh-primary { background: #135d66; color: white; }
            .fh-primary:disabled { cursor: not-allowed; opacity: .55; }
            .fh-icon { display: inline-flex; width: 36px; height: 36px; align-items: center; justify-content: center; padding: 0; background: transparent; color: #64748b; }
            .fh-shot { max-height: 220px; width: 100%; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
            .fh-code { display: block; overflow-wrap: anywhere; border-radius: 8px; background: #f8fafc; padding: 10px; color: #475569; font-size: 12px; }
            .fh-chat { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
            .fh-bubble { align-self: flex-start; max-width: 85%; box-sizing: border-box; white-space: pre-wrap; overflow-wrap: anywhere; border-radius: 8px; background: #f1f5f9; padding: 9px 12px; color: #172026; font-size: 14px; line-height: 1.45; }
            .fh-bubble.is-user { align-self: flex-end; background: #135d66; color: white; }
            .fh-bubble.is-busy { color: #64748b; font-style: italic; }
            .fh-chat-form { display: flex; align-items: center; gap: 8px; }
            .fh-chat-form .fh-input { flex: 1 1 auto; min-width: 0; }
            .fh-record-dot { width: 10px; height: 10px; border-radius: 999px; background: #dc2626; }
            .fh-video { display: block; max-height: 260px; width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; background: #0f172a; }
            .fh-video-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
            .fh-choice { position: fixed; right: 24px; bottom: 92px; max-height: calc(100vh - 110px); max-height: calc(100dvh - 110px); overflow-y: auto; z-index: 55; display: flex; flex-direction: column; gap: 4px; width: 260px; border: 1px solid rgba(15, 23, 42, .12); border-radius: 12px; background: white; padding: 6px; box-shadow: 0 20px 48px rgba(15, 23, 42, .2); }
            .fh-choice-option { display: flex; align-items: flex-start; gap: 12px; border: 0; border-radius: 8px; background: transparent; padding: 10px 12px; color: #172026; text-align: left; font: inherit; cursor: pointer; }
            .fh-choice-option:hover, .fh-choice-option:focus-visible { background: #edf7f8; outline: none; }
            .fh-choice-option svg { flex: none; margin-top: 2px; color: #135d66; }
            .fh-choice-title { display: block; font-size: 14px; font-weight: 700; }
            .fh-choice-text { display: block; margin-top: 2px; color: #64748b; font-size: 13px; line-height: 1.35; }
            .fh-recording { position: fixed; left: 50%; bottom: 24px; z-index: 70; display: flex; align-items: center; gap: 12px; transform: translateX(-50%); border-radius: 999px; background: #172026; color: white; padding: 8px 8px 8px 16px; box-shadow: 0 16px 34px rgba(15, 23, 42, .3); font-size: 14px; font-variant-numeric: tabular-nums; }
            .fh-recording .fh-record-dot { animation: fh-pulse 1.2s ease-in-out infinite; }
            .fh-stop { border: 0; border-radius: 999px; background: white; color: #172026; padding: 7px 14px; font: inherit; font-weight: 700; cursor: pointer; }
            @keyframes fh-pulse { 50% { opacity: .35; } }
            .fh-error { margin: 0; color: #b91c1c; font-size: 13px; line-height: 1.4; }
            .fh-shot-frame { position:relative; }
            .fh-shot-frame .fh-shot { display:block; box-sizing:border-box; }
            .fh-shot-outline { position:absolute; border:2px solid #135d66; background:rgba(19,93,102,.08); box-sizing:border-box; pointer-events:none; }
            .fh-shot-actions { display:flex; gap:4px; position:absolute; top:8px; right:8px; }
            .fh-shot-actions .fh-icon { width:28px; height:28px; background:white; border:1px solid #e2e8f0; border-radius:6px; box-shadow:0 1px 3px #0001; }
            .fh-context, .fh-wait, .fh-with-icon { display:flex; gap:6px; align-items:center; }
            .fh-context-label { white-space:nowrap; flex:none; }
            .fh-context svg, .fh-wait svg, .fh-wait .fh-spinner { flex:none; }
            .fh-alert { display:flex; align-items:center; gap:8px; margin-bottom:16px; padding:10px 12px; border-radius:8px; background:#fef2f2; color:#b91c1c; font-size:13px; }
            .fh-alert svg { flex:none; }
            .fh-spinner { display:inline-block; width:14px; height:14px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:fh-spin .8s linear infinite; flex:none; }
            @keyframes fh-spin { to { transform:rotate(360deg); } }
            .fh-dots { display:inline-flex; gap:3px; margin-right:4px; }
            .fh-dots i { width:4px; height:4px; border-radius:50%; background:#94a3b8; animation:fh-pulse 1.2s infinite; }
            .fh-dots i:nth-child(2) { animation-delay:.2s; } .fh-dots i:nth-child(3) { animation-delay:.4s; }
            .fh-draft-toast { position:fixed; right:24px; bottom:92px; z-index:75; display:flex; gap:12px; align-items:center; border:1px solid #e7eaee; border-radius:8px; padding:12px; background:white; box-shadow:0 16px 34px rgba(15,23,42,.2); }
            .fh-progress { height:4px; background:#e2e8f0; border-radius:999px; overflow:hidden; margin-top:10px; }
            .fh-progress span { display:block; height:100%; background:#135d66; }
            .fh-muted { color: #64748b; font-size: 13px; line-height: 1.5; }
            .fh-confirm { display:flex; gap:12px; align-items:center; }
            .fh-sent { display:flex; gap:8px; align-items:center; font-weight:650; color: #15803d; font-size: 14px; margin: 0 0 18px; }
            .fh-answer { display: flex; justify-content: space-between; width: 100%; text-align: left; margin: 8px 0; padding: 12px 14px; border-color: #d7dde5; border-radius: 8px; background: white; min-height:46px; font-size: 14px; }
            .fh-qsub { color:#64748b; font-size:13px; margin:0 0 14px; }
            .fh-q { font-size:18px; font-weight:650; margin:0 0 4px; line-height:1.35; }
            .fh-answer.is-active { border-color:#135d66; background:#edf7f8; color:#0f4d55; }
            .fh-summary { margin-top: 20px; background: #f1f5f9; border-radius: 8px; padding: 12px 14px; }
            .fh-summary dl { display: grid; grid-template-columns: 65px 1fr; gap: 8px; font-size: 14px; margin-bottom: 0; }
            .fh-summary dt { color: #64748b; } .fh-summary dd { margin: 0; white-space: pre-wrap; }
            .fh-badge { border-radius: 999px; background: #dc2626; color: white; padding: 2px 6px; font-size: 12px; }
            .fh-button .fh-badge { position: absolute; right: -2px; top: -2px; }
            .fh-button.busy::before { content:""; position:absolute; inset:-5px; border-radius:999px; border:3px solid rgba(19,93,102,.18); border-top-color:#135d66; animation:fh-spin 1s linear infinite; }
            .fh-pill { position:fixed; right:92px; bottom:34px; z-index:50; display:inline-flex; gap:8px; align-items:center; max-width:calc(100vw - 110px); box-sizing:border-box; background:#fff; border:1px solid #e7eaee; border-radius:999px; padding:7px 14px 7px 11px; min-height:36px; line-height:20px; font:inherit; font-size:13.5px; box-shadow:0 10px 26px rgba(15,23,42,.14); color:#172026; cursor:pointer; text-align:left; }
            .fh-pill:hover { background:#f8fafa; }
            .fh-pill.att { border-color:#fdba74; background:#fff7ed; color:#7c2d12; }
            .fh-pill.att strong { color:#c2410c; font-weight:650; }
            .fh-pill .fh-spinner, .fh-pend .fh-spinner { width:12px; height:12px; box-sizing:border-box; border:2px solid #d6dfe0; border-top-color:#135d66; }
            .fh-pend { display:flex; align-items:center; gap:10px; width:100%; border:0; background:#f7fafa; border-radius:8px; padding:9px 12px; min-height:56px; box-sizing:border-box; line-height:18px; text-align:left; font:inherit; font-size:13px; cursor:pointer; }
            .fh-pend:hover { background:#edf7f8; }
            .fh-pend .tt { display:block; font-weight:650; color:#172026; overflow-wrap:anywhere; }
            .fh-pend .sub { display:block; margin-top:2px; color:#64748b; overflow-wrap:anywhere; }
            .fh-pend.att .sub { color:#c2410c; font-weight:600; }
            .fh-pend .pch { margin-left:auto; color:#64748b; flex:none; }
            .fh-uteq { display:flex; gap:10px; align-items:flex-start; margin:0 18px 14px; background:#edf7f8; border-radius:8px; padding:10px 12px; font-size:13.5px; line-height:1.45; color:#1f3a3e; }
            .fh-uteq svg { color:#135d66; flex:none; margin-top:1px; }
            .fh-body.fh-body-with-notice { padding-bottom:4px; }
            .fh-uteq strong { font-weight:650; }
            @media (max-width: 480px) {
                .fh-backdrop { padding: 12px; }
                .fh-modal { max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); }
                .fh-header, .fh-footer, .fh-body { padding: 14px; }
                .fh-uteq { margin-left:14px; margin-right:14px; }
            }
        </style>

        @include('feedback-hub::partials.widget-script')

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('feedbackHubWidget', (config) => window.FeedbackHubWidget(config));
            });
        </script>
    @endonce

    {{-- Livewire keeps this wrapper (and the recording in it) across wire:navigate. x-persist sits on a plain
         wrapper: on the x-data element itself Livewire would initialise the component a second time. --}}
    <script type="application/json" data-feedback-hub-props>{!! json_encode(['contextLabel' => $contextLabel, 'myFeedbackBadge' => (int) $myFeedbackBadge, 'myFeedbackUrl' => $myFeedbackUrl, 'submitLabel' => $submitLabel, 'notice' => $notice, 'status' => $status], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <div x-persist="feedback-hub">
    <div
        x-data="feedbackHubWidget({ endpoint: @js(route('feedback-hub.store')), followUpUrl: @js($followUpUrl), followUpWaitMs: @js($followUpWaitMs), contextLabel: @js($contextLabel), myFeedbackUrl: @js($myFeedbackUrl), myFeedbackBadge: @js((int) $myFeedbackBadge), chatEndpoint: @js(route('feedback-hub.chat'), JSON_UNESCAPED_SLASHES), csrfEndpoint: @js(route('feedback-hub.csrf'), JSON_UNESCAPED_SLASHES), chatEnabled: {{ $chatEnabled ? 'true' : 'false' }}, video: @js($video, JSON_UNESCAPED_SLASHES) })"
        x-on:feedback-hub-captured.window="handleCapture($event.detail)"
        x-on:feedback-hub-screenshot-captured.window="handleScreenshot($event.detail)"
        x-on:open-feedback-hub.window="start"
        x-on:resize.window="shotRevision++"
        class="fh-widget"
        data-feedback-hub-widget
    >
        <div x-on:click.outside="showChoice = false" x-on:keydown.escape.window="showChoice = false">
        <div class="fh-choice" x-cloak x-show="showChoice && !hidden" x-transition.opacity role="menu" aria-label="Feedback geven" data-feedback-hub-choice>
            <button type="button" class="fh-choice-option" role="menuitem" x-on:click="startScreenshot">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.8"/></svg>
                <span><span class="fh-choice-title">Schermafbeelding</span><span class="fh-choice-text">Wijs aan waar het om gaat</span></span>
            </button>
            <button type="button" class="fh-choice-option" role="menuitem" x-show="canRecordVideo()" x-on:click="startVideo">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="m16 10 5-3v10l-5-3" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span><span class="fh-choice-title">Video opnemen</span><span class="fh-choice-text">{{ $followUpUrl ? "Laat het zien en vertel erbij. Hooguit" : "Laat zien wat er gebeurt, ook over meerdere pagina's. Hooguit" }} <span x-text="videoLimitLabel()"></span>.</span></span>
            </button>
            <button type="button" class="fh-choice-option" role="menuitem" x-show="followUpUrl && !sentReport && hasDraft()" x-on:click="resumeDraft">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 3h14v18H5zM8 8h8M8 12h8" stroke="currentColor" stroke-width="1.8"/></svg>
                <span class="fh-choice-title">Verder met je tekst</span>
            </button>
            <template x-for="item in (status?.items || [])" :key="item.id">
                <button type="button" class="fh-pend" :class="{ att: item.state === 'attention' }" role="menuitem" x-on:click="openStatus(item.id)">
                    <span class="fh-spinner" x-show="item.state === 'busy'" aria-hidden="true"></span>
                    <svg x-show="item.state === 'attention'" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="color:#c2410c;flex:none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 7v6m0 3v1" stroke="currentColor" stroke-width="1.8"/></svg>
                    <span><span class="tt" x-text="item.title"></span><span class="sub" x-text="item.line"></span></span>
                    <svg class="pch" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </template>
            <a :href="myFeedbackUrl" x-show="myFeedbackUrl" class="fh-choice-option" role="menuitem" style="text-decoration: none; border-top: 1px solid #e7eaee">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 4h16v13H8l-4 4V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span class="fh-choice-title">Mijn feedback</span>
                <span class="fh-badge" x-show="myFeedbackBadge > 0" x-text="myFeedbackBadge"></span>
            </a>
        </div>

        @if ($floating)
            <button type="button" class="fh-pill" :class="{ att: status?.state === 'attention' }" x-cloak x-show="!hidden && !showModal && !recording && !showChoice && status?.label" x-on:click="openStatus()">
                <span class="fh-spinner" x-show="status?.state === 'busy'" aria-hidden="true"></span>
                <svg x-show="status?.state === 'attention'" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z" stroke="currentColor" stroke-width="1.8"/></svg>
                <span><strong x-show="status?.label?.strong" x-text="status?.label?.strong"></strong><span x-show="status?.label?.strong && status?.label?.text"> </span><span x-text="typeof status?.label === 'string' ? status.label : status?.label?.text"></span></span>
            </button>
            <button type="button" class="fh-button" :class="{ busy: status?.state === 'busy' }" x-on:click="start" x-show="!hidden && !showModal && !recording" aria-label="Feedback geven">
                <span class="fh-badge" x-show="myFeedbackBadge > 0" x-text="myFeedbackBadge"></span>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 13.5V10a3 3 0 0 1 3-3h3l6-3v16l-6-3H7a3 3 0 0 1-3-3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M8 17v2.2c0 .8.6 1.4 1.4 1.4h.8c.8 0 1.4-.6 1.4-1.4V18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M19 9.5c.7.6 1 1.5 1 2.5s-.3 1.9-1 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </button>
        @endif
        </div>

        <div class="fh-draft-toast" x-cloak x-show="undoDraft !== null" role="status">
            <span class="fh-muted">Weggegooid</span>
            <button type="button" class="fh-type" x-on:click="restoreDraft">Ongedaan maken</button>
        </div>
        <div class="fh-recording"  x-cloak x-show="recording" role="status" data-feedback-hub-recording>
            <span class="fh-record-dot" aria-hidden="true"></span>
            <span>Opname loopt <span x-text="recordingClock()"></span></span>
            <button type="button" class="fh-stop" x-on:click="stopRecording">Stop</button>
        </div>

        <div class="fh-backdrop" x-cloak x-show="showModal" x-transition.opacity x-on:click.self="close" x-on:keydown.escape.window="close">
            <section class="fh-modal" x-show="showModal" x-transition x-on:click.stop role="dialog" aria-modal="true" aria-labelledby="feedback-hub-heading">
                <header class="fh-header">
                    <h2 id="feedback-hub-heading" class="fh-title">Feedback geven <span class="fh-muted" x-show="followUpState === 'question'" x-text="(questionIndex + 1) + '/' + followUpQuestions.length"></span></h2>
                    <button type="button" class="fh-icon" x-on:click="close" aria-label="Sluiten">×</button>
                </header>

                <div class="fh-body" :class="{ 'fh-body-with-notice': notice?.title || notice?.text }" x-show="!sentReport">
                    @if($followUpUrl)
                        <div class="fh-alert" x-cloak x-show="error" role="alert">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 10 18H2L12 3ZM12 9v5M12 17v1" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            <span x-text="error === sessionMessage ? error : 'Niet verstuurd. Je tekst staat er nog.'"></span>
                        </div>
                    @endif
                    <template x-if="videoUrl">
                        <div class="fh-field">
                            @unless($followUpUrl)<label class="fh-label">Video</label>@endunless
                            <video class="fh-video" :src="videoUrl" controls playsinline></video>
                            @if($followUpUrl)<div class="fh-progress" x-show="submitting && uploadProgress !== null" role="progressbar" aria-label="Video versturen" :aria-valuenow="uploadProgress" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: (uploadProgress || 0) + '%' }"></span></div>@endif
                            <div class="fh-video-actions">
                                <button type="button" class="fh-type" x-on:click="startRecording">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 4v4.5h4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    Opnieuw opnemen
                                </button>
                                <button type="button" class="fh-type" x-on:click="removeVideo">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    Video verwijderen
                                </button>
                            </div>
                        </div>
                    </template>

                    <div class="fh-field" x-show="screenshot">
                        @unless($followUpUrl)<label class="fh-label">Screenshot</label>@endunless
                        @if($followUpUrl)
                            <div class="fh-shot-frame" x-effect="screenshot; $nextTick(() => shotRevision++)">
                                <img class="fh-shot" x-ref="shot" x-init="observeShot($el)" :src="screenshot" alt="Feedback screenshot" x-on:load="shotRevision++">
                                <span class="fh-shot-outline" x-show="elementRect" :style="screenshotOutline($refs.shot)"></span>
                                <div class="fh-shot-actions">
                                    <button type="button" class="fh-icon" x-on:click="repoint" :disabled="submitting" aria-label="Opnieuw aanwijzen" title="Opnieuw aanwijzen"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M12 1v6M12 17v6M1 12h6M17 12h6" stroke="currentColor" stroke-width="1.8"/></svg></button>
                                    <button type="button" class="fh-icon" x-on:click="removeScreenshot" :disabled="submitting" aria-label="Schermafbeelding verwijderen" title="Schermafbeelding verwijderen"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3" stroke="currentColor" stroke-width="1.8"/></svg></button>
                                </div>
                            </div>
                        @else
                            <img class="fh-shot" :src="screenshot" alt="Feedback screenshot">
                        @endif
                    </div>

                    <template x-if="mode === 'chat'">
                        <div class="fh-field">
                            <div class="fh-chat" aria-live="polite">
                                <template x-for="(message, index) in chatMessages" :key="index">
                                    <div class="fh-bubble" :class="{ 'is-user': message.role === 'user' }" x-text="message.content"></div>
                                </template>
                                <div class="fh-bubble is-busy" x-show="chatBusy">Even denken…</div>
                            </div>
                            <form class="fh-chat-form" x-on:submit.prevent="sendTurn" x-effect="chatMessages.length; $nextTick(() => $el.scrollIntoView({ block: 'nearest' }))">
                                <input id="feedback-hub-chat-input" class="fh-input" type="text" x-model="chatInput" maxlength="5000" placeholder="Typ je antwoord" aria-label="Je antwoord" :disabled="chatBusy" autocomplete="off">
                                <button type="submit" class="fh-primary" :disabled="chatBusy || !chatInput.trim()">Stuur</button>
                            </form>
                        </div>
                    </template>

                    <template x-if="mode !== 'chat'">
                        <div>
                            @unless($followUpUrl)
                            <div class="fh-field">
                                <label class="fh-label">Type</label>
                                <div class="fh-types">
                                    <button type="button" class="fh-type" :class="{ 'is-active': type === 'bug' }" :aria-pressed="(type === 'bug').toString()" x-on:click="type = 'bug'">Bug</button>
                                    <button type="button" class="fh-type" :class="{ 'is-active': type === 'suggestion' }" :aria-pressed="(type === 'suggestion').toString()" x-on:click="type = 'suggestion'">Suggestie</button>
                                    <button type="button" class="fh-type" :class="{ 'is-active': type === 'question' }" :aria-pressed="(type === 'question').toString()" x-on:click="type = 'question'">Vraag</button>
                                </div>
                            </div>

                            <div class="fh-field">
                                <label class="fh-label" for="feedback-hub-title">Titel</label>
                                <input id="feedback-hub-title" class="fh-input" type="text" x-model="title" maxlength="255" placeholder="Korte beschrijving">
                            </div>

                            @endunless
                            <div class="fh-field">
                                <label class="fh-label" for="feedback-hub-description">{{ $followUpUrl ? "Wat moet er anders?" : "Beschrijving" }}</label>
                                <textarea id="feedback-hub-description" class="fh-textarea" rows="{{ $followUpUrl ? 4 : 5 }}" :disabled="!!followUpUrl && submitting" x-model="description" maxlength="5000" @if($followUpUrl) :placeholder="videoUrl ? 'Typ het hier, of zeg het in de video' : 'Bijvoorbeeld: hier moet ook de tijd bij'" @endif placeholder="{{ $followUpUrl ? "Bijvoorbeeld: hier moet ook de tijd bij" : "Wat verwachtte je en wat gebeurde er?" }}"></textarea>
                            </div>

                            @if($followUpUrl)<p class="fh-muted fh-context"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m8 13 7-7a3 3 0 0 1 4 4L9 20a5 5 0 0 1-7-7L13 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><span class="fh-context-label">Gaat mee:</span> <span x-text="contextText()"></span></p>@endif
                            <template x-if="mode === 'summary'">
                                <div>
                                    <div class="fh-field">
                                        <label class="fh-label" for="feedback-hub-goal">Doel</label>
                                        <input id="feedback-hub-goal" class="fh-input" type="text" x-model="intent.goal" maxlength="2000">
                                    </div>
                                    <div class="fh-field">
                                        <label class="fh-label" for="feedback-hub-expected">Verwacht</label>
                                        <input id="feedback-hub-expected" class="fh-input" type="text" x-model="intent.expected" maxlength="2000">
                                    </div>
                                    <div class="fh-field">
                                        <label class="fh-label" for="feedback-hub-actual">Werkelijk</label>
                                        <input id="feedback-hub-actual" class="fh-input" type="text" x-model="intent.actual" maxlength="2000">
                                    </div>
                                </div>
                            </template>

                            <div class="fh-field" x-show="elementSelector && !followUpUrl">
                                <label class="fh-label">Geselecteerd element</label>
                                <code class="fh-code" x-text="elementSelector"></code>
                            </div>
                        </div>
                    </template>

                    @unless($followUpUrl)<p class="fh-error" x-cloak x-show="error" x-text="error" role="alert"></p>@endunless
                </div>

                <div class="fh-uteq" x-cloak x-show="!sentReport && (notice?.title || notice?.text)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M15 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.8"/></svg>
                    <div><strong x-text="notice?.title"></strong> <span x-text="notice?.text"></span></div>
                </div>
                <footer class="fh-footer" x-show="!sentReport">
                    @if($followUpUrl)
                        <button type="button" class="fh-icon" x-show="!sentReport" x-on:click="discardDraft" :disabled="submitting" aria-label="Weggooien" style="margin-right:auto">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12h10l1-12M9 7V4h6v3" stroke="currentColor" stroke-width="1.8"/></svg>
                        </button>
                    @endif
                    @unless($followUpUrl)<button type="button" class="fh-secondary" x-on:click="close">Annuleren</button>@endunless
                    <button type="button" class="fh-primary" :class="{ 'fh-with-icon': followUpUrl || submitLabel }" x-show="mode !== 'chat'" x-on:click="submit" :disabled="submitting || (followUpUrl ? (!description.trim() && !videoUrl) : !title)">
                        <svg x-show="submitLabel && !submitting" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z" stroke="currentColor" stroke-width="1.8"/></svg>
                        @if($followUpUrl)
                            <svg x-show="!submitLabel && !submitting" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4L22 2ZM11 13 22 2" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            <span class="fh-spinner" x-show="submitting" aria-hidden="true"></span>
                        @endif
                        <span x-show="!submitting" x-text="followUpUrl && error ? 'Opnieuw versturen' : (submitLabel || 'Versturen')"></span>
                        <span x-show="submitting" data-feedback-hub-submit-label x-text="followUpUrl ? 'Versturen…' : (uploadProgress === null ? 'Bezig...' : `Video versturen ${uploadProgress}%`)"></span>
                    </button>
                </footer>
                @if($followUpUrl)
                <div class="fh-body" x-cloak x-show="sentReport" aria-live="polite">
                    <div x-show="followUpState !== 'question'" class="fh-confirm">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" style="color:#15803d;flex:none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><path d="m8 12 3 3 5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <div><p class="fh-q">Verstuurd naar Uteq</p><span class="fh-muted">Je hoeft niets meer te doen.</span></div>
                    </div>
                    <p class="fh-sent" x-show="followUpState === 'question'"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><path d="m8 12 3 3 5-6" stroke="currentColor" stroke-width="1.8"/></svg><span x-text="questionIndex === 0 && followUpQuestions.length === 2 ? 'Verstuurd. Nog twee korte vragen.' : 'Verstuurd. Nog één vraag.'"></span></p>
                    <div x-show="followUpState === 'waiting'">
                        <p class="fh-muted fh-wait"><span class="fh-dots" aria-hidden="true"><i></i><i></i><i></i></span>Even kijken of we nog iets moeten vragen…</p>
                    </div>
                    <div x-show="followUpState === 'question'">
                        <h3 class="fh-q" x-text="currentQuestion().question"></h3>
                        <p class="fh-qsub" x-show="currentQuestion().hint" x-text="currentQuestion().hint"></p>
                        <template x-for="option in (currentQuestion().options || [])" :key="option">
                            <button type="button" class="fh-type fh-answer" :class="{ 'is-active': selectedAnswer === option }" :disabled="answering" x-on:click="answerQuestion(option)"><span x-text="option"></span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 6 6 6-6 6" stroke="#94a3b8" stroke-width="1.8"/></svg></button>
                        </template>
                        <button type="button" class="fh-type fh-answer" x-show="!otherAnswer" x-on:click="otherAnswer = true; $nextTick(() => $refs.otherAnswer.focus())">Iets anders<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 4 5 5-11 11H4v-5L15 4Z" stroke="#94a3b8" stroke-width="1.8"/></svg></button>
                        <form x-show="otherAnswer" x-on:submit.prevent="answerQuestion(answerText)" class="fh-chat-form">
                            <input class="fh-input" x-ref="otherAnswer" x-model="answerText" maxlength="500" aria-label="Je antwoord" placeholder="Vertel het in je eigen woorden…">
                            <button class="fh-primary" type="submit" :disabled="answering || !answerText.trim()">Verstuur</button>
                        </form>
                    </div>
                    <div x-show="followUpState === 'done'">
                        <div class="fh-summary" x-show="followUpSummary">
                            <span class="fh-label">Zo hebben we het begrepen</span>
                            <strong x-text="followUpSummary?.title"></strong>
                            <dl>
                                <dt>Je zag</dt><dd x-text="followUpSummary?.saw"></dd>
                                <dt>Je wilt</dt><dd x-text="followUpSummary?.wants"></dd>
                                <dt>Waar</dt><dd x-text="followUpSummary?.where"></dd>
                            </dl>
                        </div>
                        <p class="fh-muted fh-wait" x-show="sentVideo"><span class="fh-spinner" aria-hidden="true"></span>We bekijken je video nog. Vragen komen in je inbox.</p>
                        <p class="fh-muted fh-wait" x-show="followUpLate"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 4h16v13H8l-4 4V4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>Heeft Uteq nog een vraag? Die komt in je inbox.</p>
                    </div>
                </div>
                <footer class="fh-footer" x-cloak x-show="sentReport">
                    <button type="button" class="fh-secondary" x-show="followUpState === 'question'" x-on:click="answerQuestion(null, true)" :disabled="answering">Overslaan</button>
                    <a class="fh-secondary fh-with-icon" x-show="followUpState === 'done' && editUrl" :href="editUrl"  style="margin-right:auto;text-decoration:none" ><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 4 5 5-11 11H4v-5L15 4Z" stroke="currentColor" stroke-width="1.8"/></svg>Aanpassen</a>
                    <button type="button" class="fh-primary" x-show="followUpState !== 'question'" x-on:click="close">Klaar</button>
                </footer>
                @endif
            </section>
        </div>
    </div>
    </div>
@endauth
