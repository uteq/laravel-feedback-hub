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
        </style>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('feedbackHubWidget', (config) => ({
                    endpoint: config.endpoint,
                    showModal: false,
                    submitting: false,
                    type: 'bug',
                    title: '',
                    description: '',
                    screenshot: null,
                    elementSelector: null,
                    elementRect: null,
                    sessionData: null,
                    consoleErrors: [],
                    networkRequests: [],
                    formState: null,

                    start() {
                        if (window.FeedbackHubInspector) {
                            window.FeedbackHubInspector.activate();
                            return;
                        }

                        this.showModal = true;
                    },

                    handleCapture(detail) {
                        this.screenshot = detail.screenshot;
                        this.elementSelector = detail.elementSelector;
                        this.elementRect = detail.elementRect;
                        this.sessionData = detail.sessionData;
                        this.consoleErrors = detail.consoleErrors || [];
                        this.networkRequests = detail.networkRequests || [];
                        this.formState = detail.formState || null;
                        this.showModal = true;
                    },

                    handleScreenshot(detail) {
                        if (detail.screenshot) {
                            this.screenshot = detail.screenshot;
                        }
                    },

                    close() {
                        this.showModal = false;
                        this.reset();
                    },

                    reset() {
                        this.type = 'bug';
                        this.title = '';
                        this.description = '';
                        this.screenshot = null;
                        this.elementSelector = null;
                        this.elementRect = null;
                        this.sessionData = null;
                        this.consoleErrors = [];
                        this.networkRequests = [];
                        this.formState = null;
                    },

                    async submit() {
                        if (!this.title || this.submitting) return;

                        this.submitting = true;
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
                            || document.querySelector('input[name="_token"]')?.value
                            || window.Livewire?.csrfToken?.();

                        try {
                            const payload = this.redactPayload({
                                type: this.type,
                                title: this.title,
                                description: this.description,
                                url: this.currentUrl(),
                                element_selector: this.elementSelector,
                                element_rect: this.elementRect,
                                screenshot: this.screenshot,
                                session_data: this.sessionData || this.collectSessionData(),
                                console_errors: this.consoleErrors,
                                network_requests: this.networkRequests,
                                form_state: this.formState,
                            });

                            const response = await fetch(this.endpoint, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify(payload),
                            });

                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) {
                                throw new Error(data.message || 'Feedback kon niet worden verstuurd');
                            }

                            this.close();
                            this.toast('Feedback verzonden', data.reference ? `Referentie: ${data.reference}` : '', 'success');
                        } catch (error) {
                            this.toast('Er ging iets mis', error.message || 'Probeer het opnieuw', 'danger');
                        } finally {
                            this.submitting = false;
                        }
                    },

                    currentUrl() {
                        return this.redactText(window.location.href);
                    },

                    redactText(value) {
                        if (!value) return value;

                        if (window.FeedbackHubInspector?.redactText) {
                            return window.FeedbackHubInspector.redactText(value);
                        }

                        return String(value)
                            .replace(/([?&])([^=&#]*(?:password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey|key)[^=&#]*)=([^&#\s]*)/gi, '$1$2=[filtered]')
                            .replace(/\b(Bearer\s+)[A-Za-z0-9._~+/=-]+/gi, '$1[filtered]')
                            .replace(/\b(password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey)\s*[:=]\s*([^\s,;&#]+)/gi, '$1=[filtered]');
                    },

                    redactPayload(value, key = null) {
                        if (key && this.isSensitiveKey(key)) return '[filtered]';
                        if (Array.isArray(value)) return value.map((item) => this.redactPayload(item));
                        if (value && typeof value === 'object') {
                            return Object.fromEntries(
                                Object.entries(value).map(([entryKey, entryValue]) => [entryKey, this.redactPayload(entryValue, entryKey)])
                            );
                        }
                        if (typeof value === 'string') return this.redactText(value);

                        return value;
                    },

                    isSensitiveKey(key) {
                        return ['password', 'passwd', 'hidden', 'token', 'secret', 'authorization', 'cookie', 'csrf', '_token', 'api_key', 'apikey'].some((part) => String(key).toLowerCase().includes(part));
                    },

                    collectSessionData() {
                        return {
                            url: this.currentUrl(),
                            userAgent: navigator.userAgent,
                            viewport: { width: window.innerWidth, height: window.innerHeight },
                            screen: { width: window.screen.width, height: window.screen.height },
                            locale: navigator.language,
                            timestamp: new Date().toISOString(),
                        };
                    },

                    toast(heading, text, variant) {
                        if (window.Flux?.toast) {
                            window.Flux.toast({ heading, text, variant });
                            return;
                        }

                        window.dispatchEvent(new CustomEvent('feedback-hub:toast', { detail: { heading, text, variant } }));
                    },
                }));
            });
        </script>
    @endonce

    <div
        x-data="feedbackHubWidget({ endpoint: @js(route('feedback-hub.store')) })"
        x-on:feedback-hub-captured.window="handleCapture($event.detail)"
        x-on:feedback-hub-screenshot-captured.window="handleScreenshot($event.detail)"
        data-feedback-hub-widget
    >
        <button type="button" class="fh-button" x-on:click="start" x-show="!showModal" aria-label="Feedback geven">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4 13.5V10a3 3 0 0 1 3-3h3l6-3v16l-6-3H7a3 3 0 0 1-3-3.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                <path d="M8 17v2.2c0 .8.6 1.4 1.4 1.4h.8c.8 0 1.4-.6 1.4-1.4V18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M19 9.5c.7.6 1 1.5 1 2.5s-.3 1.9-1 2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </button>

        <div class="fh-backdrop" x-cloak x-show="showModal" x-transition.opacity x-on:click.self="close" x-on:keydown.escape.window="close">
            <section class="fh-modal" x-show="showModal" x-transition x-on:click.stop>
                <header class="fh-header">
                    <h2 class="fh-title">Feedback geven</h2>
                    <button type="button" class="fh-icon" x-on:click="close" aria-label="Sluiten">×</button>
                </header>

                <div class="fh-body">
                    <div class="fh-field" x-show="screenshot">
                        <label class="fh-label">Screenshot</label>
                        <img class="fh-shot" :src="screenshot" alt="Feedback screenshot">
                    </div>

                    <div class="fh-field">
                        <label class="fh-label">Type</label>
                        <div class="fh-types">
                            <button type="button" class="fh-type" :class="{ 'is-active': type === 'bug' }" x-on:click="type = 'bug'">Bug</button>
                            <button type="button" class="fh-type" :class="{ 'is-active': type === 'suggestion' }" x-on:click="type = 'suggestion'">Suggestie</button>
                            <button type="button" class="fh-type" :class="{ 'is-active': type === 'question' }" x-on:click="type = 'question'">Vraag</button>
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

                    <div class="fh-field" x-show="elementSelector">
                        <label class="fh-label">Geselecteerd element</label>
                        <code class="fh-code" x-text="elementSelector"></code>
                    </div>
                </div>

                <footer class="fh-footer">
                    <button type="button" class="fh-secondary" x-on:click="close">Annuleren</button>
                    <button type="button" class="fh-primary" x-on:click="submit" :disabled="submitting || !title">
                        <span x-show="!submitting">Versturen</span>
                        <span x-show="submitting">Bezig...</span>
                    </button>
                </footer>
            </section>
        </div>
    </div>
@endauth
