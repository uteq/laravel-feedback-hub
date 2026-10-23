@props(['floating' => true])

@php($chatEnabled = \Uteq\FeedbackHub\Support\FeedbackAiAvailability::available())

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
            .fh-error { margin: 0; color: #b91c1c; font-size: 13px; line-height: 1.4; }
            @media (max-width: 480px) {
                .fh-button { right: 16px; bottom: 16px; }
                .fh-backdrop { padding: 12px; }
                .fh-modal { max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); }
                .fh-header, .fh-footer, .fh-body { padding: 14px; }
            }
        </style>

        @include('feedback-hub::partials.widget-script')

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('feedbackHubWidget', (config) => window.FeedbackHubWidget(config));
            });
        </script>
    @endonce

    <div
        x-data="feedbackHubWidget({ endpoint: @js(route('feedback-hub.store')), chatEndpoint: @js(route('feedback-hub.chat'), JSON_UNESCAPED_SLASHES), csrfEndpoint: @js(route('feedback-hub.csrf'), JSON_UNESCAPED_SLASHES), chatEnabled: {{ $chatEnabled ? 'true' : 'false' }} })"
        x-on:feedback-hub-captured.window="handleCapture($event.detail)"
        x-on:feedback-hub-screenshot-captured.window="handleScreenshot($event.detail)"
        x-on:open-feedback-hub.window="start"
        data-feedback-hub-widget
    >
        @if ($floating)
            <button type="button" class="fh-button" x-on:click="start" x-show="!showModal" aria-label="Feedback geven">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 13.5V10a3 3 0 0 1 3-3h3l6-3v16l-6-3H7a3 3 0 0 1-3-3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M8 17v2.2c0 .8.6 1.4 1.4 1.4h.8c.8 0 1.4-.6 1.4-1.4V18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M19 9.5c.7.6 1 1.5 1 2.5s-.3 1.9-1 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </button>
        @endif

        <div class="fh-backdrop" x-cloak x-show="showModal" x-transition.opacity x-on:click.self="close" x-on:keydown.escape.window="close">
            <section class="fh-modal" x-show="showModal" x-transition x-on:click.stop role="dialog" aria-modal="true" aria-labelledby="feedback-hub-heading">
                <header class="fh-header">
                    <h2 id="feedback-hub-heading" class="fh-title">Feedback geven</h2>
                    <button type="button" class="fh-icon" x-on:click="close" aria-label="Sluiten">×</button>
                </header>

                <div class="fh-body">
                    <div class="fh-field" x-show="screenshot">
                        <label class="fh-label">Screenshot</label>
                        <img class="fh-shot" :src="screenshot" alt="Feedback screenshot">
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

                            <div class="fh-field">
                                <label class="fh-label" for="feedback-hub-description">Beschrijving</label>
                                <textarea id="feedback-hub-description" class="fh-textarea" rows="5" x-model="description" maxlength="5000" placeholder="Wat verwachtte je en wat gebeurde er?"></textarea>
                            </div>

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

                            <div class="fh-field" x-show="elementSelector">
                                <label class="fh-label">Geselecteerd element</label>
                                <code class="fh-code" x-text="elementSelector"></code>
                            </div>
                        </div>
                    </template>

                    <p class="fh-error" x-cloak x-show="error" x-text="error" role="alert"></p>
                </div>

                <footer class="fh-footer">
                    <button type="button" class="fh-secondary" x-on:click="close">Annuleren</button>
                    <button type="button" class="fh-primary" x-show="mode !== 'chat'" x-on:click="submit" :disabled="submitting || !title">
                        <span x-show="!submitting">Versturen</span>
                        <span x-show="submitting">Bezig...</span>
                    </button>
                </footer>
            </section>
        </div>
    </div>
@endauth
