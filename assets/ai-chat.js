class AiChat extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this.isOpen = false;
        this.messages = [];
        this.maxStoredMessages = 60;
        this.isLoading = false;
        this.isAutoSendPending = false;
        this.autoSendTimer = null;
        this.autoSendDelayMs = 5000;
        this.statusPlaceholderTimer = null;
        this.statusPlaceholderIndex = 0;
        this.resetCountdownTimer = null;
        this.resetCountdownSeconds = 0;
        // Läuft noch eine Antwort (auch streamend), während der Nutzer eine neue
        // Nachricht schickt oder den Chat zurücksetzt, wird sie sauber abgebrochen
        // statt mit der neuen Antwort um den Chat-Verlauf zu wettlaufen.
        this.activeRequestController = null;
        // Widget-Übersetzungen (Buttons/Platzhalter/Meldungen - NICHT die
        // KI-Antwort). Leer bis load() aufgelöst hat; t() liefert bis dahin
        // einfach den mitgegebenen deutschen Fallback-Text zurück, nichts
        // wartet darauf oder bricht ohne es.
        this.i18n = {};
        this.personalization = {
            active: false,
            step: 'none', // none, ask_mode, ask_name
            mode: 'formal', // formal (Sie), informal (Du)
            userName: ''
        };
    }

    // Kurzform für window.AiChatI18n.t() gegen die aktuell geladenen
    // Übersetzungen dieser Instanz (siehe assets/ai-i18n.js).
    t(key, fallback, vars) {
        if (window.AiChatI18n && typeof window.AiChatI18n.t === 'function') {
            return window.AiChatI18n.t(this.i18n, key, fallback, vars);
        }
        return fallback || key;
    }

    // Patcht die wenigen statischen Textattribute aus dem render()-Template
    // (Platzhalter/aria-labels/Titles), NACHDEM die Übersetzungen geladen
    // sind - render() selbst bleibt synchron und zeigt bis dahin weiterhin
    // sofort den deutschen Standardtext, kein Warten/Flackern für den
    // deutschsprachigen Regelfall.
    applyTranslations() {
        const input = this.shadowRoot.querySelector('.chat-input');
        if (input) {
            input.setAttribute('placeholder', this.t('input_placeholder', 'Nachricht schreiben...'));
            input.setAttribute('aria-label', this.t('input_aria_label', 'Nachricht'));
        }
        const toggle = this.shadowRoot.querySelector('.chat-toggle');
        if (toggle) {
            toggle.setAttribute('aria-label', this.t('toggle_aria_label', 'Chat öffnen'));
        }
        const resizeHandle = this.shadowRoot.querySelector('.resize-handle');
        if (resizeHandle) {
            resizeHandle.setAttribute('title', this.t('resize_title', 'Größe ändern'));
        }
        const closeBtn = this.shadowRoot.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.setAttribute('title', this.t('close_title', 'Schließen'));
        }
    }

    connectedCallback() {
        if (!this.hasAttribute('mode')) {
            this.setAttribute('mode', 'bubble');
        }

        this.render();
        this.setupEventListeners();

        if (window.AiChatI18n && typeof window.AiChatI18n.load === 'function') {
            window.AiChatI18n.load(this.getAttribute('ui-language') || 'de').then((dict) => {
                this.i18n = dict || {};
                this.applyTranslations();
            });
        }

        try {
            this.loadState();
            this.applyPersonalizationConfigGuard();
        } catch (e) {
            console.error('AiChat error in loadState:', e);
        }

        this.updateScopeAccent();
        this.updateMaxLength();

        // Initial greeting if no messages
        if (this.messages.length === 0) {
            const greeting = this.getAttribute('greeting') || 'Hallo! Wie kann ich Ihnen helfen?';
            this.addMessage('bot', greeting);
        }
        
        // Inline mode: always open
        if (this.getAttribute('mode') === 'inline') {
            const container = this.shadowRoot.querySelector('.chat-container');
            if (container) container.classList.add('open');
            this.isOpen = true;
            setTimeout(() => this.initPersonalization(), 500);
        }

        // Anchored mode: kein eigener Toggle-Button, oeffnet sich ueber ein
        // Auslöser-Element auf der Seite (siehe setupExternalTrigger()). Ein per
        // sessionStorage wiederhergestellter "war offen"-Zustand (loadState() oben) wird
        // hier bewusst NICHT uebernommen - ohne erneuten Klick auf den Ausloeser fehlt die
        // Positionsangabe (siehe positionNearTrigger()), der Chat wuerde sonst unsichtbar an
        // der Standardposition "offen" sein. pointer-events muss von Anfang an "none" sein
        // (nicht erst nach dem ersten open()/close()), sonst wuerde die unsichtbare, aber
        // wegen fester Breite/Hoehe vorhandene Host-Flaeche Klicks auf darunterliegende
        // Seiteninhalte abfangen, bevor ueberhaupt geoeffnet wurde.
        if (this.getAttribute('mode') === 'anchored') {
            this.isOpen = false;
            this.style.pointerEvents = 'none';
            const anchoredContainer = this.shadowRoot.querySelector('.chat-container');
            if (anchoredContainer) anchoredContainer.classList.remove('open');
            this.setupExternalTrigger();
        }

        // Set title if provided
        const title = this.getAttribute('title');
        if (title) {
            const headerTitle = this.shadowRoot.querySelector('.chat-title');
            if (headerTitle) headerTitle.textContent = title;
        }
    }

    disconnectedCallback() {
        this.stopBusyPlaceholderAnimation();
    }

    open() {
        this.isOpen = true;
        this.updateUiState();
        this.saveState();
    }

    close() {
        if (this.getAttribute('mode') === 'inline') return;
        this.clearAutoSendTimer();
        this.isOpen = false;
        this.updateUiState();
        this.saveState();
    }

    updateUiState() {
        const container = this.shadowRoot.querySelector('.chat-container');
        const toggleBtn = this.shadowRoot.querySelector('.chat-toggle');

        if (this.isOpen) {
            if (container) container.classList.add('open');
            if (toggleBtn) toggleBtn.classList.add('open');
            const input = this.shadowRoot.querySelector('.chat-input');
            if (input) setTimeout(() => input.focus(), 100);

            // Trigger personalization on first open
            setTimeout(() => this.initPersonalization(), 500);
        } else {
            if (container) container.classList.remove('open');
            if (toggleBtn) toggleBtn.classList.remove('open');
        }

        // Anchored-Modus hat keinen sichtbaren Toggle-Button, der als Klickflaeche dient -
        // der Host selbst braucht daher explizit pointer-events:none, solange geschlossen,
        // sonst wuerde seine unsichtbare (aber wegen fester Breite/Hoehe vorhandene) Flaeche
        // Klicks auf darunterliegende Seiteninhalte abfangen, bevor ueberhaupt geoeffnet wurde.
        if (this.getAttribute('mode') === 'anchored') {
            this.style.pointerEvents = this.isOpen ? 'auto' : 'none';
        }
    }

    toggleChat() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    // Anchored-Modus (mode="anchored", kein eigener schwebender Button): jeder Klick auf ein
    // Element mit der Klasse "aichat" oder der ID "aichat" irgendwo auf der Seite oeffnet/
    // schliesst den Chat, positioniert an genau diesem Element. Ein einziger delegierter
    // Listener auf document statt einer Direktbindung, damit auch Ausloeser funktionieren,
    // die erst nach dem Verbinden dieses Custom Elements ins DOM kommen (z.B. per JS
    // nachtraeglich eingefuegtes Menu).
    setupExternalTrigger() {
        const selector = this.getAttribute('trigger-selector') || '.aichat, #aichat';
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest ? event.target.closest(selector) : null;
            if (!trigger) return;
            event.preventDefault();
            if (!this.isOpen) {
                this.positionNearTrigger(trigger);
            }
            this.toggleChat();
        });
    }

    // Berechnet, ob genug Platz unterhalb des Ausloesers ist (nach unten aufklappen) oder
    // nicht (nach oben aufklappen), und setzt die Fenster-Position einmalig beim Oeffnen als
    // feste Koordinaten (position:fixed vom :host, siehe CSS) - bewusst nicht laufend beim
    // Scrollen neu berechnet ("fixiert" wie gewuenscht, nicht am Ausloeser "klebend").
    positionNearTrigger(trigger) {
        const rect = trigger.getBoundingClientRect();
        const gap = 12;
        const styles = getComputedStyle(this);
        const panelWidth = parseInt(styles.getPropertyValue('--ai-chat-width'), 10) || 350;
        const panelHeight = parseInt(styles.getPropertyValue('--ai-chat-height'), 10) || 500;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        const spaceBelow = viewportHeight - rect.bottom;
        const spaceAbove = rect.top;
        const openDownward = spaceBelow >= panelHeight + gap || spaceBelow >= spaceAbove;

        this.style.top = '';
        this.style.bottom = '';
        this.style.left = '';
        this.style.right = '';

        if (openDownward) {
            const top = Math.min(rect.bottom + gap, Math.max(gap, viewportHeight - panelHeight - gap));
            this.style.top = top + 'px';
        } else {
            const bottom = Math.max(gap, viewportHeight - rect.top + gap);
            this.style.bottom = bottom + 'px';
        }

        let left = rect.left;
        if (left + panelWidth + gap > viewportWidth) {
            left = Math.max(gap, viewportWidth - panelWidth - gap);
        }
        this.style.left = Math.max(gap, left) + 'px';
    }

    saveState() {
        const messages = this.messages.slice(-this.maxStoredMessages);
        const scopeSelector = this.shadowRoot.querySelector('.scope-selector');
        const state = {
            isOpen: this.isOpen,
            messages,
            personalization: this.personalization,
            scope: scopeSelector ? scopeSelector.value : (this.getAttribute('scope') || 'frontend')
        };
        sessionStorage.setItem('ai_chat_state', JSON.stringify(state));
    }

    getConversationHistory(currentMessage) {
        // isPersonalizationPrompt/isPersonalizationAnswer (Sie/Du-Frage + Antwort, siehe
        // addMessage()-Aufrufe fuer die Personalisierung) sind reine UI-Meta-Nachrichten, keine
        // echte Konversation - zaehlten hier sonst als "vorherige Nutzer-Nachricht" mit, wodurch
        // ChatQueryService::process() die eigentliche erste Frage nie als Konversationsanfang
        // erkennt (faqPrecacheEnabled bleibt dadurch bei aktiver Personalisierung IMMER aus,
        // siehe dortiges "!$hasPreviousUserMessage").
        const history = this.messages
            .filter((entry) => entry && (entry.type === 'user' || entry.type === 'bot') && typeof entry.text === 'string' && !entry.isPersonalizationPrompt && !entry.isPersonalizationAnswer)
            .map((entry) => ({
                role: entry.type === 'user' ? 'user' : 'assistant',
                text: (entry.isHtml ? this.stripHtml(entry.text) : entry.text).trim()
            }))
            .filter((entry) => entry.text !== '')
            .slice(-7);

        if (history.at(-1)?.role === 'user' && history.at(-1)?.text === currentMessage) {
            history.pop();
        }

        return history;
    }

    // Liest den aktuell gewaehlten Eintrag des Scope-Selectors und uebersetzt ihn in
    // {scope, profileId} - der Selector-Wert kann "frontend", "developer" oder
    // "profile:<id>" sein (siehe render(), profileOptions). "profile:<id>" bedeutet
    // serverseitig weiterhin scope=frontend, nur eben mit einer explizit gewaehlten
    // Profil-ID statt der statischen profile-id des Widgets.
    getSelectedScopeAndProfileId() {
        const selector = this.shadowRoot.querySelector('.scope-selector');
        const raw = selector ? selector.value : (this.getAttribute('scope') || 'frontend');

        if (raw.startsWith('profile:')) {
            return { scope: 'frontend', profileId: raw.slice('profile:'.length) };
        }
        if (raw === 'developer') {
            return { scope: 'developer', profileId: null };
        }
        return { scope: 'frontend', profileId: this.getAttribute('profile-id') || null };
    }

    // Markiert den Chat-Container mit dem aktuell aktiven Scope (Frontend/Developer), damit
    // eine per CSS zugeordnete Akzentfarbe unmittelbar erkennen lässt, in welchem Modus man ist.
    // Nur aktiv, wenn scope-accent="true" gesetzt ist (ausschließlich der Backend-Chat) – im
    // Frontend-Widget sollen weiterhin nur die individuellen Branding-Einstellungen (primary-color,
    // avatar-url, ...) gelten, unbeeinflusst vom Scope.
    updateScopeAccent() {
        const container = this.shadowRoot.querySelector('.chat-container');
        if (!container) return;

        if (this.getAttribute('scope-accent') !== 'true') {
            delete container.dataset.scope;
            return;
        }

        container.dataset.scope = this.getSelectedScopeAndProfileId().scope;
    }

    // Setzt das maxlength-Attribut der Eingabe passend zum aktuell gewählten Scope
    // (Backend/Developer darf großzügiger sein als Frontend, siehe Settings "Verhalten & Antworten").
    updateMaxLength() {
        const input = this.shadowRoot.querySelector('.chat-input');
        if (!input) return;

        const scope = this.getSelectedScopeAndProfileId().scope;
        const attrName = scope === 'developer' ? 'max-length-backend' : 'max-length-frontend';
        const maxLength = parseInt(this.getAttribute(attrName) || '0', 10);

        if (maxLength > 0) {
            input.setAttribute('maxlength', String(maxLength));
        } else {
            input.removeAttribute('maxlength');
        }
    }

    getPersonalizationMode() {
        const mode = (this.getAttribute('personalization-mode') || 'off').toLowerCase();
        if (mode === 'simple' || mode === 'name') {
            return mode;
        }

        return 'off';
    }

    applyPersonalizationConfigGuard() {
        if (this.getPersonalizationMode() !== 'off') {
            return;
        }

        // Global off must always win over any stale session state.
        this.personalization.step = 'none';
        this.personalization.mode = 'formal';
        this.personalization.userName = '';
        this.personalization.active = false;

        if (this.messages.length === 0) {
            return;
        }

        // Erkennt Onboarding-Nachrichten ueber die bei addMessage() gesetzte Markierung
        // (isPersonalizationPrompt/isPersonalizationAnswer), nicht mehr ueber hartes
        // Text-/Regex-Matching gegen deutsche Formulierungen - die Anzeige-Texte sind seit
        // der Widget-i18n (siehe assets/i18n/*.json) uebersetzt und wuerden sonst je nach
        // ui-language nicht mehr erkannt.
        this.messages = this.messages.filter((msg) => {
            if (!msg) {
                return true;
            }

            if (msg.type === 'user' && msg.isPersonalizationAnswer) {
                return false;
            }

            if (msg.type === 'bot' && msg.isPersonalizationPrompt) {
                return false;
            }

            return true;
        });

        const messagesContainer = this.shadowRoot && this.shadowRoot.querySelector('.chat-messages');
        if (messagesContainer) {
            messagesContainer.innerHTML = '';
            this.messages.forEach((msg) => {
                const messageEl = document.createElement('div');
                messageEl.className = `message message-${msg.type}`;

                if (msg.isHtml) {
                    messageEl.innerHTML = msg.text;
                    this.enhanceCodeBlocks(messageEl);
                } else {
                    messageEl.innerHTML = this.formatMessage(msg.text);
                }

                messagesContainer.appendChild(messageEl);
            });
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        this.saveState();
    }

    loadState() {
        const saved = sessionStorage.getItem('ai_chat_state');
        if (saved) {
            const state = JSON.parse(saved);
            this.isOpen = state.isOpen;
            this.messages = (state.messages || []).slice(-this.maxStoredMessages);
            this.personalization = state.personalization || this.personalization;

            // Zuletzt gewählten Scope (Frontend/Developer) wiederherstellen, statt nach jedem
            // Seitenwechsel/Neu-Rendern immer auf das statische "scope"-Attribut zurückzufallen.
            const scopeSelector = this.shadowRoot.querySelector('.scope-selector');
            if (scopeSelector && state.scope) {
                scopeSelector.value = state.scope;
            }
            this.updateScopeAccent();
            this.updateMaxLength();

            // Restore UI state
            if (this.isOpen) {
                const container = this.shadowRoot.querySelector('.chat-container');
                const toggleBtn = this.shadowRoot.querySelector('.chat-toggle');
                container.classList.add('open');
                toggleBtn.classList.add('open');
            }

            // Restore messages
            // Clear default welcome message if we have history
            if (this.messages.length > 0) {
                const messagesContainer = this.shadowRoot.querySelector('.chat-messages');
                messagesContainer.innerHTML = '';
                this.messages.forEach(msg => {
                    // Re-render each message
                    const messageEl = document.createElement('div');
                    messageEl.className = `message message-${msg.type}`;
                    
                    // Use stored isHtml flag or fallback to type check for backward compatibility
                    const isHtml = msg.isHtml || msg.type === 'bot';
                    
                    if (isHtml) {
                        messageEl.innerHTML = msg.text;
                        this.enhanceCodeBlocks(messageEl);
                    } else {
                        messageEl.innerHTML = this.formatMessage(msg.text);
                    }

                    // Restore buttons if any (only for active personalization step)
                    if (msg.buttons && msg.buttons.length > 0 && this.personalization.step === 'ask_mode') {
                        const btnGroup = document.createElement('div');
                        btnGroup.className = 'btn-group';
                        btnGroup.style.marginTop = '10px';
                        btnGroup.style.display = 'flex';
                        btnGroup.style.gap = '5px';
                        btnGroup.style.flexWrap = 'wrap';

                        msg.buttons.forEach(btn => {
                            const b = document.createElement('button');
                            b.textContent = btn.label;
                            b.className = 'chat-btn';
                            b.style.padding = '5px 12px';
                            b.style.borderRadius = '15px';
                            b.style.border = '1px solid var(--ai-chat-primary)';
                            b.style.background = 'white';
                            b.style.color = 'var(--ai-chat-primary)';
                            b.style.cursor = 'pointer';
                            b.style.fontSize = '12px';
                            
                            b.addEventListener('click', () => {
                                btnGroup.remove();
                                this.handlePersonalization(btn.value);
                            });
                            btnGroup.appendChild(b);
                        });
                        messageEl.appendChild(btnGroup);
                    }
                    
                    // Add copy listeners
                    messageEl.querySelectorAll('.copy-btn').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            const code = e.target.closest('.code-block').querySelector('code').innerText;
                            navigator.clipboard.writeText(code).then(() => {
                                const originalText = e.target.textContent;
                                e.target.textContent = this.t('code_copied', 'Copied!');
                                setTimeout(() => e.target.textContent = originalText, 2000);
                            });
                        });
                    });

                    messagesContainer.appendChild(messageEl);
                });
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        // Trigger personalization on load if open
        if (this.isOpen) {
             setTimeout(() => this.initPersonalization(), 1000);
        }
    }

    startQuery(message) {
        if (!this.isOpen) this.open();
        const input = this.shadowRoot.querySelector('.chat-input');
        if (input) {
            input.value = message;
            this.sendMessage();
        }
    }

    async sendMessage(retryMessage = null) {
        if (this.isLoading) return;

        this.clearAutoSendTimer();

        const input = this.shadowRoot.querySelector('.chat-input');
        const message = retryMessage || input.value.trim();
        if (!message) return;

        // If personalization is in "ask_name" step, we handle it here instead of AI
        // Only if it's not a retry (retry shouldn't happen during personalization usually)
        if (this.personalization.step === 'ask_name' && !retryMessage) {
            input.value = '';
            this.handlePersonalization(message);
            return;
        }

        // Add user message if not a retry (avoid duplicates if wanted, or just add them)
        // Usually, adding them again is clearer for the history.
        this.addMessage('user', message);

        if (!retryMessage) {
            input.value = '';
            input.style.height = 'auto'; // Reset height
        }
        this.isLoading = true;
        this.updateLoadingState();

        // Eine noch laufende vorherige Antwort abbrechen, statt zwei Antworten
        // gleichzeitig in den Chat-Verlauf schreiben zu lassen.
        if (this.activeRequestController) {
            this.activeRequestController.abort();
        }
        const requestController = new AbortController();
        this.activeRequestController = requestController;

        try {
            let apiUrl = this.getAttribute('api-url') || '/index.php?rex-api-call=ai_chat_query';
            apiUrl = apiUrl.trim();
            
            // Get scope (+ ggf. explizit gewaehlte Profil-ID) vom Selector, falls vorhanden,
            // sonst von den statischen Attributen.
            const { scope, profileId } = this.getSelectedScopeAndProfileId();

            const searchCurrentPageOnly = this.getAttribute('search-current-page-only') === 'true';
            const currentUrl = searchCurrentPageOnly ? window.location.href : null;

            const persMode = this.personalization.mode || 'formal';
            const userName = this.personalization.userName || '';
            const personalizationMode = this.getPersonalizationMode();
            const personalizationPayload = personalizationMode === 'off'
                ? null
                : {
                    mode: persMode,
                    name: userName
                };

            // Make sure the URL is absolute if it's relative? 
            // Most browsers handle relative URLs in fetch, but let's be safe.
            let fetchUrl = apiUrl;
            if (apiUrl.startsWith('/')) {
                fetchUrl = window.location.origin + apiUrl;
            }

            const streamEnabled = this.getAttribute('stream-enabled') === 'true';
            const fetchOptions = {
                method: 'POST',
                headers: {
                    'Accept': streamEnabled ? 'text/event-stream, application/json' : 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(streamEnabled ? { 'X-AiChat-Stream': 'true' } : {}),
                },
                body: JSON.stringify({
                    message,
                    scope,
                    mode: 'chat',
                    include_followups: false,
                    current_url: currentUrl,
                    history: this.getConversationHistory(message),
                    personalization: personalizationPayload,
                    stream: streamEnabled,
                    profile_id: profileId
                }),
                signal: requestController.signal
            };

            // Ein einzelner Retry mit kurzer Verzögerung nur bei echten Netzwerkfehlern
            // (fetch() wirft dafür einen TypeError, nicht bei einer regulären
            // HTTP-Fehlerantwort) - ein flackerndes WLAN soll nicht sofort zur
            // "Verbindungsproblem"-Fehlermeldung führen.
            let response;
            try {
                response = await fetch(fetchUrl, fetchOptions);
            } catch (networkError) {
                if (networkError.name === 'AbortError') {
                    throw networkError;
                }
                await new Promise((resolve) => setTimeout(resolve, 800));
                response = await fetch(fetchUrl, fetchOptions);
            }

            if (!response.ok) {
                 const text = await response.text();
                 throw new Error('Server returned error HTTP ' + response.status + ': ' + text.substring(0, 100));
            }

            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            if (streamEnabled && contentType.includes('text/event-stream')) {
                await this.handleSseResponse(response, scope, message, fetchUrl);
                return;
            }

            const data = await response.json();
            
            if (data.error) {
                this.addMessage('system', this.t('error_prefix', 'Fehler: {error}', { error: data.error }));
            } else {
                // The API now returns HTML
                this.addMessage('bot', data.answer, true);

                // Follow-up suggestions are loaded asynchronously to keep first response fast.
                if (scope === 'frontend') {
                    this.fetchFollowUpQuestions(fetchUrl, {
                        message,
                        scope,
                        answer: data.answer_text || this.stripHtml(data.answer || '')
                    });
                }

                // Show follow-up question chips if provided
                if (Array.isArray(data.follow_up_questions) && data.follow_up_questions.length > 0) {
                    this.showFollowUpQuestions(data.follow_up_questions);
                }
            }

        } catch (e) {
            // Ein AbortError durch eine neuere Nachricht/ein Reset ist kein Fehler,
            // den der Nutzer sehen muss - die neue Anfrage übernimmt die UI bereits.
            if (e.name !== 'AbortError') {
                console.error(e);
                this.addMessage('bot', this.t('error_network', 'Da gab es leider ein Verbindungsproblem. Möchtest du es noch einmal versuchen?'), false, [
                    { label: this.t('retry_label', 'Wiederholen'), value: '__retry__' }
                ]);
            }
        } finally {
            // Nur zurücksetzen, wenn zwischenzeitlich keine neuere Anfrage gestartet wurde
            // (deren eigener Controller sonst hier faelschlich mit geloescht wuerde).
            if (this.activeRequestController === requestController) {
                this.activeRequestController = null;
            }
            this.isLoading = false;
            this.updateLoadingState();
        }
    }

    // Parses the fetch ReadableStream as it arrives and renders each "chunk"
    // event immediately, so the answer grows live instead of appearing all at once.
    async handleSseResponse(response, scope, message, fetchUrl) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let streamedText = '';

        const botMessageWrapper = { element: null };
        const messagesContainer = this.shadowRoot.querySelector('.chat-messages');

        // While chunks stream in, auto-scroll must not fight the user's own scrolling.
        // As soon as the user touches the scroll (wheel/touch/scrollbar drag), we stop
        // forcing the view back until this message is done.
        let userScrolledAway = false;
        const markUserScrolled = () => { userScrolledAway = true; };
        if (messagesContainer) {
            messagesContainer.addEventListener('wheel', markUserScrolled, { passive: true });
            messagesContainer.addEventListener('touchmove', markUserScrolled, { passive: true });
            messagesContainer.addEventListener('pointerdown', markUserScrolled, { passive: true });
        }
        const stopScrollTracking = () => {
            if (!messagesContainer) return;
            messagesContainer.removeEventListener('wheel', markUserScrolled);
            messagesContainer.removeEventListener('touchmove', markUserScrolled);
            messagesContainer.removeEventListener('pointerdown', markUserScrolled);
        };

        const ensureBotMessageElement = () => {
            if (botMessageWrapper.element) {
                return botMessageWrapper.element;
            }
            if (!messagesContainer) {
                return null;
            }
            const messageEl = document.createElement('div');
            messageEl.className = 'message message-bot';
            messagesContainer.appendChild(messageEl);
            botMessageWrapper.element = messageEl;
            return messageEl;
        };

        const appendChunk = (chunkText) => {
            const messageEl = ensureBotMessageElement();
            if (!messageEl) return;
            streamedText += chunkText;
            messageEl.innerHTML = this.formatMessage(streamedText);
            this.enhanceCodeBlocks(messageEl);
            if (!userScrolledAway) {
                this.scrollToMessageStart(messagesContainer, messageEl, 'bot');
            }
        };

        const finalizeBotMessage = (html, followUpQuestions) => {
            const messageEl = ensureBotMessageElement();
            if (messageEl) {
                messageEl.innerHTML = html;
                this.enhanceCodeBlocks(messageEl);
                if (!userScrolledAway) {
                    this.scrollToMessageStart(messagesContainer, messageEl, 'bot');
                }
            }

            this.messages.push({ type: 'bot', text: html, isHtml: true, buttons: [] });
            if (this.messages.length > this.maxStoredMessages) {
                this.messages = this.messages.slice(-this.maxStoredMessages);
            }
            this.saveState();

            if (scope === 'frontend') {
                const answerText = this.stripHtml(html || '');
                this.fetchFollowUpQuestions(fetchUrl, { message, scope, answer: answerText });
            }

            if (Array.isArray(followUpQuestions) && followUpQuestions.length > 0) {
                this.showFollowUpQuestions(followUpQuestions);
            }
        };

        const processFrame = (part) => {
            const lines = part.split(/\n/);
            let eventName = 'message';
            let eventData = '';
            for (const line of lines) {
                if (!line) continue;
                if (line.startsWith('event:')) {
                    eventName = line.slice(6).trim();
                    continue;
                }
                if (line.startsWith('data:')) {
                    eventData += (eventData ? '\n' : '') + line.slice(5).trim();
                }
            }
            if (!eventData) return;

            let payload = null;
            try {
                payload = JSON.parse(eventData);
            } catch (err) {
                return;
            }

            if (eventName === 'chunk' && typeof payload.text === 'string') {
                appendChunk(payload.text);
            } else if (eventName === 'complete') {
                finalizeBotMessage(payload.answer || '', payload.follow_up_questions);
            } else if (eventName === 'error') {
                const errorText = typeof payload.message === 'string' ? payload.message : this.t('error_generic', 'Es ist ein Fehler aufgetreten.');
                this.addMessage('bot', errorText, false, [{ label: this.t('retry_label', 'Wiederholen'), value: '__retry__' }]);
            }
        };

        try {
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const parts = buffer.split(/\n\n/);
                buffer = parts.pop() || '';
                for (const part of parts) {
                    processFrame(part);
                }
            }

            if (buffer.trim()) {
                processFrame(buffer);
            }
        } finally {
            stopScrollTracking();
        }
    }


    async fetchFollowUpQuestions(fetchUrl, payload) {
        try {
            const response = await fetch(fetchUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    mode: 'followups',
                    message: payload.message,
                    scope: payload.scope,
                    answer: payload.answer,
                })
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (Array.isArray(data.follow_up_questions) && data.follow_up_questions.length > 0) {
                this.showFollowUpQuestions(data.follow_up_questions);
            }
        } catch (e) {
            // Folgefragen sind optional; Fehler werden bewusst ignoriert.
        }
    }

    stripHtml(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return (div.textContent || div.innerText || '').trim();
    }

    escapeHtmlAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // meta: optionale, sprachunabhängige Markierung (z.B. { isPersonalizationFlow: true }) -
    // ermöglicht es applyPersonalizationConfigGuard() (siehe dort), zu einer Personalisierungs-
    // Onboarding-Nachricht gehörende Nachrichten zu erkennen, OHNE den (jetzt übersetzten,
    // siehe assets/i18n/*.json) angezeigten Text hart gegen deutsche Strings zu vergleichen.
    addMessage(type, text, isHtml = false, buttons = [], meta = {}) {
        this.messages.push({ type, text, isHtml, buttons, ...meta });
        if (this.messages.length > this.maxStoredMessages) {
            this.messages = this.messages.slice(-this.maxStoredMessages);
        }

        const messagesContainer = this.shadowRoot.querySelector('.chat-messages');
        const messageEl = document.createElement('div');
        messageEl.className = `message message-${type}`;
        
        if (isHtml) {
            messageEl.innerHTML = text;
            this.enhanceCodeBlocks(messageEl);
        } else {
            messageEl.innerHTML = this.formatMessage(text);
        }

        if (buttons.length > 0) {
            const btnGroup = document.createElement('div');
            btnGroup.className = 'btn-group';
            btnGroup.style.marginTop = '10px';
            btnGroup.style.display = 'flex';
            btnGroup.style.gap = '5px';
            btnGroup.style.flexWrap = 'wrap';

            buttons.forEach(btn => {
                const b = document.createElement('button');
                b.textContent = btn.label;
                b.className = 'chat-btn';
                b.style.padding = '5px 12px';
                b.style.borderRadius = '15px';
                b.style.border = '1px solid var(--ai-chat-primary)';
                b.style.background = 'white';
                b.style.color = 'var(--ai-chat-primary)';
                b.style.cursor = 'pointer';
                b.style.fontSize = '12px';
                
                b.addEventListener('click', () => {
                    btnGroup.remove();
                    this.handlePersonalization(btn.value);
                });
                btnGroup.appendChild(b);
            });
            messageEl.appendChild(btnGroup);
        }
        
        // Add copy listeners for code blocks
        messageEl.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const code = e.target.closest('.code-block').querySelector('code').innerText;
                navigator.clipboard.writeText(code).then(() => {
                    const originalText = e.target.textContent;
                    e.target.textContent = this.t('code_copied', 'Copied!');
                    setTimeout(() => e.target.textContent = originalText, 2000);
                });
            });
        });

        messagesContainer.appendChild(messageEl);

        if (type === 'user' || type === 'bot') {
            this.activateMessageHighlight(messageEl, type);
        }

        this.scrollToMessageStart(messagesContainer, messageEl, type);
        this.saveState();
    }

    activateMessageHighlight(messageEl, type) {
        if (!(messageEl instanceof HTMLElement)) {
            return;
        }

        window.requestAnimationFrame(() => {
            messageEl.classList.remove('message-fresh', `message-fresh-${type}`);

            window.requestAnimationFrame(() => {
                messageEl.classList.add('message-fresh', `message-fresh-${type}`);

                window.setTimeout(() => {
                    messageEl.classList.remove('message-fresh', `message-fresh-${type}`);
                }, 1800);
            });
        });
    }

    scrollToMessageStart(messagesContainer, messageEl, type) {
        if (!(messagesContainer instanceof HTMLElement) || !(messageEl instanceof HTMLElement)) {
            return;
        }

        requestAnimationFrame(() => {
            if (type === 'bot') {
                const header = this.shadowRoot.querySelector('.chat-header');
                const headerOffset = header instanceof HTMLElement ? header.offsetHeight : 0;
                messagesContainer.scrollTop = Math.max(0, messageEl.offsetTop - headerOffset - 12);
                return;
            }

            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        });
    }

    isNearBottom(messagesContainer, thresholdPx = 48) {
        if (!(messagesContainer instanceof HTMLElement)) {
            return true;
        }

        return messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight <= thresholdPx;
    }

    showFollowUpQuestions(questions) {
        const messagesContainer = this.shadowRoot.querySelector('.chat-messages');
        const chipGroup = document.createElement('div');
        chipGroup.className = 'follow-up-chips';
        chipGroup.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 4px;padding-left:4px;';

        questions.forEach(q => {
            const chip = document.createElement('button');
            chip.textContent = q;
            chip.style.cssText = [
                'background:transparent',
                'border:1px solid var(--ai-chat-primary,#007bff)',
                'color:var(--ai-chat-primary,#007bff)',
                'border-radius:16px',
                'padding:4px 12px',
                'font-size:12px',
                'cursor:pointer',
                'transition:background 0.15s,color 0.15s',
            ].join(';');

            chip.addEventListener('mouseenter', () => {
                chip.style.background = 'var(--ai-chat-primary,#007bff)';
                chip.style.color = '#fff';
            });
            chip.addEventListener('mouseleave', () => {
                chip.style.background = 'transparent';
                chip.style.color = 'var(--ai-chat-primary,#007bff)';
            });
            chip.addEventListener('click', () => {
                chipGroup.remove();
                this.sendMessage(q);
            });
            chipGroup.appendChild(chip);
        });

        // Only stick to the bottom if the user is already there (e.g. short answer).
        // For long answers the user is reading from the top of the message, so
        // silently appending the chips below must not yank the view away.
        const shouldStickToBottom = this.isNearBottom(messagesContainer);
        messagesContainer.appendChild(chipGroup);
        if (shouldStickToBottom) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }


    enhanceCodeBlocks(container) {
        container.querySelectorAll('pre code').forEach(codeBlock => {
            const pre = codeBlock.parentElement;
            // Check if already wrapped
            if (pre.parentElement.classList.contains('code-block')) return;

            const wrapper = document.createElement('div');
            wrapper.className = 'code-block';
            
            // Try to get language class
            const langClass = Array.from(codeBlock.classList).find(c => c.startsWith('language-'));
            const lang = langClass ? langClass.replace('language-', '') : 'text';
            
            const header = document.createElement('div');
            header.className = 'code-header';
            header.innerHTML = `<span class="code-lang">${lang}</span><button class="copy-btn">Copy</button>`;
            
            // Wrap
            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(header);
            wrapper.appendChild(pre);
        });
    }

    formatMessage(text) {
        // Some providers return literal escape sequences like "\\n\\n".
        // Convert them to real line breaks before escaping/HTML rendering.
        const normalizedText = String(text || '')
            .replace(/\\r\\n/g, '\n')
            .replace(/\\n/g, '\n')
            .replace(/\\r/g, '\n');

        // 1. Escape HTML to prevent XSS (basic)
        const escapeHtml = (unsafe) => {
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        };

        // 2. Split by code blocks
        const parts = normalizedText.split(/```(\w+)?\n([\s\S]*?)```/g);
        
        let result = '';
        
        for (let i = 0; i < parts.length; i++) {
            // Even indices are normal text, Odd indices are code (lang, code, lang, code...)
            // Wait, split with capturing groups works like: [text, lang, code, text, lang, code...]
            
            if (i % 3 === 0) {
                // Normal text
                let normalText = escapeHtml(parts[i]);
                const markdownLinks = [];

                normalText = normalText.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (match, label, url) => {
                    const placeholder = `@@KLXM_LINK_${markdownLinks.length}@@`;
                    markdownLinks.push(`<a href="${url}" target="_blank" rel="noopener noreferrer">${label}</a>`);
                    return placeholder;
                });

                normalText = normalText.replace(/https?:\/\/[^\s<]+[^\s<.,;:!?)]/gi, '<a href="$&" target="_blank" rel="noopener noreferrer">$&</a>');
                normalText = normalText.replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, '<a href="mailto:$&">$&</a>');
                
                markdownLinks.forEach((link, index) => {
                    normalText = normalText.replace(`@@KLXM_LINK_${index}@@`, link);
                });

                // Convert lists (lines starting with - )
                normalText = normalText.replace(/^- (.*)$/gm, '• $1');

                // Absatz-Umbrüche (zwei oder mehr aufeinanderfolgende Zeilenumbrüche) bekommen
                // sichtbaren zusätzlichen Abstand statt nur zweier <br> hintereinander (deren
                // Abstand je nach line-height/Container kaum auffällt und dadurch wie ein
                // fehlender Absatz wirkt) - bewusst weiterhin <br>-basiert (kein <p>), da dieser
                // Text-Abschnitt mit Code-Block-HTML im selben Elternelement gemischt wird.
                normalText = normalText
                    .replace(/\n{2,}/g, '<br><br class="ai-chat-paragraph-break">')
                    .replace(/\n/g, '<br>');

                // Convert **bold** to <strong>
                normalText = normalText.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

                result += normalText;
            } else if (i % 3 === 1) {
                // Language (captured group 1) - skip, handled in next step
                continue;
            } else if (i % 3 === 2) {
                // Code (captured group 2)
                const lang = parts[i-1] || 'text';
                const code = escapeHtml(parts[i]); // Escape code content
                
                result += `<div class="code-block">
                    <div class="code-header">
                        <span class="code-lang">${lang}</span>
                        <button class="copy-btn">Copy</button>
                    </div>
                    <pre><code class="language-${lang}">${code}</code></pre>
                </div>`;
            }
        }
        
        return result;
    }

    updateLoadingState() {
        const inputArea = this.shadowRoot.querySelector('.chat-input-area');
        const input = this.shadowRoot.querySelector('.chat-input');
        const submitButton = this.shadowRoot.querySelector('button[type="submit"]');
        if (!inputArea || !input || !submitButton) {
            return;
        }

        const basePlaceholder = 'Nachricht schreiben...';
        const statusTexts = this.isAutoSendPending && !this.isLoading
            ? ['KI arbeitet… Bitte warten…', 'KI recherchiert… Bitte warten…', 'KI startet… Bitte warten…']
            : ['KI arbeitet… Bitte warten…', 'KI recherchiert… Bitte warten…', 'KI antwortet… Bitte warten…'];

        if (this.isLoading || this.isAutoSendPending) {
            inputArea.classList.add('is-busy');
            input.setAttribute('aria-busy', 'true');
            this.startBusyPlaceholderAnimation(input, statusTexts);
            submitButton.disabled = this.isLoading;
            submitButton.setAttribute('title', statusTexts[statusTexts.length - 1]);
        } else {
            inputArea.classList.remove('is-busy');
            this.stopBusyPlaceholderAnimation();
            input.placeholder = basePlaceholder;
            input.removeAttribute('aria-busy');
            submitButton.disabled = false;
            submitButton.setAttribute('title', 'Senden');
        }
    }

    startBusyPlaceholderAnimation(input, statusTexts) {
        if (!Array.isArray(statusTexts) || statusTexts.length === 0) {
            return;
        }

        const normalizedTexts = statusTexts.filter((text) => typeof text === 'string' && text !== '');
        if (normalizedTexts.length === 0) {
            return;
        }

        if (this.statusPlaceholderTimer) {
            clearInterval(this.statusPlaceholderTimer);
            this.statusPlaceholderTimer = null;
        }

        this.statusPlaceholderIndex = 0;
        input.placeholder = normalizedTexts[this.statusPlaceholderIndex];

        if (normalizedTexts.length === 1) {
            return;
        }

        this.statusPlaceholderTimer = setInterval(() => {
            this.statusPlaceholderIndex = (this.statusPlaceholderIndex + 1) % normalizedTexts.length;
            input.placeholder = normalizedTexts[this.statusPlaceholderIndex];
        }, 1400);
    }

    stopBusyPlaceholderAnimation() {
        if (!this.statusPlaceholderTimer) {
            return;
        }

        clearInterval(this.statusPlaceholderTimer);
        this.statusPlaceholderTimer = null;
        this.statusPlaceholderIndex = 0;
    }

    clearAutoSendTimer() {
        if (this.autoSendTimer) {
            clearTimeout(this.autoSendTimer);
            this.autoSendTimer = null;
        }

        if (this.isAutoSendPending) {
            this.isAutoSendPending = false;
            this.updateLoadingState();
        }
    }

    scheduleAutoSend() {
        this.clearAutoSendTimer();

        const input = this.shadowRoot.querySelector('.chat-input');
        if (!input) {
            return;
        }

        const messageSnapshot = input.value.trim();
        if (messageSnapshot === '') {
            this.isAutoSendPending = false;
            this.updateLoadingState();
            return;
        }

        this.isAutoSendPending = true;
        this.updateLoadingState();

        this.autoSendTimer = setTimeout(() => {
            const currentValue = input.value.trim();
            const isInline = this.getAttribute('mode') === 'inline';

            this.autoSendTimer = null;
            this.isAutoSendPending = false;
            this.updateLoadingState();

            if (!this.isLoading && (this.isOpen || isInline) && currentValue !== '' && currentValue === messageSnapshot) {
                this.sendMessage();
            }
        }, this.autoSendDelayMs);
    }

    setupEventListeners() {
        const toggleBtn = this.shadowRoot.querySelector('.chat-toggle');
        toggleBtn.addEventListener('click', () => this.toggleChat());

        // Gewählten Scope (Frontend/Developer/Profil) sofort persistieren, damit er nach einem
        // Seitenwechsel im Backend erhalten bleibt statt auf "developer" zurückzufallen.
        const scopeSelector = this.shadowRoot.querySelector('.scope-selector');
        if (scopeSelector) {
            scopeSelector.addEventListener('change', () => {
                // getConversationHistory() schickt die letzten Nachrichten unabhaengig vom
                // gewaehlten Scope als Konversationsverlauf mit - ohne Reset haette ein
                // Themenwechsel (z.B. Developer -> Profil "Standard") die KI weiterhin im
                // Kontext/Ton des vorherigen Scopes antworten lassen, obwohl Scope/Profil-ID
                // fuer die naechste Anfrage serverseitig laengst korrekt gewechselt sind (siehe
                // Nutzer-Report: Antworten "klangen" nach dem Wechsel weiterhin nach Developer).
                // Ein Scope-Wechsel ist ein Wechsel zu einem anderen Assistenten/Wissens-Scope,
                // kein Fortsetzen desselben Gespraechs - der sichtbare Reset macht das auch fuer
                // den Nutzer eindeutig, statt einen scheinbar fortlaufenden aber innerlich
                // gespaltenen Chat zu zeigen.
                this.resetChat();
                this.updateScopeAccent();
                this.updateMaxLength();
                this.saveState();
            });
        }

        const form = this.shadowRoot.querySelector('.chat-input-area');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.clearAutoSendTimer();
            this.sendMessage();
        });

        const input = this.shadowRoot.querySelector('.chat-input');
        
        // Stop all key events from bubbling to prevent REDAXO shortcuts
        const stopPropagation = (e) => e.stopPropagation();
        input.addEventListener('keyup', stopPropagation);
        input.addEventListener('keypress', stopPropagation);

        input.addEventListener('keydown', (e) => {
            // Stop propagation to prevent global shortcuts (like REDAXO backend shortcuts)
            e.stopPropagation();
            
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.clearAutoSendTimer();
                this.sendMessage();
            }
        });

        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
            this.scheduleAutoSend();
        });

        // Resize Logic
        const handle = this.shadowRoot.querySelector('.resize-handle');
        const container = this.shadowRoot.querySelector('.chat-container');
        
        let startX, startY, startWidth, startHeight;

        const doDrag = (e) => {
            const position = this.getAttribute('position') || 'bottom-right';
            let newWidth;
            
            if (position === 'bottom-left') {
                // Anchor is bottom-left, handle is top-right. Dragging right (increasing X) increases width.
                newWidth = startWidth + (e.clientX - startX);
            } else {
                // Anchor is bottom-right, handle is top-left. Dragging left (decreasing X) increases width.
                newWidth = startWidth + (startX - e.clientX);
            }

            const newHeight = startHeight + (startY - e.clientY);
            
            // Min dimensions
            if (newWidth > 300) container.style.width = newWidth + 'px';
            if (newHeight > 400) container.style.height = newHeight + 'px';
        };

        const stopDrag = () => {
            container.style.transition = ''; // Restore transition
            document.documentElement.removeEventListener('mousemove', doDrag);
            document.documentElement.removeEventListener('mouseup', stopDrag);
        };

        handle.addEventListener('mousedown', (e) => {
            container.style.transition = 'none'; // Disable transition during drag
            startX = e.clientX;
            startY = e.clientY;
            startWidth = parseInt(document.defaultView.getComputedStyle(container).width, 10);
            startHeight = parseInt(document.defaultView.getComputedStyle(container).height, 10);
            
            document.documentElement.addEventListener('mousemove', doDrag);
            document.documentElement.addEventListener('mouseup', stopDrag);
            e.preventDefault(); // Prevent text selection
        });
        const resetBtn = this.shadowRoot.querySelector('.reset-btn');
        resetBtn.addEventListener('click', () => {
            const countdown = this.getResetCountdownSeconds();
            if (countdown > 0) {
                this.startResetCountdown(resetBtn, countdown);
                return;
            }

            if (confirm(this.t('reset_confirm_text', 'Möchten Sie den Chat-Verlauf wirklich löschen und neu starten?'))) {
                this.resetChat();
            }
        });

        const copyBtn = this.shadowRoot.querySelector('.copy-history-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyChatHistory(copyBtn));
        }

        const closeBtn = this.shadowRoot.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.close());
        }

        // Trigger personalization if enabled
        setTimeout(() => this.initPersonalization(), 1000);
    }

    getResetCountdownSeconds() {
        const value = Number.parseInt(this.getAttribute('reset-countdown') || '0', 10);
        return Number.isFinite(value) && value > 0 ? value : 0;
    }

    getCopyHistoryEnabled() {
        return this.getAttribute('copy-history') === 'true';
    }

    startResetCountdown(button, seconds) {
        if (this.resetCountdownTimer) {
            clearInterval(this.resetCountdownTimer);
            this.resetCountdownTimer = null;
        }

        this.resetCountdownSeconds = seconds;
        const originalHtml = button.innerHTML;
        button.classList.add('countdown-active');
        button.style.minWidth = '34px';
        button.style.minHeight = '28px';

        const finishReset = () => {
            if (this.resetCountdownTimer) {
                clearInterval(this.resetCountdownTimer);
                this.resetCountdownTimer = null;
            }

            this.resetCountdownSeconds = 0;
            button.classList.remove('countdown-active');
            button.innerHTML = originalHtml;
            button.style.minWidth = '';
            button.style.minHeight = '';
            button.setAttribute('title', 'Neuer Chat / Verlauf löschen');
            this.resetChat();
        };

        const updateButton = () => {
            if (this.resetCountdownSeconds <= 0) {
                finishReset();
                return;
            }

            button.innerHTML = '<span style="font-size:11px;font-weight:700;line-height:1;display:inline-block;min-width:22px;text-align:center;">' + this.resetCountdownSeconds + 's</span>';
            button.setAttribute('title', this.t('reset_countdown_title', 'Verlauf wird in {seconds}s gelöscht', { seconds: this.resetCountdownSeconds }));
            this.resetCountdownSeconds -= 1;
        };

        updateButton();
        this.resetCountdownTimer = setInterval(() => {
            updateButton();
        }, 1000);
    }

    copyChatHistory(button) {
        const messages = this.messages
            .filter((msg) => msg && typeof msg.text === 'string' && msg.text.trim() !== '')
            .map((msg) => {
                const label = msg.type === 'user' ? 'User' : 'Assistant';
                return label + ':\n' + msg.text.trim();
            });

        const historyText = messages.join('\n\n');
        if (!historyText) {
            return;
        }

        const fallbackDownload = () => {
            const blob = new Blob([historyText], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'ai-chat-verlauf.txt';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(historyText).then(() => {
                const originalTitle = button.getAttribute('title') || this.t('copy_history_title', 'Chatverlauf kopieren');
                button.setAttribute('title', this.t('copy_history_done', 'Kopiert'));
                const originalSvg = button.innerHTML;
                button.innerHTML = '<span style="font-size:11px; font-weight:700;">✓</span>';
                setTimeout(() => {
                    button.innerHTML = originalSvg;
                    button.setAttribute('title', originalTitle);
                }, 1200);
            }).catch(() => fallbackDownload());
            return;
        }

        fallbackDownload();
    }

    resetChat() {
        this.clearAutoSendTimer();
        // Verhindert, dass eine noch laufende (ggf. streamende) Antwort nach dem
        // Reset verspätet in den frisch geleerten Chat-Verlauf nachträgt.
        if (this.activeRequestController) {
            this.activeRequestController.abort();
            this.activeRequestController = null;
        }
        this.messages = [];
        this.personalization.step = 'none';
        this.personalization.userName = '';
        
        sessionStorage.removeItem('ai_chat_state');
        
        const messagesContainer = this.shadowRoot.querySelector('.chat-messages');
        messagesContainer.innerHTML = '';
        
        const greeting = this.getAttribute('greeting') || 'Hallo! Wie kann ich Ihnen helfen?';
        this.addMessage('bot', greeting);
        
        this.saveState();
        setTimeout(() => this.initPersonalization(), 500);
    }

    initPersonalization() {
        const mode = this.getPersonalizationMode();
        
        if (mode === 'off' || mode === '') {
            this.applyPersonalizationConfigGuard();
            return;
        }
        
        // Skip if there's user interaction
        if (this.messages.some(m => m.type === 'user')) {
            return;
        }

        // Only if we haven't started personalization yet or if step is "none"
        if (this.personalization.step !== 'none') {
            return;
        }

        this.personalization.step = 'ask_mode';
        this.addMessage('bot', this.t('personalization_ask_mode', 'Bevor wir starten: Bevorzugen Sie das förmliche "Sie" oder das freundliche "Du"?'), true, [
            { label: this.t('personalization_formal', 'Sie'), value: 'formal' },
            { label: this.t('personalization_informal', 'Du'), value: 'informal' }
        ], { isPersonalizationPrompt: true });
        this.saveState();
    }

    handlePersonalization(value) {
        if (value === '__retry__') {
            const lastUserMsg = [...this.messages].reverse().find(m => m.type === 'user');
            if (lastUserMsg) {
                this.sendMessage(lastUserMsg.text);
            }
            return;
        }

        const configMode = this.getAttribute('personalization-mode') || 'off';

        if (this.personalization.step === 'ask_mode') {
            this.personalization.mode = value;
            this.addMessage(
                'user',
                value === 'formal' ? this.t('personalization_formal', 'Sie') : this.t('personalization_informal', 'Du'),
                false,
                [],
                { isPersonalizationAnswer: true },
            );

            if (value === 'informal' && configMode === 'name') {
                this.personalization.step = 'ask_name';
                setTimeout(() => {
                    this.addMessage('bot', this.t('personalization_ask_name', 'Sehr gerne! Wie darf ich dich nennen? (Dein Vorname reicht mir völlig)'), false, [], { isPersonalizationPrompt: true });
                }, 500);
            } else {
                this.personalization.step = 'done';
                const msg = value === 'formal'
                    ? this.t('personalization_confirm_formal', 'Alles klar, wir bleiben beim Sie. Wie kann ich Ihnen helfen?')
                    : this.t('personalization_confirm_informal', 'Alles klar, wir bleiben beim Du. Wie kann ich dir helfen?');
                setTimeout(() => {
                    this.addMessage('bot', msg, false, [], { isPersonalizationPrompt: true });
                }, 500);
            }
        } else if (this.personalization.step === 'ask_name') {
            this.personalization.userName = value;
            this.personalization.step = 'done';
            this.addMessage('user', value);
            setTimeout(() => {
                this.addMessage('bot', this.t('personalization_greeting_name', 'Hallo {name}! Freut mich. Wie kann ich dir heute helfen?', { name: value }), false, [], { isPersonalizationPrompt: true });
            }, 500);
        }
        this.saveState();
    }

    render() {
        const position = this.getAttribute('position') || 'bottom-right';
        const primaryColor = this.getAttribute('primary-color') || '#007bff';
        const avatarUrl = this.getAttribute('avatar-url') || '';
        const mode = this.getAttribute('mode') || 'bubble'; // bubble, inline, anchored

        let positionStyles = '';
        if (mode === 'bubble') {
            if (position === 'bottom-left') {
                positionStyles = `
                    :host { left: var(--ai-chat-side, 20px); right: auto; }
                    .chat-container { left: 0; right: auto; }
                    .resize-handle { right: 0; left: auto; cursor: ne-resize; }
                    .resize-handle::before { border-left: none; border-right: 2px solid #ccc; border-top-right-radius: 4px; left: auto; right: 4px; }
                `;
            } else {
                positionStyles = `
                    :host { right: var(--ai-chat-side, 20px); left: auto; }
                    .chat-container { right: 0; left: auto; }
                `;
            }
        }

        const style = `
            <style>
                :host {
                    --ai-chat-primary: ${primaryColor};
                    z-index: var(--ai-chat-zindex, 1000000);
                    font-family: var(--ai-chat-font-family, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif);
                    position: fixed;
                    bottom: var(--ai-chat-bottom, 20px);
                    display: block;
                }

                :host([mode="inline"]) {
                    position: relative;
                    bottom: auto;
                    left: auto;
                    right: auto;
                    width: var(--ai-chat-width, 100%);
                    height: var(--ai-chat-height, 400px);
                }

                /* Kein schwebender Toggle-Button - oeffnet sich stattdessen ueber ein
                   eigenes Auslöser-Element auf der Seite (.aichat/#aichat, siehe
                   setupExternalTrigger()). top/left/right/bottom werden von
                   positionNearTrigger() dynamisch per Inline-Style gesetzt (Position des
                   Ausloesers, nach oben/unten aufklappend je nach verfuegbarem Platz) -
                   hier nur Breite/Hoehe und der "leere" Ausgangszustand.
                */
                :host([mode="anchored"]) {
                    bottom: auto;
                    left: auto;
                    right: auto;
                    width: var(--ai-chat-width, 350px);
                    height: var(--ai-chat-height, 500px);
                }

                .chat-toggle {
                    width: 60px;
                    height: 60px;
                    border-radius: 30px;
                    background: var(--ai-chat-primary, ${primaryColor});
                    color: white;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: transform 0.3s;
                    padding: 0;
                    overflow: hidden;
                }
                
                :host([mode="inline"]) .chat-toggle,
                :host([mode="anchored"]) .chat-toggle {
                    display: none;
                }

                .chat-toggle:hover {
                    transform: scale(1.05);
                }

                .chat-toggle svg {
                    width: 30px;
                    height: 30px;
                    fill: currentColor;
                }

                .chat-toggle img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }

                .chat-container {
                    background: var(--ai-chat-bg, white);
                    border-radius: var(--ai-chat-radius, 12px);
                    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                    transition: all 0.3s ease;
                    position: absolute;
                    bottom: 80px;
                    width: var(--ai-chat-width, 350px);
                    height: var(--ai-chat-height, 500px);
                    opacity: 0;
                    pointer-events: none;
                    transform: translateY(20px);
                }

                .chat-container.open {
                    opacity: 1;
                    pointer-events: all;
                    transform: translateY(0);
                }

                :host([mode="inline"]) .chat-container {
                    position: relative;
                    bottom: auto;
                    width: 100%;
                    height: 100%;
                    opacity: 1;
                    pointer-events: all;
                    transform: none;
                }

                /* Im anchored-Modus IST der Host bereits exakt am richtigen Platz
                   positioniert (siehe positionNearTrigger()) - der Container fuellt ihn nur
                   noch komplett aus, statt sich selbst zusaetzlich relativ zu verschieben. */
                :host([mode="anchored"]) .chat-container {
                    position: relative;
                    bottom: auto;
                    width: 100%;
                    height: 100%;
                    transform: none;
                }

                .chat-header {
                    padding: 15px;
                    background: var(--ai-chat-header-bg, #f8f9fa);
                    border-bottom: 1px solid rgba(0,0,0,0.1);
                    font-weight: bold;
                    display: flex;
                    align-items: center;
                    color: var(--ai-chat-text, #333);
                }

                /* Scope-Akzentfarbe: zeigt auf einen Blick, in welchem Modus der Chat gerade läuft.
                   Bewusst als Bottom-Border statt Top-Border, damit sie nicht an den Resize-Greifer
                   in der oberen linken Ecke stößt. */
                .chat-container[data-scope="frontend"] .chat-header {
                    border-bottom: 3px solid #2196F3;
                }
                .chat-container[data-scope="developer"] .chat-header {
                    border-bottom: 3px solid #B45309;
                }
                .chat-container[data-scope="developer"] .scope-selector {
                    border-color: #B45309;
                    color: #B45309;
                }
                .chat-container[data-scope="frontend"] .scope-selector {
                    border-color: #2196F3;
                    color: #2196F3;
                }

                .header-avatar {
                    width: 24px;
                    height: 24px;
                    border-radius: 50%;
                    margin-right: 8px;
                    object-fit: cover;
                }

                .chat-messages {
                    flex: 1;
                    padding: 15px;
                    overflow-y: auto;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    min-width: 0;
                }

                .message {
                    max-width: var(--ai-chat-message-max-width, 82%);
                    min-width: 0;
                    padding: 10px 14px;
                    border-radius: 12px;
                    font-size: 14px;
                    line-height: 1.4;
                    box-sizing: border-box;
                    overflow-wrap: anywhere;
                    word-break: break-word;
                    white-space: normal;
                }

                .message-fresh {
                    animation: chat-message-fresh-glow 1.8s ease-out forwards;
                }

                .message-fresh-user {
                    --ai-chat-fresh-ring: var(--ai-chat-user-fresh-glow, color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 45%, transparent 55%));
                    box-shadow: 0 0 0 0 var(--ai-chat-user-fresh-glow, color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 45%, transparent 55%));
                }

                .message-fresh-bot {
                    --ai-chat-fresh-ring: var(--ai-chat-bot-fresh-glow, color-mix(in srgb, var(--ai-chat-bot-msg-bg, #f1f3f5) 55%, white 45%));
                    box-shadow: 0 0 0 0 var(--ai-chat-bot-fresh-glow, color-mix(in srgb, var(--ai-chat-bot-msg-bg, #f1f3f5) 55%, white 45%));
                }

                .message > * {
                    max-width: 100%;
                    overflow-wrap: anywhere;
                    word-break: break-word;
                }

                .message-user {
                    align-self: flex-end;
                    background: var(--ai-chat-primary, ${primaryColor});
                    color: var(--ai-chat-user-msg-text, white);
                    border-bottom-right-radius: 2px;
                }

                .message-bot {
                    align-self: flex-start;
                    background: var(--ai-chat-bot-msg-bg, #f1f3f5);
                    color: var(--ai-chat-bot-msg-text, var(--ai-chat-text, #333));
                    border-bottom-left-radius: 2px;
                }
                
                .message-system {
                    align-self: center;
                    font-size: 12px;
                    color: #999;
                    background: transparent;
                }

                /* Markiert einen Absatz-Umbruch (siehe formatMessage()) - <br> selbst
                   akzeptiert kein margin, daher content:"" auf einem eigenen Block. */
                br.ai-chat-paragraph-break {
                    content: "";
                    display: block;
                    margin-top: 6px;
                }

                @keyframes chat-message-fresh-glow {
                    0% {
                        transform: translateY(6px);
                        filter: saturate(1.06);
                    }
                    18% {
                        transform: translateY(0);
                    }
                    35% {
                        box-shadow: 0 0 0 3px var(--ai-chat-fresh-ring, transparent);
                    }
                    100% {
                        transform: translateY(0);
                        box-shadow: 0 0 0 0 transparent;
                        filter: saturate(1);
                    }
                }

                /* Code Block Styles */
                .code-block {
                    background: #2d2d2d;
                    border-radius: 6px;
                    margin: 10px 0;
                    overflow: hidden;
                    font-family: monospace;
                    text-align: left;
                }

                .code-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 5px 10px;
                    background: #444;
                    color: #ddd;
                    font-size: 12px;
                }

                .code-lang {
                    font-weight: bold;
                    text-transform: uppercase;
                }

                .copy-btn {
                    background: transparent;
                    border: 1px solid #666;
                    color: #ddd;
                    border-radius: 4px;
                    padding: 2px 8px;
                    font-size: 10px;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .copy-btn:hover {
                    background: #666;
                    color: white;
                }

                pre {
                    margin: 0;
                    padding: 10px;
                    overflow-x: auto;
                    color: #f8f8f2;
                }

                code {
                    font-family: 'Consolas', 'Monaco', 'Andale Mono', monospace;
                    font-size: 13px;
                }

                .chat-input-area {
                    padding: 15px;
                    border-top: 1px solid rgba(0,0,0,0.1);
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    background: var(--ai-chat-bg, white);
                }

                .chat-input-area.is-busy .chat-input {
                    border-color: color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 70%, white 30%);
                    box-shadow: 0 0 0 2px color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 18%, transparent 82%);
                    animation: chat-input-busy-pulse 1.4s ease-in-out infinite;
                }

                .chat-input {
                    flex: 1;
                    padding: 10px 15px;
                    border: 1px solid var(--ai-chat-input-border, #ddd);
                    border-radius: 20px;
                    outline: none;
                    resize: none;
                    display: block;
                    font-family: inherit;
                    font-size: 14px;
                    height: auto;
                    min-height: 44px;
                    max-height: 120px;
                    box-sizing: border-box;
                    overflow-y: auto;
                    line-height: 1.4;
                    background: var(--ai-chat-input-bg, white);
                    color: var(--ai-chat-input-text, #333);
                }

                .chat-input:focus {
                    border-color: var(--ai-chat-primary, ${primaryColor});
                }

                .chat-input::placeholder {
                    color: color-mix(in srgb, var(--ai-chat-input-text, #333), transparent 50%);
                }

                @keyframes chat-input-busy-pulse {
                    0% {
                        border-color: color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 58%, var(--ai-chat-input-border, #ddd) 42%);
                        box-shadow: 0 0 0 0 color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 22%, transparent 78%);
                    }
                    50% {
                        border-color: var(--ai-chat-primary, ${primaryColor});
                        box-shadow: 0 0 0 4px color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 12%, transparent 88%);
                    }
                    100% {
                        border-color: color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 58%, var(--ai-chat-input-border, #ddd) 42%);
                        box-shadow: 0 0 0 0 color-mix(in srgb, var(--ai-chat-primary, ${primaryColor}) 22%, transparent 78%);
                    }
                }

                button[type="submit"] {
                    background: none;
                    border: none;
                    color: var(--ai-chat-primary, ${primaryColor});
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 8px;
                    transition: transform 0.2s;
                }

                button[type="submit"]:disabled {
                    cursor: default;
                    opacity: 0.55;
                    transform: none;
                }

                button[type="submit"]:hover {
                    transform: scale(1.1);
                }

                button[type="submit"]:disabled:hover {
                    transform: none;
                }

                button[type="submit"] svg {
                    width: 24px;
                    height: 24px;
                    fill: currentColor;
                }

                .resize-handle {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 20px;
                    height: 20px;
                    cursor: nw-resize;
                    z-index: 10;
                }
                
                :host([mode="inline"]) .resize-handle {
                    display: none;
                }

                .resize-handle::before {
                    content: '';
                    position: absolute;
                    top: 4px;
                    left: 4px;
                    width: 10px;
                    height: 10px;
                    border-top: 2px solid #ccc;
                    border-left: 2px solid #ccc;
                    border-top-left-radius: 4px;
                }
                
                .resize-handle:hover::before {
                    border-color: var(--ai-chat-primary);
                }

                .scope-selector {
                    font-size: 12px;
                    padding: 2px 5px;
                    border-radius: 4px;
                    border: 1px solid var(--ai-chat-input-border, #ccc);
                    background: var(--ai-chat-input-bg, white);
                    color: var(--ai-chat-input-text, #333);
                    outline: none;
                }

                .header-actions {
                    margin-left: auto;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .reset-btn,
                .copy-history-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    color: #666;
                    padding: 4px;
                    border-radius: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 28px;
                    min-height: 28px;
                    transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
                }

                .reset-btn:hover,
                .copy-history-btn:hover {
                    background: #eee;
                    color: #333;
                }

                .reset-btn.countdown-active {
                    background: rgba(220, 53, 69, 0.08);
                    color: #c62828;
                    box-shadow: 0 0 0 1px rgba(220, 53, 69, 0.20);
                    font-weight: 700;
                    animation: resetCountdownPulse 1s ease-in-out infinite;
                }

                @keyframes resetCountdownPulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.08); }
                }

                .reset-btn svg,
                .copy-history-btn svg {
                    width: 18px;
                    height: 18px;
                    fill: currentColor;
                }

                .close-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    color: #666;
                    padding: 4px;
                    border-radius: 4px;
                    display: none;
                    align-items: center;
                    justify-content: center;
                }
                
                :host([mode="bubble"]) .close-btn,
                :host([mode="anchored"]) .close-btn {
                    display: flex;
                }

                .close-btn:hover {
                    background: #eee;
                    color: #333;
                }

                .close-btn svg {
                    width: 18px;
                    height: 18px;
                    fill: currentColor;
                }

                .chat-options {
                    padding: 5px 15px;
                    background: var(--ai-chat-header-bg);
                    border-bottom: 1px solid #eee;
                    font-size: 11px;
                    color: #666;
                    display: flex;
                    align-items: center;
                    gap: 5px;
                }

                @media (max-width: 600px) {
                    :host {
                        bottom: 0;
                        left: 0 !important;
                        right: 0 !important;
                        width: 100%;
                        z-index: calc(var(--ai-chat-zindex, 1000000) + 1);
                    }

                    :host([mode="inline"]) {
                        position: relative;
                        width: 100%;
                        height: var(--ai-chat-height, 400px);
                        bottom: auto;
                    }
                    
                    .chat-toggle {
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        z-index: var(--ai-chat-zindex, 1000000);
                    }

                    .chat-container {
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        width: 100% !important;
                        height: 100dvh !important;
                        border-radius: 0;
                        z-index: calc(var(--ai-chat-zindex, 1000000) + 2);
                    }

                    :host([mode="bubble"]) .chat-container.open {
                        transform: none;
                    }

                    .resize-handle {
                        display: none;
                    }
                }

                ${positionStyles}
            </style>
        `;

        const allowSwitch = this.getAttribute('allow-scope-switch') === 'true';
        const currentScope = this.getAttribute('scope') || 'frontend';

        // Der automatisch eingebundene Backend-Chat bekommt statt eines festen "Frontend"-
        // Eintrags die Liste der aktiven Profile zur Auswahl (siehe boot.php, dort als JSON
        // ins "profile-options"-Attribut geschrieben) - Backend-Nutzer koennen so zwischen
        // Developer-Chat und jedem Profil wechseln, ohne die Seite zu wechseln. Das normale
        // Frontend-Widget hat dieses Attribut nicht und verhaelt sich unveraendert
        // (einfacher Frontend/Developer-Umschalter fuer genau sein eigenes Profil).
        let profileOptions = [];
        const rawProfileOptions = this.getAttribute('profile-options');
        if (rawProfileOptions) {
            try {
                const parsed = JSON.parse(rawProfileOptions);
                if (Array.isArray(parsed)) {
                    profileOptions = parsed;
                }
            } catch (e) {
                profileOptions = [];
            }
        }

        const currentProfileId = this.getAttribute('profile-id') || '';
        const currentSelectValue = currentScope === 'developer'
            ? 'developer'
            : (profileOptions.length > 0 ? 'profile:' + currentProfileId : 'frontend');

        const selectorHtml = allowSwitch ? `
            <select class="scope-selector">
                ${profileOptions.length > 0 ? '' : `<option value="frontend" ${currentSelectValue === 'frontend' ? 'selected' : ''}>Frontend</option>`}
                <option value="developer" ${currentSelectValue === 'developer' ? 'selected' : ''}>Developer</option>
                ${profileOptions.map((profile) => `<option value="profile:${profile.id}" ${currentSelectValue === 'profile:' + profile.id ? 'selected' : ''}>${this.escapeHtmlAttr(String(profile.name))}</option>`).join('')}
            </select>
        ` : '';

        let toggleContent = '<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>';
        if (avatarUrl) {
            toggleContent = `<img src="${avatarUrl}" alt="Chat">`;
        }

        let headerAvatarHtml = '';
        if (avatarUrl) {
            headerAvatarHtml = `<img src="${avatarUrl}" class="header-avatar" alt="">`;
        }

        const copyHistoryHtml = this.getCopyHistoryEnabled() ? `
            <button class="copy-history-btn" title="Chatverlauf kopieren" aria-label="Chatverlauf kopieren">
                <svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
            </button>
        ` : '';

        const html = `
            <button class="chat-toggle" aria-label="Chat öffnen">
                ${toggleContent}
            </button>
            <div class="chat-container">
                <div class="resize-handle" title="Größe ändern"></div>
                <div class="chat-header">
                    ${headerAvatarHtml}
                    <span class="chat-title">Chat Assistant</span>
                    <div class="header-actions">
                        ${selectorHtml}
                        ${copyHistoryHtml}
                        <button class="reset-btn" title="Neuer Chat / Verlauf löschen">
                            <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        </button>
                        <button class="close-btn" title="Schließen">
                           <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="chat-messages">
                </div>
                <form class="chat-input-area">
                    <textarea class="chat-input" placeholder="Nachricht schreiben..." rows="1" aria-label="Nachricht"></textarea>
                    <button type="submit" aria-label="Senden">
                        <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                    </button>
                </form>
            </div>
        `;

        this.shadowRoot.innerHTML = style + html;
    }
}

customElements.define('ai-chat', AiChat);
