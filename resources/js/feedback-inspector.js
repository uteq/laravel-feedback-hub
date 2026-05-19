window.FeedbackHubInspector = {
    active: false,
    selectedElement: null,
    highlightOverlay: null,
    interactionBlocker: null,
    instructionBanner: null,
    boundHandleEscape: null,
    boundHandleDocumentClick: null,
    boundHandlePointerMove: null,
    originalFetch: null,
    originalConsoleError: null,
    originalBodyCursor: null,

    init() {
        if (this.highlightOverlay) return;

        this.boundHandleEscape = this.handleEscape.bind(this);
        this.boundHandleDocumentClick = this.handleDocumentClick.bind(this);
        this.boundHandlePointerMove = this.handlePointerMove.bind(this);
        this.createOverlay();
        this.createInteractionBlocker();
        this.createInstructionBanner();
        this.setupConsoleCapture();
        this.setupNetworkCapture();
    },

    cleanup() {
        if (this.active) this.deactivate();

        document.getElementById('feedback-hub-highlight-overlay')?.remove();
        document.getElementById('feedback-hub-interaction-blocker')?.remove();
        document.getElementById('feedback-hub-instruction-banner')?.remove();
        document.getElementById('feedback-hub-inspector-styles')?.remove();

        this.active = false;
        this.selectedElement = null;
        this.highlightOverlay = null;
        this.interactionBlocker = null;
        this.instructionBanner = null;
    },

    reinit() {
        this.cleanup();
        this.init();
    },

    activate() {
        this.active = true;
        this.originalBodyCursor = document.body.style.cursor || '';
        document.body.style.cursor = 'crosshair';
        this.showInstructionBanner();
        this.showInteractionBlocker();
        document.addEventListener('keydown', this.boundHandleEscape);
        document.addEventListener('click', this.boundHandleDocumentClick, true);
        document.addEventListener('mousemove', this.boundHandlePointerMove, true);
    },

    deactivate() {
        this.active = false;
        this.hideInstructionBanner();
        this.hideInteractionBlocker();
        this.hideOverlay();
        document.body.style.cursor = this.originalBodyCursor || '';
        document.removeEventListener('keydown', this.boundHandleEscape);
        document.removeEventListener('click', this.boundHandleDocumentClick, true);
        document.removeEventListener('mousemove', this.boundHandlePointerMove, true);
    },

    createInstructionBanner() {
        this.instructionBanner = document.createElement('div');
        this.instructionBanner.id = 'feedback-hub-instruction-banner';
        this.instructionBanner.innerHTML = '<strong>Selecteer een element</strong><span>Klik op het onderdeel waarover je feedback wilt geven. ESC annuleert.</span>';
        this.instructionBanner.style.cssText = [
            'position:fixed',
            'top:0',
            'left:0',
            'right:0',
            'z-index:999999',
            'display:none',
            'gap:10px',
            'align-items:center',
            'padding:14px 22px',
            'background:#135d66',
            'color:white',
            'font-family:system-ui,-apple-system,sans-serif',
            'box-shadow:0 8px 24px rgba(15,23,42,.18)'
        ].join(';');
        document.body.appendChild(this.instructionBanner);

        const style = document.createElement('style');
        style.id = 'feedback-hub-inspector-styles';
        style.textContent = '#feedback-hub-instruction-banner span{font-size:13px;opacity:.92}';
        document.head.appendChild(style);
    },

    showInstructionBanner() {
        if (this.instructionBanner) this.instructionBanner.style.display = 'flex';
    },

    hideInstructionBanner() {
        if (this.instructionBanner) this.instructionBanner.style.display = 'none';
    },

    createInteractionBlocker() {
        this.interactionBlocker = document.createElement('div');
        this.interactionBlocker.id = 'feedback-hub-interaction-blocker';
        this.interactionBlocker.style.cssText = 'position:fixed;inset:0;z-index:99998;display:none;pointer-events:none';

        document.body.appendChild(this.interactionBlocker);
    },

    showInteractionBlocker() {
        if (this.interactionBlocker) this.interactionBlocker.style.display = 'block';
    },

    hideInteractionBlocker() {
        if (this.interactionBlocker) this.interactionBlocker.style.display = 'none';
    },

    getElementAtPoint(x, y) {
        const blockerDisplay = this.interactionBlocker?.style.display;
        const overlayDisplay = this.highlightOverlay?.style.display;

        if (this.interactionBlocker) this.interactionBlocker.style.display = 'none';
        if (this.highlightOverlay) this.highlightOverlay.style.display = 'none';

        const element = document.elementFromPoint(x, y);

        if (this.interactionBlocker) this.interactionBlocker.style.display = blockerDisplay;
        if (this.highlightOverlay) this.highlightOverlay.style.display = overlayDisplay;

        if (element === this.instructionBanner || this.instructionBanner?.contains(element)) return null;

        return element;
    },

    handleDocumentClick(event) {
        if (!this.active) return;

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const element = this.getElementAtPoint(event.clientX, event.clientY);
        if (!element || element === document.body || element === document.documentElement) return;

        this.selectedElement = element;
        this.deactivate();
        this.captureAndOpen();
    },

    handlePointerMove(event) {
        if (!this.active) return;

        this.highlightElement(this.getElementAtPoint(event.clientX, event.clientY));
    },

    handleEscape(event) {
        if (event.key === 'Escape' && this.active) this.deactivate();
    },

    createOverlay() {
        this.highlightOverlay = document.createElement('div');
        this.highlightOverlay.id = 'feedback-hub-highlight-overlay';
        this.highlightOverlay.style.cssText = 'position:fixed;pointer-events:none;border:2px solid #135d66;background:rgba(19,93,102,.12);z-index:99999;display:none;transition:all .08s ease';
        document.body.appendChild(this.highlightOverlay);
    },

    highlightElement(element) {
        if (!element || element === document.body || element === document.documentElement) {
            this.hideOverlay();
            return;
        }

        const rect = element.getBoundingClientRect();
        this.highlightOverlay.style.display = 'block';
        this.highlightOverlay.style.top = `${rect.top}px`;
        this.highlightOverlay.style.left = `${rect.left}px`;
        this.highlightOverlay.style.width = `${rect.width}px`;
        this.highlightOverlay.style.height = `${rect.height}px`;
    },

    hideOverlay() {
        if (this.highlightOverlay) this.highlightOverlay.style.display = 'none';
    },

    getSelector(element) {
        if (element.id) return `#${element.id}`;

        const path = [];
        while (element && element.nodeType === Node.ELEMENT_NODE) {
            let selector = element.nodeName.toLowerCase();
            if (element.className && typeof element.className === 'string') {
                const classes = element.className.split(/\s+/).filter((name) => name && !name.includes(':')).slice(0, 2);
                if (classes.length) selector += `.${classes.join('.')}`;
            }
            path.unshift(selector);
            element = element.parentNode;
        }

        return path.slice(-4).join(' > ');
    },

    redactText(value) {
        if (!value) return value;

        return String(value)
            .replace(/([?&])([^=&#]*(?:password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey|key)[^=&#]*)=([^&#\s]*)/gi, '$1$2=[filtered]')
            .replace(/\b(Bearer\s+)[A-Za-z0-9._~+/=-]+/gi, '$1[filtered]')
            .replace(/\b(password|passwd|token|secret|authorization|cookie|csrf|api_key|apikey)\s*[:=]\s*([^\s,;&#]+)/gi, '$1=[filtered]');
    },

    async captureScreenshot() {
        let widget = null;
        let banner = null;
        let track = null;

        try {
            if (navigator.webdriver || !navigator.mediaDevices?.getDisplayMedia || typeof ImageCapture === 'undefined') {
                return null;
            }

            const stream = await navigator.mediaDevices.getDisplayMedia({
                video: { displaySurface: 'browser' },
                preferCurrentTab: true,
                selfBrowserSurface: 'include',
                systemAudio: 'exclude',
            });

            track = stream.getVideoTracks()[0];

            if (this.highlightOverlay) this.highlightOverlay.style.display = 'none';

            widget = document.querySelector('[data-feedback-hub-widget]');
            banner = document.getElementById('feedback-hub-instruction-banner');
            if (widget) widget.style.display = 'none';
            if (banner) banner.style.display = 'none';

            if (this.selectedElement) {
                this.selectedElement.dataset.feedbackHubOriginalOutline = this.selectedElement.style.outline || '';
                this.selectedElement.dataset.feedbackHubOriginalOutlineOffset = this.selectedElement.style.outlineOffset || '';
                this.selectedElement.style.outline = '3px solid #135d66';
                this.selectedElement.style.outlineOffset = '2px';
            }

            await new Promise((resolve) => requestAnimationFrame(resolve));

            const bitmap = await new ImageCapture(track).grabFrame();

            const canvas = document.createElement('canvas');
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
            canvas.getContext('2d').drawImage(bitmap, 0, 0);

            return canvas.toDataURL('image/png');
        } catch (error) {
            console.warn('Feedback screenshot capture failed:', error.message);
            return null;
        } finally {
            if (track) track.stop();
            if (widget) widget.style.display = '';
            if (this.selectedElement) {
                this.selectedElement.style.outline = this.selectedElement.dataset.feedbackHubOriginalOutline || '';
                this.selectedElement.style.outlineOffset = this.selectedElement.dataset.feedbackHubOriginalOutlineOffset || '';
                delete this.selectedElement.dataset.feedbackHubOriginalOutline;
                delete this.selectedElement.dataset.feedbackHubOriginalOutlineOffset;
            }
        }
    },

    setupConsoleCapture() {
        if (this.originalConsoleError) return;

        window.__feedbackHubConsoleErrors = [];
        this.originalConsoleError = console.error;

        console.error = (...args) => {
            window.__feedbackHubConsoleErrors.push({
                type: 'error',
                message: this.redactText(args.map((value) => String(value)).join(' ')).slice(0, 500),
                timestamp: new Date().toISOString(),
            });
            window.__feedbackHubConsoleErrors = window.__feedbackHubConsoleErrors.slice(-30);
            this.originalConsoleError.apply(console, args);
        };
    },

    setupNetworkCapture() {
        if (this.originalFetch) return;

        window.__feedbackHubNetworkRequests = [];
        this.originalFetch = window.fetch;

        window.fetch = async (...args) => {
            const start = Date.now();
            const url = this.redactText(typeof args[0] === 'string' ? args[0] : args[0]?.url);
            const method = args[1]?.method || 'GET';

            try {
                const response = await this.originalFetch.apply(window, args);
                if (!response.ok) {
                    window.__feedbackHubNetworkRequests.push({
                        url,
                        method,
                        status: response.status,
                        duration: Date.now() - start,
                        timestamp: new Date().toISOString(),
                    });
                    window.__feedbackHubNetworkRequests = window.__feedbackHubNetworkRequests.slice(-30);
                }

                return response;
            } catch (error) {
                window.__feedbackHubNetworkRequests.push({
                    url,
                    method,
                    error: error.message,
                    duration: Date.now() - start,
                    timestamp: new Date().toISOString(),
                });
                window.__feedbackHubNetworkRequests = window.__feedbackHubNetworkRequests.slice(-30);
                throw error;
            }
        };
    },

    collectContext() {
        return {
            url: this.redactText(window.location.href),
            userAgent: navigator.userAgent,
            viewport: { width: window.innerWidth, height: window.innerHeight },
            screen: { width: screen.width, height: screen.height },
            locale: navigator.language,
            timestamp: new Date().toISOString(),
        };
    },

    collectFormState() {
        const fields = {};

        document.querySelectorAll('input, select, textarea').forEach((element, index) => {
            const type = (element.type || element.tagName.toLowerCase()).toLowerCase();
            const name = element.name || element.id || `field_${index}`;
            const normalized = name.toLowerCase();

            if (['password', 'hidden'].includes(type)) return;
            if (['token', 'secret', 'csrf', 'password', 'authorization', 'cookie'].some((part) => normalized.includes(part))) return;

            fields[name] = {
                type,
                filled: type === 'checkbox' || type === 'radio' ? Boolean(element.checked) : Boolean(element.value),
            };
        });

        return fields;
    },

    async captureAndOpen() {
        const rect = this.selectedElement?.getBoundingClientRect();

        window.dispatchEvent(new CustomEvent('feedback-hub-captured', {
            detail: {
                screenshot: null,
                elementSelector: this.selectedElement ? this.getSelector(this.selectedElement) : null,
                elementRect: rect ? { x: rect.x, y: rect.y, width: rect.width, height: rect.height } : null,
                sessionData: this.collectContext(),
                consoleErrors: window.__feedbackHubConsoleErrors || [],
                networkRequests: window.__feedbackHubNetworkRequests || [],
                formState: this.collectFormState(),
            },
        }));

        const screenshot = await this.captureScreenshot();

        if (screenshot) {
            window.dispatchEvent(new CustomEvent('feedback-hub-screenshot-captured', {
                detail: { screenshot },
            }));
        }
    },
};

function initializeFeedbackHubInspector() {
    window.FeedbackHubInspector.init();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeFeedbackHubInspector, { once: true });
} else {
    initializeFeedbackHubInspector();
}

document.addEventListener('livewire:navigated', () => {
    window.FeedbackHubInspector.reinit();
});
