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
        const media = { recorder: null, streams: [], chunks: [], timer: null, video: null, discard: false };
        const video = config.video || {};

        return {
            endpoint: config.endpoint,
            chatEndpoint: config.chatEndpoint,
            csrfEndpoint: config.csrfEndpoint,
            chatEnabled: Boolean(config.chatEnabled),
            mode: config.chatEnabled ? 'chat' : 'form',
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
            recording: false,
            recordingSeconds: 0,
            videoUrl: null,

            openModal() {
                this.showModal = true;
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

            start() {
                this.reset();

                if (window.FeedbackHubInspector) {
                    window.FeedbackHubInspector.activate();
                    return;
                }

                this.openModal();
            },

            close() {
                this.closeModal();
                this.reset();
            },

            handleCapture(detail) {
                this.screenshot = detail.screenshot;
                this.elementSelector = detail.elementSelector;
                this.elementRect = detail.elementRect;
                this.sessionData = detail.sessionData;
                this.consoleErrors = detail.consoleErrors || [];
                this.networkRequests = detail.networkRequests || [];
                this.formState = detail.formState || null;
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

            async startRecording() {
                if (this.recording || !this.canRecordVideo()) return;

                this.error = '';
                this.removeVideo();
                this.closeModal();

                let display;
                try {
                    display = await navigator.mediaDevices.getDisplayMedia({
                        video: { frameRate: 15 },
                        audio: false,
                        preferCurrentTab: true,
                        selfBrowserSurface: 'include',
                        surfaceSwitching: 'exclude',
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
                const audio = microphone ? microphone.getAudioTracks() : [];
                const stream = new MediaStream([...display.getVideoTracks(), ...audio]);
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

                this.recordingSeconds = 0;
                this.recording = true;
                recorder.start(1000);
                media.timer = setInterval(() => {
                    this.recordingSeconds++;
                    if (this.recordingSeconds >= this.videoMaxSeconds) this.stopRecording();
                }, 1000);
            },

            stopRecording() {
                if (media.recorder && media.recorder.state !== 'inactive') {
                    media.recorder.stop();
                }
            },

            finishRecording(type) {
                clearInterval(media.timer);
                media.timer = null;
                media.recorder = null;
                this.stopStreams();
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
                } else {
                    this.error = this.recordingUnavailableMessage;
                }

                this.openModal();
            },

            stopStreams() {
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

                while (offset < blob.size) {
                    const url = new URL(this.videoChunkEndpoint, window.location.href);
                    if (upload) url.searchParams.set('upload', upload);
                    url.searchParams.set('offset', String(offset));

                    const response = await this.post(url.toString(), blob.slice(offset, offset + this.videoChunkBytes), { 'Content-Type': 'application/octet-stream' });
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
                            ? 'De video is te groot. Neem een kortere video op, of haal hem weg.'
                            : 'De video kon niet worden verstuurd. Probeer het opnieuw, of haal de video weg.');
                    }

                    upload = data.upload;
                    offset = data.offset;
                }

                return upload;
            },

            reset() {
                if (this.recording) {
                    media.discard = true;
                    this.stopRecording();
                }
                this.removeVideo();
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
                if (!this.title || this.submitting) return;

                this.error = '';
                this.submitting = true;

                try {
                    const videoUpload = media.video ? await this.uploadVideo() : null;
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
                        intent: this.intent,
                        transcript: this.chatMessages,
                    });
                    if (videoUpload) payload.video_upload = videoUpload;

                    const response = await this.postJson(this.endpoint, payload);

                    const data = await response.json().catch(() => ({}));
                    if (this.sessionEnded(response)) {
                        throw new Error(this.sessionMessage);
                    }
                    if (!response.ok) {
                        throw new Error(data.message || 'Feedback kon niet worden verstuurd');
                    }

                    this.close();
                    this.notify('Feedback verzonden', data.reference ? `Referentie: ${data.reference}` : '', 'success');
                } catch (error) {
                    this.error = error.message || 'Probeer het opnieuw';
                    this.notify('Er ging iets mis', this.error, 'danger');
                } finally {
                    this.submitting = false;
                }
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
                    url: this.currentUrl(),
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
