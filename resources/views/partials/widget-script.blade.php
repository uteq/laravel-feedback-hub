{{--
    Shared behaviour of the feedback widget: chat, summary, form, submit, CSRF retry and redaction.

    It defines window.FeedbackHubWidget(config), a factory that returns the Alpine component object.
    A widget registers it under its own markup and overrides only the UI hooks:

        document.addEventListener('alpine:init', () => {
            Alpine.data('feedbackHubWidget', (config) => ({
                ...window.FeedbackHubWidget(config),
                openModal() { ... },
                closeModal() { ... },
                notify(heading, text, variant) { ... },
            }));
        });

    config: { endpoint, chatEndpoint, csrfEndpoint, chatEnabled, video: { enabled, chunkEndpoint, chunkBytes, maxSeconds } }.
    The screen recording (startRecording/stopRecording) hides the modal while it records and opens it again
    afterwards; a recording travels in chunks to video.chunkEndpoint before the report is sent.
    The defaults below toggle a `showModal` boolean and toast through window.Flux.toast,
    falling back to a cancelable `feedback-hub:toast` window event, followed by
    `mortel-toast` unless the host calls preventDefault().
--}}
@once
    <script>
        window.FeedbackHubWidget = (config) => {
        // Native media objects stay outside Alpine's reactive proxy: a proxied MediaRecorder or Blob breaks.
        const media = { recorder: null, streams: [], chunks: [], timer: null, video: null, discard: false, leaveGuard: null, crop: null };
        const video = config.video || {};

        return {
            endpoint: config.endpoint,
            chatEndpoint: config.chatEndpoint,
            csrfEndpoint: config.csrfEndpoint,
            followUpUrl: config.followUpUrl || null,
            followUpWaitMs: Math.max(1, Number(config.followUpWaitMs) || 3000),
            contextLabel: config.contextLabel || '',
            myFeedbackUrl: config.myFeedbackUrl || null,
            myFeedbackBadge: Number(config.myFeedbackBadge) || 0,
            submitLabel: config.submitLabel || '',
            notice: config.notice || null,
            status: config.status || null,
            hidden: false,
            pageContext: null,
            closedWhileSubmitting: false,
            sentVideo: false,
            init() {
                this.onNavigate = () => {
                    const node = document.querySelector('script[data-feedback-hub-props]');
                    try { if (node) this.updateProps(JSON.parse(node.textContent)); } catch (_) {}
                };
                this.$watch('showChoice', open => {
                    if (this.$el.dataset.mobileTrigger !== 'host' || !window.matchMedia('(max-width: 760px)').matches) return;
                    if (open) {
                        this.$nextTick(() => this.$el.querySelector('[data-feedback-hub-choice] [role="menuitem"]')?.focus());
                    } else {
                        const anchor = config.mobileAnchor ? document.querySelector(config.mobileAnchor) : null;
                        anchor?.focus({ preventScroll: true });
                    }
                });
                this.onNavigate();
                this.onUpdate = event => this.updateProps(event.detail || {});
                document.addEventListener('livewire:navigated', this.onNavigate);
                window.addEventListener('feedback-hub:update', this.onUpdate);
            },
            destroy() {
                document.removeEventListener('livewire:navigated', this.onNavigate);
                window.removeEventListener('feedback-hub:update', this.onUpdate);
                this.stopActivity?.();
                media.shotObserver?.disconnect();
            },
            updateProps(props) {
                if (Object.hasOwn(props, 'submitLabel')) this.submitLabel = String(props.submitLabel || '');
                if (Object.hasOwn(props, 'notice')) this.notice = props.notice || null;
                if (Object.hasOwn(props, 'status')) this.status = props.status || null;
                if (Object.hasOwn(props, 'hidden')) {
                    this.hidden = Boolean(props.hidden);
                    if (this.hidden) this.showChoice = false;
                }
                if (Object.hasOwn(props, 'contextLabel')) this.contextLabel = String(props.contextLabel || '');
                if (Object.hasOwn(props, 'myFeedbackBadge')) this.myFeedbackBadge = Math.max(0, Number(props.myFeedbackBadge) || 0);
                if (Object.hasOwn(props, 'myFeedbackUrl')) this.myFeedbackUrl = props.myFeedbackUrl || null;
            },
            openStatus(id = null) {
                id ??= this.status?.items?.find(item => item.state === this.status.state)?.id ?? this.status?.items?.[0]?.id;
                if (id != null) window.dispatchEvent(new CustomEvent('feedback-hub:open', { detail: { id } }));
            },
            sentReport: null,
            followUpState: null,
            followUpSummary: null,
            followUpQuestions: [],
            questionIndex: 0,
            selectedAnswer: null,
            answering: false,
            otherAnswer: false,
            answerText: '',
            editUrl: null,
            followUpLate: false,
            activity: [],
            chatEnabled: !config.followUpUrl && Boolean(config.chatEnabled),
            mode: !config.followUpUrl && config.chatEnabled ? 'chat' : 'form',
            chatMessages: [],
            chatInput: '',
            chatBusy: false,
            intent: { goal: '', expected: '', actual: '' },
            showModal: false,
            submitting: false,
            error: '',
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
            videoEnabled: Boolean(video.enabled),
            videoChunkEndpoint: video.chunkEndpoint,
            videoChunkBytes: Number(video.chunkBytes) || 512 * 1024,
            videoMaxSeconds: Number(video.maxSeconds) || 120,
            showChoice: false,
            recording: false,
            recordingSeconds: 0,
            uploadProgress: null,
            videoUrl: null,

            openModal() {
                if (this.followUpUrl && !this.pageContext && !this.sentReport) this.capturePageContext();
                this.showChoice = false;
                this.showModal = true;
                if (this.followUpUrl && !this.sentReport) {
                    requestAnimationFrame(() => document.getElementById('feedback-hub-description')?.focus({ preventScroll: true }));
                }
            },

            closeModal() {
                this.showModal = false;
            },

            notify(heading, text, variant) {
                if (window.Flux?.toast) {
                    window.Flux.toast({ heading, text, variant });
                    return;
                }

                const detail = { heading, text: text || heading, variant };

                // A host that shows its own toast cancels this event (Alpine: .prevent).
                if (window.dispatchEvent(new CustomEvent('feedback-hub:toast', { detail, cancelable: true }))) {
                    window.dispatchEvent(new CustomEvent('mortel-toast', { detail }));
                }
            },

            // With video available the person first picks a screenshot or a video; otherwise straight to the screenshot.
            positionMobileMenu() {
                const anchor = config.mobileAnchor ? document.querySelector(config.mobileAnchor) : null;
                if (anchor) this.$el.style.setProperty('--fh-mobile-menu-top', `${Math.max(0, anchor.getBoundingClientRect().bottom) + 8}px`);
            },
            start() {
                this.positionMobileMenu();
                if (this.submitting) { this.openModal(); return; }
                this.undoDraft = null;
                media.draft = null;
                if (this.followUpUrl && !this.sentReport && this.hasDraft()) {
                    this.showChoice = true;
                    return;
                }
                this.reset();

                if (this.status?.items?.length || this.canRecordVideo() || this.myFeedbackUrl) {
                    this.showChoice = true;
                    return;
                }
                this.startScreenshot();
            },
            hasDraft() {
                return Boolean(this.description || this.videoUrl || this.screenshot);
            },
            resumeDraft() { this.openModal(); },
            startScreenshot() {
                this.reset();
                this.repoint();
            },
            repoint() {
                this.showChoice = false;
                this.closeModal();
                this.screenshot = null;
                this.elementSelector = null;
                this.elementRect = null;
                this.pageContext = null;
                if (window.FeedbackHubInspector) {
                    window.FeedbackHubInspector.activate();
                    return;
                }
                this.openModal();
            },
            removeScreenshot() {
                this.screenshot = null;
                this.elementRect = null;
            },

            close() {
                if (!this.showModal) return;
                this.closeModal();
                if (this.followUpUrl && this.submitting && !this.sentReport) {
                    this.closedWhileSubmitting = true;
                    return;
                }
                if (this.followUpUrl && !this.sentReport) return;
                if (this.sentReport && this.followUpState !== 'done') {
                    this.notify('Verstuurd. Terug te vinden bij Mijn feedback.', '', 'success');
                }
                this.reset();
            },

            discardDraft() {
                const draft = {};
                ['description', 'screenshot', 'elementSelector', 'elementRect', 'sessionData', 'consoleErrors', 'networkRequests', 'formState', 'activity', 'recordingSeconds', 'pageContext'].forEach(key => draft[key] = this[key]);
                draft.video = media.video;
                this.closeModal();
                this.reset();
                media.draft = draft;
                this.undoDraft = true;
                setTimeout(() => {
                    if (media.draft === draft) { this.undoDraft = null; media.draft = null; }
                }, 8000);
            },
            undoDraft: null,
            restoreDraft() {
                const draft = media.draft;
                if (!draft) return;
                const { video: blob, ...fields } = draft;
                Object.assign(this, fields);
                if (blob) { media.video = blob; this.videoUrl = URL.createObjectURL(blob); }
                this.undoDraft = null;
                media.draft = null;
                this.openModal();
            },
            selectedLabel() {
                try {
                    const el = this.elementSelector && document.querySelector(this.elementSelector);
                    return this.redactText(el?.getAttribute('aria-label') || el?.labels?.[0]?.textContent || el?.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60);
                } catch (_) { return ''; }
            },
            capturePageContext() {
                const elementLabel = this.selectedLabel();
                this.pageContext = {
                    url: this.currentUrl(), title: document.title,
                    label: [this.contextLabel || document.title, elementLabel].filter(Boolean).join(' · '),
                    viewport: { width: window.innerWidth, height: window.innerHeight },
                };
            },
            contextText() {
                return this.pageContext?.label || [this.contextLabel || document.title, this.selectedLabel()].filter(Boolean).join(' · ');
            },
            shotRevision: 0,
            observeShot(image) {
                media.shotObserver?.disconnect();
                media.shotObserver = new ResizeObserver(() => this.shotRevision++);
                media.shotObserver.observe(image);
            },
            screenshotOutline(image) {
                this.shotRevision;
                if (!this.elementRect) return {};
                const view = this.pageContext?.viewport || this.sessionData?.viewport || { width: innerWidth, height: innerHeight };
                if (!image) return {};
                const scale = Math.min(image.clientWidth / view.width, image.clientHeight / view.height);
                const x = (image.clientWidth - view.width * scale) / 2;
                const y = (image.clientHeight - view.height * scale) / 2;
                return {
                    left: (x + this.elementRect.x * scale) + 'px',
                    top: (y + this.elementRect.y * scale) + 'px',
                    width: (this.elementRect.width * scale) + 'px',
                    height: (this.elementRect.height * scale) + 'px',
                };
            },

            handleCapture(detail) {
                this.screenshot = detail.screenshot;
                this.elementSelector = detail.elementSelector;
                this.elementRect = detail.elementRect;
                this.sessionData = detail.sessionData;
                this.consoleErrors = detail.consoleErrors || [];
                this.networkRequests = detail.networkRequests || [];
                this.formState = detail.formState || null;
                if (this.followUpUrl) {
                    this.capturePageContext();
                    this.sessionData = { ...(this.sessionData || this.collectSessionData()), title: this.pageContext.title };
                }
                this.openModal();
            },

            handleScreenshot(detail) {
                if (detail.screenshot) {
                    this.screenshot = detail.screenshot;
                }
            },

            canRecordVideo() {
                return this.videoEnabled
                    && Boolean(this.videoChunkEndpoint)
                    && typeof navigator.mediaDevices?.getDisplayMedia === 'function'
                    && typeof window.MediaRecorder === 'function';
            },

            videoLimitLabel() {
                const seconds = this.videoMaxSeconds;

                return seconds % 60 === 0 && seconds >= 120 ? `${seconds / 60} minuten` : `${seconds} seconden`;
            },

            recordingClock() {
                const seconds = this.recordingSeconds;

                return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
            },

            recordingUnavailableMessage: 'Opnemen is niet gelukt. Je kunt je feedback ook zonder video sturen.',

            startVideo() {
                this.reset();
                this.pageContext = null;
                this.showChoice = false;
                this.startRecording();
            },

            async startRecording() {
                if (this.recording || !this.canRecordVideo()) return;

                this.error = '';
                if (this.followUpUrl) {
                    this.pageContext = null;
                    this.sessionData = null;
                    this.screenshot = null;
                    this.elementSelector = null;
                    this.elementRect = null;
                    this.formState = null;
                }
                this.removeVideo();
                this.closeModal();

                let display;
                try {
                    // Ask for this tab (Chrome and Edge show "Share this tab?"), never the whole screen where the browser lets us.
                    display = await navigator.mediaDevices.getDisplayMedia({
                        video: { displaySurface: 'browser', frameRate: 15 },
                        audio: false,
                        preferCurrentTab: true,
                        selfBrowserSurface: 'include',
                        surfaceSwitching: 'exclude',
                        monitorTypeSurfaces: 'exclude',
                    });
                } catch (error) {
                    this.error = this.recordingUnavailableMessage;
                    this.openModal();
                    return;
                }

                // The voice is optional: without a microphone (or permission) the recording simply has no sound.
                let microphone = null;
                try {
                    microphone = await navigator.mediaDevices.getUserMedia?.({ audio: true }) || null;
                } catch (error) {
                    microphone = null;
                }

                media.streams = [display, microphone].filter(Boolean);
                media.crop = await this.cropToPage(display);
                const audio = microphone ? microphone.getAudioTracks() : [];
                const stream = new MediaStream([...(media.crop ? [media.crop.track] : display.getVideoTracks()), ...audio]);
                const types = audio.length
                    ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4']
                    : ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4'];
                const mimeType = types.find((type) => MediaRecorder.isTypeSupported(type));

                let recorder;
                try {
                    recorder = new MediaRecorder(stream, { ...(mimeType ? { mimeType } : {}), videoBitsPerSecond: 800000, audioBitsPerSecond: 64000 });
                } catch (error) {
                    this.stopStreams();
                    this.error = this.recordingUnavailableMessage;
                    this.openModal();
                    return;
                }

                media.recorder = recorder;
                media.chunks = [];
                media.discard = false;
                recorder.ondataavailable = (event) => { if (event.data?.size) media.chunks.push(event.data); };
                recorder.onstop = () => this.finishRecording(recorder.mimeType || mimeType || 'video/webm');

                // "Stop sharing" in the browser bar ends the video track; treat it as pressing Stop.
                display.getVideoTracks().forEach((track) => track.addEventListener('ended', () => this.stopRecording(), { once: true }));

                // The widget survives wire:navigate (x-persist); a real page load would end the recording, so warn first.
                media.leaveGuard = (event) => { event.preventDefault(); event.returnValue = ''; };
                window.addEventListener('beforeunload', media.leaveGuard);

                this.recordingSeconds = 0;
                this.recording = true;
                this.startActivity();
                recorder.start(1000);
                media.timer = setInterval(() => {
                    this.recordingSeconds++;
                    if (this.recordingSeconds >= this.videoMaxSeconds) this.stopRecording();
                }, 1000);
            },

            startActivity() {
                this.stopActivity?.();
                media.startedAt = performance.now();
                this.activity = [{
                    t: 0, type: 'start',
                    label: this.redactText(document.title).slice(0, 60),
                    selector: '',
                    path: this.redactText(location.pathname).slice(0, 500),
                }];
                let lastUrl = location.href;
                let active = true;
                const append = (type, el) => {
                    if (this.activity.length >= 500) return;
                    if (!active) return;
                    const control = el?.closest('button,a,label,[role="button"],summary,option');
                    const label = control?.textContent || '';
                    this.activity.push({
                        t: Math.round((performance.now() - media.startedAt) / 100) / 10,
                        type,
                        label: this.redactText(label).trim().replace(/\s+/g, ' ').slice(0, 60),
                        selector: (el ? window.FeedbackHubInspector?.getSelector(control || el) || '' : '').slice(0, 200),
                        path: this.redactText(location.pathname).slice(0, 500),
                    });
                };
                const click = event => {
                    if (!(event.target instanceof Element) || event.target.closest('.fh-widget, [data-feedback-hub-recording]')) return;
                    append('click', event.target);
                };
                const navigate = () => {
                    if (lastUrl === location.href) return;
                    lastUrl = location.href;
                    append('navigate', null);
                };
                const originals = {};
                const wrappers = {};
                ['pushState', 'replaceState'].forEach(name => {
                    originals[name] = history[name];
                    wrappers[name] = function (...args) { const result = originals[name].apply(this, args); navigate(); return result; };
                    history[name] = wrappers[name];
                });
                document.addEventListener('click', click, true);
                document.addEventListener('livewire:navigated', navigate);
                window.addEventListener('popstate', navigate);
                this.stopActivity = () => {
                    active = false;
                    document.removeEventListener('click', click, true);
                    document.removeEventListener('livewire:navigated', navigate);
                    window.removeEventListener('popstate', navigate);
                    Object.keys(originals).forEach(name => { if (history[name] === wrappers[name]) history[name] = originals[name]; });
                    this.stopActivity = null;
                };
            },

            stopRecording() {
                if (media.recorder && media.recorder.state !== 'inactive') {
                    media.recorder.stop();
                }
            },

            finishRecording(type) {
                clearInterval(media.timer);
                media.timer = null;
                if (media.leaveGuard) window.removeEventListener('beforeunload', media.leaveGuard);
                media.leaveGuard = null;
                media.recorder = null;
                this.stopStreams();
                this.stopActivity?.();
                this.recordingSeconds = Math.round((performance.now() - media.startedAt) / 100) / 10;
                this.recording = false;

                const chunks = media.chunks;
                media.chunks = [];

                if (media.discard) {
                    media.discard = false;
                    return;
                }

                const blob = new Blob(chunks, { type: String(type).split(';')[0] || 'video/webm' });
                if (blob.size > 0) {
                    media.video = blob;
                    this.videoUrl = URL.createObjectURL(blob);
                    this.collectPageContext();
                } else {
                    this.error = this.recordingUnavailableMessage;
                }

                this.openModal();
            },

            // The page where the recording ends; the screenshot route gets this from the inspector's capture.
            collectPageContext() {
                const inspector = window.FeedbackHubInspector;
                if (this.followUpUrl) this.capturePageContext();
                if (this.sessionData || !inspector) return;

                this.sessionData = inspector.collectContext?.() || null;
                this.consoleErrors = window.__feedbackHubConsoleErrors || [];
                this.networkRequests = window.__feedbackHubNetworkRequests || [];
                this.formState = inspector.collectFormState?.() || null;
            },

            // Firefox cannot share a single tab: it records a window or a screen, side panel and tab titles included.
            // Firefox does say where the page sits on screen (mozInnerScreenX/Y), so the recording is cut to the page.
            // When the shared surface does not match this window or screen (another window), it stays as it is.
            async cropToPage(display) {
                const track = display.getVideoTracks()[0];
                if (typeof window.mozInnerScreenX !== 'number' || !track || track.getSettings?.().displaySurface === 'browser') return null;

                const source = document.createElement('video');
                source.muted = true;
                source.playsInline = true;
                source.srcObject = display;
                await source.play().catch(() => {});
                if (!source.videoWidth) {
                    await new Promise((resolve) => { source.onloadedmetadata = resolve; setTimeout(resolve, 1500); });
                }

                const first = this.pageRegion(source.videoWidth, source.videoHeight);
                if (!first) {
                    source.srcObject = null;
                    return null;
                }

                const canvas = document.createElement('canvas');
                canvas.width = Math.round(first.width);
                canvas.height = Math.round(first.height);
                const context = canvas.getContext('2d');
                const draw = () => {
                    const region = this.pageRegion(source.videoWidth, source.videoHeight) || first;
                    context.drawImage(source, region.x, region.y, region.width, region.height, 0, 0, canvas.width, canvas.height);
                };
                draw();
                const timer = setInterval(draw, 1000 / 15);
                const stream = canvas.captureStream(15);

                return {
                    track: stream.getVideoTracks()[0],
                    stop() {
                        clearInterval(timer);
                        stream.getTracks().forEach((cropped) => cropped.stop());
                        source.srcObject = null;
                    },
                };
            },

            // Where the page sits in a recorded frame of this window, or of the screen it is on; null when neither fits.
            pageRegion(frameWidth, frameHeight) {
                if (!frameWidth || !frameHeight) return null;

                const surfaces = [
                    { width: window.outerWidth, height: window.outerHeight, left: window.screenX, top: window.screenY },
                    { width: window.screen.width, height: window.screen.height, left: window.screen.left || 0, top: window.screen.top || 0 },
                ];

                for (const surface of surfaces) {
                    const scale = frameWidth / surface.width;
                    if (!scale || Math.abs(surface.height * scale - frameHeight) > Math.max(4, frameHeight * 0.03)) continue;

                    const x = (window.mozInnerScreenX - surface.left) * scale;
                    const y = (window.mozInnerScreenY - surface.top) * scale;
                    const width = window.innerWidth * scale;
                    const height = window.innerHeight * scale;
                    if (x < 0 || y < 0 || x + width > frameWidth + 2 || y + height > frameHeight + 2) continue;

                    return { x, y, width: Math.min(width, frameWidth - x), height: Math.min(height, frameHeight - y) };
                }

                return null;
            },

            stopStreams() {
                media.crop?.stop();
                media.crop = null;
                media.streams.forEach((stream) => stream.getTracks().forEach((track) => track.stop()));
                media.streams = [];
            },

            removeVideo() {
                if (this.videoUrl) URL.revokeObjectURL(this.videoUrl);
                this.videoUrl = null;
                media.video = null;
            },

            // Sends the recording in pieces that stay under the usual upload limits; returns the upload id.
            async uploadVideo() {
                const blob = media.video;
                let upload = null;
                let offset = 0;
                let conflicts = 0;
                let failures = 0;
                this.uploadProgress = 0;

                while (offset < blob.size) {
                    const url = new URL(this.videoChunkEndpoint, window.location.href);
                    if (upload) url.searchParams.set('upload', upload);
                    url.searchParams.set('offset', String(offset));

                    // A long recording is hundreds of pieces: a network hiccup or a busy server retries the piece, not the video.
                    let response;
                    try {
                        response = await this.post(url.toString(), blob.slice(offset, offset + this.videoChunkBytes), { 'Content-Type': 'application/octet-stream' });
                    } catch (error) {
                        response = null;
                    }
                    if ((!response || response.status >= 500 || response.status === 429) && failures++ < 5) {
                        await new Promise((resolve) => setTimeout(resolve, Math.min(8000, 500 * 2 ** failures)));
                        continue;
                    }
                    if (!response) throw new Error('De video kon niet worden verstuurd. Probeer het opnieuw, of verwijder de video.');

                    const data = await response.json().catch(() => ({}));

                    if (this.sessionEnded(response)) throw new Error(this.sessionMessage);

                    // The server tells where the upload stands; continue from there (a retried piece is not added twice).
                    if (response.status === 409 && Number.isInteger(data.offset) && conflicts++ < 3) {
                        upload = data.upload || upload;
                        offset = data.offset;
                        continue;
                    }

                    if (!response.ok) {
                        throw new Error(response.status === 413
                            ? 'De video is te groot. Neem een kortere video op, of verwijder hem.'
                            : 'De video kon niet worden verstuurd. Probeer het opnieuw, of verwijder de video.');
                    }

                    upload = data.upload;
                    offset = data.offset;
                    failures = 0;
                    conflicts = 0;
                    this.uploadProgress = Math.min(100, Math.round((offset / blob.size) * 100));
                }

                return upload;
            },

            reset() {
                if (this.recording) {
                    media.discard = true;
                    this.stopRecording();
                }
                this.removeVideo();
                this.showChoice = false;
                this.undoDraft = null;
                media.draft = null;
                this.pageContext = null;
                this.closedWhileSubmitting = false;
                this.sentVideo = false;
                this.sentReport = null;
                this.followUpState = null;
                this.followUpSummary = null;
                this.followUpQuestions = [];
                this.questionIndex = 0;
                this.answering = false;
                this.otherAnswer = false;
                this.answerText = '';
                this.selectedAnswer = null;
                this.editUrl = null;
                this.followUpLate = false;
                this.activity = [];
                this.error = '';
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
                this.mode = this.chatEnabled ? 'chat' : 'form';
                this.chatMessages = this.chatEnabled ? [{ role: 'assistant', content: 'Waar liep je tegenaan?' }] : [];
                this.chatInput = '';
                this.chatBusy = false;
                this.intent = { goal: '', expected: '', actual: '' };
            },

            chatUnavailableMessage: 'De chat is even niet beschikbaar. Vul het formulier in, dan komt je feedback alsnog aan.',

            async sendTurn() {
                const content = this.chatInput.trim();
                if (!content || this.chatBusy) return;

                this.chatMessages.push({ role: 'user', content });
                // reset() swaps the array; a reply that arrives after a reset is dropped.
                const messages = this.chatMessages;
                this.chatInput = '';
                this.chatBusy = true;
                this.error = '';

                try {
                    const response = await this.postJson(this.chatEndpoint, this.redactPayload({
                        messages: this.chatMessages,
                        url: this.currentUrl(),
                        element_selector: this.elementSelector,
                        screenshot: this.screenshot,
                        console_errors: this.consoleErrors,
                        network_requests: this.networkRequests,
                    }));

                    const data = await response.json().catch(() => ({}));

                    if (this.chatMessages !== messages) return;

                    if (this.sessionEnded(response)) {
                        // Put the answer back in the input; the user logs in and sends it again.
                        this.chatMessages.pop();
                        this.chatInput = content;
                        this.error = this.sessionMessage;
                        return;
                    }

                    if (!response.ok || data.fallback) {
                        this.error = this.chatUnavailableMessage;
                        this.mode = 'form';
                        return;
                    }

                    this.chatMessages.push({ role: 'assistant', content: data.reply });

                    if (data.ready && data.summary) {
                        this.type = data.summary.type || 'bug';
                        this.title = String(data.summary.title || '').slice(0, 255);
                        this.description = String(data.summary.description || '').slice(0, 5000);
                        this.intent = {
                            goal: String(data.summary.goal || '').slice(0, 2000),
                            expected: String(data.summary.expected || '').slice(0, 2000),
                            actual: String(data.summary.actual || '').slice(0, 2000),
                        };
                        this.mode = 'summary';
                    }
                } catch (error) {
                    if (this.chatMessages !== messages) return;

                    this.error = this.chatUnavailableMessage;
                    this.mode = 'form';
                } finally {
                    if (this.chatMessages === messages) this.chatBusy = false;
                }
            },

            async submit() {
                if (this.followUpUrl) {
                    if (!this.description.trim() && !media.video) return;
                    this.title = this.description.trim().replace(/\s+/g, ' ').slice(0, 80) || 'Video-feedback';
                    this.type = 'bug';
                }
                if (!this.title || this.submitting || this.sentReport) return;

                this.error = '';
                this.submitting = true;
                this.closedWhileSubmitting = false;

                try {
                    const videoUpload = media.video ? await this.uploadVideo() : null;
                    const payload = this.redactPayload({
                        type: this.type,
                        title: this.title,
                        description: this.description,
                        url: this.pageContext?.url || this.currentUrl(),
                        element_selector: this.elementSelector,
                        element_rect: this.elementRect,
                        screenshot: this.screenshot,
                        session_data: this.sessionData || this.collectSessionData(),
                        console_errors: this.consoleErrors,
                        network_requests: this.networkRequests,
                        form_state: this.formState,
                        intent: this.intent,
                        transcript: this.chatMessages,
                    });
                    if (videoUpload) {
                        payload.video_upload = videoUpload;
                        payload.activity = this.activity;
                        payload.recording_seconds = this.recordingSeconds;
                    }

                    const response = await this.postJson(this.endpoint, payload);

                    const data = await response.json().catch(() => ({}));
                    if (this.sessionEnded(response)) {
                        throw new Error(this.sessionMessage);
                    }
                    if (!response.ok) {
                        throw new Error(data.message || 'Feedback kon niet worden verstuurd');
                    }

                    // A host may take over the saved report before any follow-up request or panel.
                    if (!window.dispatchEvent(new CustomEvent('feedback-hub:submitted', {
                        detail: { reportId: data.id }, cancelable: true,
                    }))) {
                        this.closeModal();
                        this.reset();
                        return;
                    }

                    if (this.followUpUrl) {
                        this.sentVideo = Boolean(videoUpload);
                        this.sentReport = data.id;
                        if (this.closedWhileSubmitting && !this.showModal) {
                            this.notify('Verstuurd. Terug te vinden bij Mijn feedback.', '', 'success');
                        }
                        this.followUpState = 'waiting';
                        this.loadFollowUp(data.id);
                        return;
                    }
                    this.close();
                    this.notify('Feedback verzonden', data.reference ? `Referentie: ${data.reference}` : '', 'success');
                } catch (error) {
                    this.error = error.message || 'Probeer het opnieuw';
                    this.notify('Er ging iets mis', this.error, 'danger');
                } finally {
                    this.uploadProgress = null;
                    this.submitting = false;
                }
            },

            async loadFollowUp(report) {
                let timer;
                try {
                    const data = await Promise.race([
                        this.postJson(this.followUpUrl, { report }).then(async response => {
                            const data = await response.json();
                            const later = response.status === 202 || data.later === true;
                            if (!response.ok && !later) throw new Error('follow-up');
                            return { ...data, later };
                        }),
                        new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timeout')), this.followUpWaitMs); }),
                    ]);
                    if (this.sentReport !== report) return;
                    this.followUpSummary = data.summary || null;
                    this.followUpLate = data.later === true;
                    this.followUpQuestions = !this.followUpLate && Array.isArray(data.questions) ? data.questions.slice(0, 2) : [];
                    // Links are supplied by the host, but must never execute script.
                    try {
                        const url = new URL(data.edit_url, location.href);
                        this.editUrl = data.edit_url && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
                    } catch (_) {}
                    this.followUpState = this.followUpQuestions.length ? 'question' : 'done';
                } catch (_) {
                    if (this.sentReport !== report) return;
                    this.followUpLate = true;
                    this.followUpState = 'done';
                } finally { clearTimeout(timer); }
            },
            currentQuestion() {
                return this.followUpQuestions[this.questionIndex] || {};
            },
            answerQuestion(answer, skipped = false) {
                if (this.answering || (!skipped && !String(answer || '').trim())) return;
                this.answering = true;
                this.selectedAnswer = answer;
                const report = this.sentReport;
                this.postJson(this.followUpUrl.replace(/\/$/, '') + '/answers', {
                    report, question_id: this.currentQuestion().id,
                    answer: skipped ? null : String(answer).trim().slice(0, 500), skipped,
                }).catch(() => {});
                setTimeout(() => {
                    if (this.sentReport !== report) return;
                    this.questionIndex++;
                    this.otherAnswer = false;
                    this.answerText = '';
                    this.selectedAnswer = null;
                    this.answering = false;
                    if (this.questionIndex >= this.followUpQuestions.length) this.followUpState = 'done';
                }, 250);
            },

            sessionMessage: 'Je bent uitgelogd. Log opnieuw in, dan kun je verder waar je was.',

            csrfToken() {
                return document.querySelector('meta[name="csrf-token"]')?.content
                    || document.querySelector('input[name="_token"]')?.value
                    || window.Livewire?.csrfToken?.();
            },

            // 401 and a redirect to the login page mean the session is gone. A 419 that survives the retry in postJson
            // ends up here too. In every case the text stays in the modal.
            sessionEnded(response) {
                return response.status === 401 || response.status === 419 || response.type === 'opaqueredirect';
            },

            // A 419 means Laravel rejected the CSRF token before anything ran, so one retry with a fresh token is safe.
            // Never retried after a 401 or a redirect to login: the session is gone and the user has to sign in.
            async postJson(url, payload) {
                return this.post(url, JSON.stringify(payload), { 'Content-Type': 'application/json' });
            },

            async post(url, body, headers) {
                // Never attach a session CSRF secret to an external endpoint.
                if (new URL(url, location.href).origin !== location.origin) throw new Error('Feedback-adres moet op dezelfde website staan.');
                const send = (token) => fetch(url, {
                    method: 'POST',
                    redirect: 'manual',
                    credentials: 'same-origin',
                    headers: { ...headers, 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                    body,
                });

                const response = await send(this.csrfToken());
                if (response.status !== 419) return response;

                const token = await this.refreshCsrfToken();

                return token ? await send(token) : response;
            },

            // Resolves to a fresh token only while the user is still signed in; null otherwise.
            async refreshCsrfToken() {
                try {
                    const response = await fetch(this.csrfEndpoint, {
                        redirect: 'manual',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!response.ok) return null;

                    const token = (await response.json()).token;
                    if (!token) return null;

                    const meta = document.querySelector('meta[name="csrf-token"]');
                    if (meta) meta.content = token;

                    return token;
                } catch (error) {
                    return null;
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
                    url: this.pageContext?.url || this.currentUrl(),
                    ...(this.pageContext ? { title: this.pageContext.title } : {}),
                    userAgent: navigator.userAgent,
                    viewport: { width: window.innerWidth, height: window.innerHeight },
                    screen: { width: window.screen.width, height: window.screen.height },
                    locale: navigator.language,
                    timestamp: new Date().toISOString(),
                };
            },
        };
        };
    </script>
@endonce
