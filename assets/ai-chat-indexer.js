/**
 * KLXM Chat – Indexer UI
 * Manages the batch-indexing progress in the REDAXO backend.
 */
(function () {
    'use strict';

    // ── Config (injected via data attribute on #ai-chat-indexer-config) ───
    let config = {};

    // ── State ────────────────────────────────────────────────────────────────
    let tasks              = [];
    let currentTaskIndex   = 0;
    let totalCount         = 0;   // Anzahl Aufgaben (Artikel/URLs/Dateien/...) - NICHT dasselbe wie die Anzahl der daraus entstehenden Index-Abschnitte
    let indexedChunks      = 0;   // Anzahl tatsächlich geschriebener Index-Abschnitte (Chunks) - das ist die Zahl, die am Ende in "Aktuelle Größe" auftaucht
    let successCount       = 0;
    let errorCount         = 0;
    let errorLog           = [];
    let cancelled          = false;
    let startTime          = null;
    let lastActivityTime   = null;
    let heartbeatInterval  = null;
    let currentAbortCtrl   = null;
    let backgroundMode     = false; // true = Hintergrundlauf (Api\ReindexWorker) statt browsergesteuerter Task-Schleife
    let backgroundPollTimer = null;
    let backgroundAvailable = false; // Ergebnis der einmaligen Verfügbarkeitsprüfung beim Laden der Seite (siehe checkBackgroundAvailability())
        let globalButtonHandlersRegistered = false;

    // ── Helpers ──────────────────────────────────────────────────────────────
    const el = id => document.getElementById(id);

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function ensureConfigLoaded() {
        if (config && typeof config.apiBase === 'string' && config.apiBase !== '') {
            return;
        }

        const cfgEl = el('ai-chat-indexer-config');
        if (!cfgEl) {
            config = config || {};
            return;
        }

        try {
            config = JSON.parse(cfgEl.dataset.config || '{}');
        } catch (e) {
            config = {};
        }
    }

    function getApiBase() {
        ensureConfigLoaded();
        return config.apiBase || 'index.php?rex-api-call=ai_chat_index';
    }

    function formatElapsed(ms) {
        const s = Math.floor(ms / 1000);
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return m > 0 ? m + ':' + String(sec).padStart(2, '0') + ' min' : sec + 's';
    }

    function getDelay() {
        const sel = el('ai-chat-delay-select');
        return sel ? parseInt(sel.value, 10) : 0;
    }

    // ── Fetch wrapper with timeout ───────────────────────────────────────────
    async function apiFetch(url, options = {}) {
        const timeout = options.timeout || 120000;
        currentAbortCtrl = new AbortController();
        const tid = setTimeout(() => currentAbortCtrl.abort(), timeout);

        try {
            const response = await fetch(url, {
                ...options,
                signal: currentAbortCtrl.signal,
            });
            clearTimeout(tid);

            if (!response.ok) {
                const body = await response.text();
                throw new Error('HTTP ' + response.status + ': ' + body.substring(0, 300));
            }

            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Ungültige JSON-Antwort vom Server: ' + text.substring(0, 200));
            }
        } catch (e) {
            clearTimeout(tid);
            if (e.name === 'AbortError') {
                throw new Error(
                    'Request-Timeout (' + Math.round(timeout / 1000) + 's). ' +
                    'Ollama / OpenWebUI möglicherweise ausgelastet oder Modell braucht länger.'
                );
            }
            throw e;
        }
    }

    // ── UI helpers ───────────────────────────────────────────────────────────
    function showProgress(visible) {
        const c = el('ai-chat-progress-container');
        if (c) c.style.display = visible ? 'block' : 'none';

        const success = el('ai-chat-success-state');
        if (success) success.style.display = 'none';
    }

    function showSuccessState(message) {
        const container = el('ai-chat-progress-container');
        if (container) container.style.display = 'none';

        const success = el('ai-chat-success-state');
        if (!success) return;

        const text = success.querySelector('.ai-chat-success-copy');
        if (text) {
            text.innerHTML = '<strong>HEUREKA!</strong><span>' + message + '</span>';
        }
        success.style.display = 'flex';
    }

    function updateProgress(percent) {
        const bar = el('ai-chat-progress-bar');
        if (!bar) return;
        bar.style.width = percent + '%';
        bar.textContent = percent + '%';
        bar.setAttribute('aria-valuenow', percent);
    }

    function setStatus(msg, type) {
        const s = el('ai-chat-status-text');
        if (!s) return;
        s.textContent = msg;
        s.className = 'klxm-indexer-status';
        if (type === 'error')   s.classList.add('text-danger');
        if (type === 'success') s.classList.add('text-success');
        if (type === 'warning') s.classList.add('text-warning');
    }

    function setDetail(html) {
        const d = el('ai-chat-detail-text');
        if (d) d.innerHTML = html;
    }

    // Start/Start-Hintergrund/Refresh gemeinsam sperren, solange irgendein Lauf
    // aktiv ist - verhindert, dass z.B. während einer laufenden Foreground-
    // Indexierung zusätzlich noch ein Hintergrundlauf gestartet wird.
    function setRunButtonsDisabled(disabled) {
        ['ai-chat-start-btn'].forEach(function (id) {
            const btn = el(id);
            if (btn) btn.disabled = disabled;
        });
        // Hintergrund-Buttons UND Refresh (das jetzt ebenfalls immer über den
        // Hintergrund-Worker läuft, siehe handleRefresh()) bleiben von einem
        // "Sperren" ausgenommen, wenn sie ohnehin schon wegen fehlender
        // Verfügbarkeit deaktiviert sind - checkBackgroundAvailability() ist die
        // alleinige Quelle für DIESEN Fall.
        [
            'ai-chat-start-background-btn',
            'ai-chat-refresh-btn',
        ].forEach(function (id) {
            const btn = el(id);
            if (btn && backgroundAvailable) btn.disabled = disabled;
        });
        ['ai-chat-cancel-btn'].forEach(function (id) {
            const btn = el(id);
            if (btn) btn.disabled = !disabled;
        });
    }

    // "Aktuelle Größe" (Index-Abschnitte insgesamt) live mitziehen, statt wie
    // bisher beim Seitenaufruf einmal zu berechnen und dann bis zum Ende des
    // Laufs eingefroren zu bleiben - genau das führte dazu, dass die Zahl mitten
    // im Lauf z.B. "128" zeigte, obwohl am Ende 2000 Abschnitte im Index standen.
    function updateIndexCountBadge(value) {
        const badge = el('ai-chat-index-count');
        if (!badge) return;
        if (badge.textContent !== String(value)) {
            badge.textContent = String(value);
            pulseElement(badge);
        }
    }

    function showBackgroundHint(visible) {
        const hint = el('ai-chat-background-hint');
        if (hint) hint.style.display = visible ? 'block' : 'none';
    }

    // Eine einzige, eindeutige Status-Anzeige statt bisher nur Fließtext -
    // nutzt Bootstrap-"label"-Kontextklassen (theme-/dark-mode-kompatibel über
    // das REDAXO-Backend-Theme, siehe CSS in content.php) statt eigener Farben.
    function setStatusBadge(state, text) {
        const badge = el('ai-chat-status-badge');
        if (!badge) return;

        const variants = {
            idle:         { cls: 'label-default', icon: 'fa-circle-o',            running: false, card: 'idle',    text: config.statusIdle || 'Bereit' },
            'running-fg': { cls: 'label-primary', icon: 'fa-refresh',             running: true,  card: 'running', text: config.statusRunningForeground || 'Läuft im Browser' },
            'running-bg': { cls: 'label-info',    icon: 'fa-cloud-upload',        running: true,  card: 'running', text: config.statusRunningBackground || 'Läuft im Hintergrund' },
            success:      { cls: 'label-success', icon: 'fa-check',               running: false, card: 'success', text: config.statusDone || 'Fertig' },
            warning:      { cls: 'label-warning', icon: 'fa-exclamation-triangle', running: false, card: 'warning', text: config.statusDoneWithErrors || 'Fertig mit Fehlern' },
            cancelled:    { cls: 'label-warning', icon: 'fa-stop',                running: false, card: 'warning', text: config.statusCancelled || 'Abgebrochen' },
            error:        { cls: 'label-danger',  icon: 'fa-times',              running: false, card: 'error',   text: config.statusError || 'Fehler' },
        };

        const variant = variants[state] || variants.idle;
        badge.className = 'label ' + variant.cls + (variant.running ? ' klxm-status-running' : '');
        badge.innerHTML = '<i class="rex-icon ' + variant.icon + '"></i> ' + (text || variant.text);

        // Karte (Hintergrund/Rahmen) und der zweifarbige Donut-Spinner folgen
        // demselben Zustand wie das Badge, statt eines separaten Aufrufs an
        // jeder Stelle - ein Zustand, eine Quelle der Wahrheit.
        const container = el('ai-chat-progress-container');
        if (container) {
            container.classList.remove(
                'klxm-progress-card--idle', 'klxm-progress-card--running',
                'klxm-progress-card--success', 'klxm-progress-card--warning', 'klxm-progress-card--error'
            );
            container.classList.add('klxm-progress-card--' + variant.card);
        }

        const donut = el('ai-chat-donut');
        if (donut) {
            donut.style.display = variant.running ? 'block' : 'none';
        }
    }

    // Kurzes Aufblitzen für Zahlen, die sich live aktualisieren (Index-
    // größe, Fortschritt) - macht sichtbar, dass wirklich gerade etwas
    // passiert, statt dass sich Zahlen kommentarlos ändern.
    function pulseElement(element) {
        if (!element) return;
        element.classList.remove('klxm-pulse');
        // Reflow erzwingen, damit die Animation bei schnell aufeinander
        // folgenden Updates jedes Mal neu von vorne startet.
        void element.offsetWidth;
        element.classList.add('klxm-pulse');
    }

    function getProviderLabel(providerKey) {
        const key = String(providerKey || '').trim();
        if (key === '') {
            return 'Provider';
        }

        const labels = (config && typeof config.providerLabels === 'object' && config.providerLabels)
            ? config.providerLabels
            : {};

        return labels[key] ? String(labels[key]) : key;
    }

    function getSourceTypeLabel(sourceType) {
        const key = String(sourceType || '').trim();
        if (key === '') {
            return 'Quelle';
        }

        const labels = (config && typeof config.sourceTypeLabels === 'object' && config.sourceTypeLabels)
            ? config.sourceTypeLabels
            : {};

        if (labels[key]) {
            return String(labels[key]);
        }

        return key.replace(/_/g, ' ');
    }

    function taskLabel(task) {
        if (task.type === 'article')     return 'Artikel&thinsp;ID&nbsp;' + task.id + ' (Sprache&nbsp;' + task.clang + ')';
        if (task.type === 'file')        return 'Addon-Dok: ' + task.addon + '/' + task.relPath;
        if (task.type === 'github_file') return 'GitHub: ' + task.repo + '/' + task.relPath;
        if (task.type === 'url') {
            const value = task.url ? String(task.url) : 'Sitemap URL';
            return 'Sitemap: ' + value;
        }
        if (task.type === 'provider_item') {
            const provider = getProviderLabel(task.provider || '');
            const source = getSourceTypeLabel(task.source_type || '');
            const title = task.title ? String(task.title) : String(task.source_id || 'Eintrag');
            return provider + ' / ' + source + ': ' + title;
        }
        return task.type;
    }

    function taskTypeLabel(task) {
        if (task.type === 'article') return 'Artikel';
        if (task.type === 'url') return 'Sitemap URLs';
        if (task.type === 'file') return 'Addon Doku';
        if (task.type === 'github_file') return 'GitHub Doku';
        if (task.type === 'provider_item') {
            const source = getSourceTypeLabel(task.source_type || '');
            return 'Provider: ' + source;
        }
        return String(task.type || 'Unbekannt');
    }

    function summarizeTaskSources(taskList) {
        const counts = {};

        taskList.forEach(task => {
            const label = taskTypeLabel(task);
            counts[label] = (counts[label] || 0) + 1;
        });

        const enabledProviders = Array.isArray(config.enabledProviders) ? config.enabledProviders : [];
        enabledProviders.forEach(providerKey => {
            const label = 'Provider: ' + String(providerKey);
            if (counts[label] === undefined) {
                counts[label] = 0;
            }
        });

        const sorted = Object.keys(counts).sort();
        return sorted.map(label => label + ': ' + counts[label]).join(' | ');
    }

    function hasProviderTasks(taskList, providerKey) {
        return taskList.some(task => task && task.type === 'provider_item' && String(task.provider || '') === providerKey);
    }

    // ── Heartbeat ────────────────────────────────────────────────────────────
    function startHeartbeat() {
        heartbeatInterval = setInterval(updateHeartbeat, 1000);
    }

    function stopHeartbeat() {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
    }

    function updateHeartbeat() {
        const hb = el('ai-chat-heartbeat');
        if (!hb) return;

        const ago     = Math.round((Date.now() - lastActivityTime) / 1000);
        const elapsed = formatElapsed(Date.now() - startTime);

        let eta = '';
        if (currentTaskIndex > 0 && totalCount > 0) {
            const msPerTask   = (Date.now() - startTime) / currentTaskIndex;
            const remaining   = (totalCount - currentTaskIndex) * msPerTask;
            eta = ' &nbsp;|&nbsp; ~' + formatElapsed(remaining) + ' verbleibend';
        }

        let colorClass = 'text-success';
        let actMsg     = '&#9679; Aktiv &nbsp;|&nbsp; letzter Task: ' + ago + 's';
        if (ago > 30)  { colorClass = 'text-warning'; actMsg = '&#9650; Kein Fortschritt seit ' + ago + 's – Embedding läuft noch…'; }
        if (ago > 90)  { colorClass = 'text-danger';  actMsg = '&#9888; Möglicherweise hängt der Task (' + ago + 's) – Browser NICHT schließen!'; }

        hb.innerHTML =
            '<span class="' + colorClass + '">' + actMsg + '</span>' +
            ' &nbsp;|&nbsp; Laufzeit: ' + elapsed + eta;
    }

    function registerGlobalButtonHandlers() {
        if (globalButtonHandlersRegistered) {
            return;
        }

        document.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target.closest('button') : null;
            if (!target || !(target instanceof HTMLElement)) {
                return;
            }

            switch (target.id) {
                case 'ai-chat-start-btn':
                    event.preventDefault();
                    handleStart();
                    break;
                case 'ai-chat-start-background-btn':
                    event.preventDefault();
                    handleStartBackgroundClick();
                    break;
                case 'ai-chat-force-reset-btn':
                    event.preventDefault();
                    handleForceResetBackground();
                    break;
                case 'ai-chat-refresh-btn':
                    event.preventDefault();
                    handleRefresh();
                    break;
                case 'ai-chat-clear-btn':
                    event.preventDefault();
                    handleClear();
                    break;
                case 'ai-chat-clear-cache-btn':
                    event.preventDefault();
                    handleClearCache();
                    break;
                case 'ai-chat-github-sync-btn':
                    event.preventDefault();
                    handleGithubSync();
                    break;
                case 'ai-chat-optimize-rag-btn':
                    event.preventDefault();
                    handleOptimizeRag();
                    break;
                case 'ai-chat-cancel-btn':
                    event.preventDefault();
                    handleCancel();
                    break;
            }
        }, true);

        globalButtonHandlersRegistered = true;
    }

    // ── Action handlers ──────────────────────────────────────────────────────
    async function handleClear() {
        ensureConfigLoaded();
        if (!confirm(config.confirmClear || 'Index wirklich löschen?')) return;
        const btn = el('ai-chat-clear-btn');
        btn.disabled = true;
        showProgress(true);
        setStatus('Index wird geleert…', 'info');
        try {
            const data = await apiFetch(getApiBase() + '&action=clear');
            if (!data.success) throw new Error(data.error || 'Unbekannter Fehler');
            el('ai-chat-index-count').textContent = '0';
            setStatus('Index geleert.', 'success');
            setTimeout(() => location.reload(), 1000);
        } catch (e) {
            setStatus('Fehler: ' + e.message, 'error');
            btn.disabled = false;
        }
    }

    async function handleClearCache() {
        ensureConfigLoaded();
        if (!confirm(config.confirmClearCache || 'Cache wirklich löschen?')) return;
        const btn = el('ai-chat-clear-cache-btn');
        btn.disabled = true;
        try {
            const data = await apiFetch(getApiBase() + '&action=clear_cache');
            if (!data.success) throw new Error(data.error || 'Unbekannter Fehler');
            alert('Cache geleert.');
            location.reload();
        } catch (e) {
            alert('Fehler: ' + e.message);
            btn.disabled = false;
        }
    }

    async function handleWarmCache() {
        ensureConfigLoaded();
        if (!confirm(config.confirmWarmCache || 'Cache jetzt vorwärmen?')) return;

        const btn = el('ai-chat-warm-cache-btn');
        if (btn) btn.disabled = true;

        try {
            showProgress(true);
            updateProgress(15);
            startTime = Date.now();
            lastActivityTime = Date.now();
            startHeartbeat();
            setStatus(config.warmCacheRunning || 'Cache-Vorwärmung läuft…', '');
            setDetail('<span class="text-muted">FAQ-Fragen werden vorbereitet und in den Cache geschrieben…</span>');
            const data = await apiFetch(getApiBase() + '&action=warm_cache', { timeout: 1800000 });
            if (!data.success) {
                throw new Error(data.error || 'Cache-Vorwärmung fehlgeschlagen');
            }

            const stats = data.stats || {};
            const prepared = Number(stats.prepared || 0);
            const processed = Number(stats.processed || 0);
            const skipped = Number(stats.skipped || 0);
            const errors = Number(stats.errors || 0);

            const processedQuestions = Array.isArray(stats.processed_questions) ? stats.processed_questions : [];
            const skippedQuestions = Array.isArray(stats.skipped_questions) ? stats.skipped_questions : [];
            const previewQuestions = processedQuestions.length > 0
                ? processedQuestions.slice(0, 4).map(q => '<li>' + q.replace(/[<>]/g, '') + '</li>').join('')
                : '<li>Keine Fragen verarbeitet.</li>';
            const skippedPreview = skippedQuestions.length > 0
                ? skippedQuestions.slice(0, 3).map(q => '<li>' + q.replace(/[<>]/g, '') + '</li>').join('')
                : '';

            const message = (config.warmCacheDone || 'Cache-Vorwärmung abgeschlossen')
                + ' — vorbereitet: ' + prepared
                + ', geschrieben: ' + processed
                + ', übersprungen: ' + skipped
                + (errors > 0 ? ', Fehler: ' + errors : '');

            stopHeartbeat();
            updateProgress(100);
            setStatus(message, errors > 0 ? 'warning' : 'success');
            setDetail(
                '<div class="text-muted" style="margin-bottom:6px;">Der Cache ist aktualisiert. Die Anzahl sehen Sie nach einem Neuladen der Seite.</div>' +
                '<div><strong>Bearbeitet:</strong><ul style="margin:6px 0 0 18px;">' + previewQuestions + '</ul></div>' +
                (skippedPreview ? '<div style="margin-top:8px;"><strong>Übersprungen:</strong><ul style="margin:6px 0 0 18px;">' + skippedPreview + '</ul></div>' : '')
            );
            const bar = el('ai-chat-progress-bar');
            if (bar) {
                bar.classList.remove('active');
                bar.classList.add(errors > 0 ? 'progress-bar-warning' : 'progress-bar-success');
            }

            const container = el('ai-chat-progress-container');
            if (container) {
                container.style.display = 'none';
            }

            const success = el('ai-chat-success-state');
            if (success) {
                const copy = success.querySelector('.ai-chat-success-copy');
                if (copy) {
                    copy.innerHTML = '<strong>HEUREKA!</strong><span>' + (config.warmCacheDone || 'Cache-Vorwärmung abgeschlossen') + '</span>';
                }
                success.style.display = 'flex';
            }
        } catch (e) {
            stopHeartbeat();
            setStatus('Fehler: ' + e.message, 'error');
            const bar = el('ai-chat-progress-bar');
            if (bar) {
                bar.classList.remove('active');
                bar.classList.add('progress-bar-danger');
            }
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function handleGithubSync() {
        ensureConfigLoaded();
        const btn = el('ai-chat-github-sync-btn');
        const result = el('github-sync-result');
        if (!btn) return;
        
        btn.disabled = true;
        result.innerHTML = '<i class="rex-icon fa-spinner fa-spin"></i> Lade ZIPs herunter...';
        
        try {
            const data = await apiFetch(getApiBase() + '&action=update_sources');
            if (data.success) {
                result.innerHTML = '<span class="text-success"><i class="rex-icon fa-check"></i> ' + (data.details.messages.join(", ") || "GitHub Quellen aktualisiert") + '</span>';
            } else {
                throw new Error(data.error || "GitHub Update fehlgeschlagen");
            }
        } catch (e) {
            result.innerHTML = '<span class="text-danger"><i class="rex-icon fa-exclamation-triangle"></i> ' + e.message + '</span>';
        } finally {
            btn.disabled = false;
        }
    }

    function handleCancel() {
        cancelled = true;
        if (currentAbortCtrl) currentAbortCtrl.abort();
        const cancelBtn = el('ai-chat-cancel-btn');
        if (cancelBtn) cancelBtn.disabled = true;
        setStatus('Abbruch wird durchgeführt…', 'warning');

        if (backgroundMode) {
            // Timer bewusst NICHT hier stoppen: der nächste bereits geplante
            // Poll-Durchlauf sieht "cancelled" selbst und löst darüber das
            // laufende Promise in pollBackgroundStatus() auf (siehe dort) -
            // sonst würde handleStart() für immer auf runBackground() warten.
            apiFetch(getApiBase() + '&action=stop_background', { method: 'POST', timeout: 10000 }).catch(() => {});
        }
    }

    async function handleOptimizeRag() {
        ensureConfigLoaded();
        const btn = el('ai-chat-optimize-rag-btn');
        const result = el('ai-chat-optimize-rag-result');
        if (!btn) return;

        btn.disabled = true;
        if (result) result.innerHTML = '<i class="rex-icon fa-spinner fa-spin"></i> Ermittle empfohlenes Kandidatenfenster…';

        try {
            const data = await apiFetch(getApiBase() + '&action=optimize_rag_settings');
            if (!data.success) throw new Error(data.error || 'Unbekannter Fehler');

            if (data.changed) {
                if (result) {
                    result.innerHTML = '<span class="text-success"><i class="rex-icon fa-check"></i> RAG-Kandidatenfenster auf ' + data.recommended_limit + ' gesetzt (Index: ' + data.total_chunks + ' Abschnitte).</span>';
                }
                const warningBox = el('ai-chat-rag-limit-warning');
                if (warningBox) {
                    setTimeout(() => { warningBox.style.display = 'none'; }, 2500);
                }
            } else if (result) {
                result.innerHTML = '<span class="text-success"><i class="rex-icon fa-check"></i> Aktueller Wert (' + data.previous_limit + ') ist bereits ausreichend.</span>';
            }
        } catch (e) {
            if (result) result.innerHTML = '<span class="text-danger"><i class="rex-icon fa-exclamation-triangle"></i> ' + e.message + '</span>';
        } finally {
            btn.disabled = false;
        }
    }

    async function handleStart() {
        ensureConfigLoaded();
        cancelled     = false;
        successCount  = 0;
        errorCount    = 0;
        errorLog      = [];
        indexedChunks = 0;
        totalCount    = 0;
        currentTaskIndex = 0;
        backgroundMode = false;
        startTime     = Date.now();
        lastActivityTime = Date.now();

        setRunButtonsDisabled(true);
        setStatusBadge('running-fg');
        showProgress(true);
        updateProgress(0);
        setDetail('');

        const bar = el('ai-chat-progress-bar');
        if (bar) {
            bar.classList.add('active');
            bar.classList.remove('progress-bar-success', 'progress-bar-warning', 'progress-bar-danger');
        }

        startHeartbeat();

        try {
            if ((config.indexSource || 'structure') !== 'sitemap') {
                // Step 1 – Update GitHub sources (up to 5 min for large repos)
                setStatus('GitHub-Quellen werden aktualisiert (ZIP-Download)…', '');
                const sourcesData = await apiFetch(getApiBase() + '&action=update_sources', { timeout: 300000 });
                if (!sourcesData.success) throw new Error(sourcesData.error || 'Fehler beim Quellen-Update');
            }

            // Step 2 – Clear index
            setStatus('Index wird geleert…', '');
            const clearData = await apiFetch(getApiBase() + '&action=clear');
            if (!clearData.success) throw new Error(clearData.error || 'Fehler beim Leeren');
            updateIndexCountBadge(0);

            // Step 3 – Collect tasks
            setStatus('Aufgaben werden gesammelt…', '');
            const collectData = await apiFetch(getApiBase() + '&action=collect');
            if (!collectData.success) throw new Error(collectData.error || 'Fehler beim Sammeln');

            tasks            = collectData.tasks;
            totalCount       = tasks.length;
            currentTaskIndex = 0;

            if (totalCount === 0) {
                finishIndexing();
                return;
            }

            setStatus(totalCount + ' Aufgaben gefunden. Indexierung startet…', '');
            let sourceSummary = '<span class="text-muted">Quellen: ' + summarizeTaskSources(tasks) + '</span>';
            if (Array.isArray(config.enabledProviders) && config.enabledProviders.includes('forcal') && !hasProviderTasks(tasks, 'forcal')) {
                sourceSummary += '<br><span class="text-warning">Hinweis: forcal ist aktiviert, aber es wurden keine forcal-Tasks gefunden.</span>';
            }
            setDetail(sourceSummary);
            await processNextTask();

        } catch (e) {
            stopHeartbeat();
            stopBackgroundPoll();
            if (cancelled) {
                setStatusBadge('cancelled');
                setStatus(
                    'Indexierung abgebrochen nach ' + currentTaskIndex + ' von ' + totalCount +
                    ' Aufgaben (' + indexedChunks + ' Abschnitte indiziert). ✓ ' + successCount + '  ✗ ' + errorCount,
                    'warning'
                );
            } else {
                setStatusBadge('error');
                setStatus('Fehler: ' + e.message, 'error');
            }
            
            // Render partial error log if we have some errors
            if (errorLog.length > 0) {
                let html = '<div style="margin-top: 15px; padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 200px; overflow-y: auto;">';
                html += '<p><strong>' + (config.errorLogPartial || 'Bisher aufgetretene Fehler:') + '</strong></p><ul style="list-style: none; padding-left: 0; font-size: 0.9em;">';
                errorLog.forEach(err => {
                    html += '<li style="margin-bottom: 5px;"><span class="text-danger">&#10007; ' + err.label + '</span>: ' + err.error + '</li>';
                });
                html += '</ul></div>';
                setDetail(html);
            }
            
            setRunButtonsDisabled(false);
            const startBtn = el('ai-chat-start-btn');
            if (startBtn) startBtn.textContent = config.btnRetry || 'Erneut indexieren';
        }
    }

    // ── Hintergrundlauf (Api\ReindexWorker via shell_exec + curl/wget) ──────
    function stopBackgroundPoll() {
        if (backgroundPollTimer) {
            clearTimeout(backgroundPollTimer);
            backgroundPollTimer = null;
        }
    }

    // Eigener, dedizierter Button statt automatischer Moduswahl im "Jetzt
    // indexieren"-Button: der war vorher ein und derselbe Button für zwei
    // grundverschiedene Abläufe (Browser-Tab muss offen bleiben vs. kann
    // geschlossen werden), das war nicht vorhersehbar, welcher Modus gerade
    // läuft. Der Button ist nur aktiv, wenn checkBackgroundAvailability() beim
    // Laden der Seite Hintergrundausführung als möglich gemeldet hat.
    async function handleStartBackgroundClick() {
        ensureConfigLoaded();
        cancelled     = false;
        successCount  = 0;
        errorCount    = 0;
        errorLog      = [];
        indexedChunks = 0;
        totalCount    = 0;
        currentTaskIndex = 0;
        startTime     = Date.now();
        lastActivityTime = Date.now();

        setRunButtonsDisabled(true);
        setStatusBadge('running-bg');
        showProgress(true);
        updateProgress(0);
        setDetail('');

        const bar = el('ai-chat-progress-bar');
        if (bar) {
            bar.classList.add('active');
            bar.classList.remove('progress-bar-success', 'progress-bar-warning', 'progress-bar-danger');
        }

        startHeartbeat();

        try {
            await runBackground();
            finishIndexing();
        } catch (e) {
            stopHeartbeat();
            stopBackgroundPoll();
            showBackgroundHint(false);
            setStatus('Fehler: ' + e.message, 'error');
            setStatusBadge('error');
            setRunButtonsDisabled(false);

            // "Es läuft bereits..." kann bedeuten, dass ein früherer Worker-Prozess
            // abgestürzt ist, ohne seinen eigenen Abschluss-Status noch schreiben zu
            // können (siehe IndexRunStore::readEffective() - nach 10 Minuten ohne
            // Update würde sich das von selbst lösen, das hier ist der sofortige
            // manuelle Weg statt Warten).
            if (/läuft bereits/i.test(e.message || '')) {
                setDetail(
                    '<button type="button" id="ai-chat-force-reset-btn" class="btn btn-default btn-sm">' +
                    '<i class="rex-icon fa-refresh"></i> Hängenden Hintergrundlauf zurücksetzen und neu starten</button>'
                );
            }
        }
    }

    async function handleForceResetBackground() {
        const btn = el('ai-chat-force-reset-btn');
        if (btn) btn.disabled = true;
        try {
            await apiFetch(getApiBase() + '&action=stop_background', { method: 'POST', timeout: 10000 });
        } catch (e) {
            // Best effort - selbst wenn das fehlschlägt, versuchen wir trotzdem den Neustart.
        }
        handleStartBackgroundClick();
    }

    async function runBackground(mode, startLabel) {
        mode = mode || 'full';
        backgroundMode = true;
        setStatus(startLabel || 'Hintergrund-Indizierung wird gestartet…', '');

        const startData = await apiFetch(getApiBase() + '&action=start_background&mode=' + encodeURIComponent(mode), { method: 'POST', timeout: 15000 });
        if (!startData.success) {
            throw new Error(startData.error || 'Hintergrundlauf konnte nicht gestartet werden.');
        }

        showBackgroundHint(true);
        await pollBackgroundStatus();
    }

    function pollBackgroundStatus() {
        return new Promise((resolve, reject) => {
            const poll = async () => {
                if (cancelled) {
                    resolve();
                    return;
                }

                let data;
                try {
                    data = await apiFetch(getApiBase() + '&action=background_status', { timeout: 15000 });
                } catch (e) {
                    // Ein einzelner fehlgeschlagener Poll (z.B. kurzer Netz-Hänger)
                    // soll den ganzen Lauf nicht abbrechen - der Hintergrundprozess
                    // läuft ja unabhängig vom Browser weiter.
                    backgroundPollTimer = setTimeout(poll, 3000);
                    return;
                }

                lastActivityTime = Date.now();

                totalCount       = Number(data.total || 0);
                currentTaskIndex = Number(data.processed || 0);
                indexedChunks    = Number(data.chunks || 0);
                errorCount       = Number(data.errors || 0);
                successCount     = Math.max(0, currentTaskIndex - errorCount);
                errorLog         = Array.isArray(data.error_log) ? data.error_log : [];

                updateIndexCountBadge(indexedChunks);

                if (totalCount > 0) {
                    updateProgress(Math.round((currentTaskIndex / totalCount) * 100));
                    setStatus(
                        'Aufgabe ' + currentTaskIndex + ' von ' + totalCount +
                        ' (Hintergrund) — ' + indexedChunks + ' Abschnitte bereits indiziert…',
                        ''
                    );
                } else {
                    setStatus('Aufgaben werden gesammelt (Hintergrund)…', '');
                }

                if (data.current_label) {
                    setDetail('<span class="text-muted">&#8987; ' + data.current_label + ' – wird verarbeitet…</span>');
                }

                if (data.status === 'error') {
                    reject(new Error(data.message || 'Hintergrundlauf fehlgeschlagen.'));
                    return;
                }

                if (data.status === 'cancelled') {
                    cancelled = true;
                    resolve();
                    return;
                }

                if (data.status === 'done') {
                    resolve();
                    return;
                }

                backgroundPollTimer = setTimeout(poll, 2000);
            };

            poll();
        });
    }

    // Läuft wie "Im Hintergrund indexieren" ueber Api\ReindexWorker (siehe
    // runBackground()/pollBackgroundStatus()), nur mit IndexerService::sync() statt
    // runFull() - ein laengeres inkrementelles Refresh blockiert dadurch weder den
    // Browser-Tab noch riskiert es ein PHP-Timeout auf Shared-Hosting-Umgebungen.
    async function handleRefresh() {
        ensureConfigLoaded();
        cancelled = false;
        successCount = 0;
        errorCount = 0;
        errorLog = [];
        indexedChunks = 0;
        totalCount = 0;
        currentTaskIndex = 0;
        startTime = Date.now();
        lastActivityTime = Date.now();

        setRunButtonsDisabled(true);
        setStatusBadge('running-fg', config.refreshRunning);

        showProgress(true);
        updateProgress(0);
        setDetail('');
        startHeartbeat();

        const bar = el('ai-chat-progress-bar');
        if (bar) {
            bar.classList.add('active');
            bar.classList.remove('progress-bar-success', 'progress-bar-warning', 'progress-bar-danger');
        }

        try {
            await runBackground('incremental', config.refreshRunning || 'Inkrementelles Refresh wird gestartet…');

            // pollBackgroundStatus() aktualisiert processed/chunks/errors laufend,
            // liest aber "skipped" nicht mit ein (nur fuer den Vollbericht am Ende
            // relevant) - dafuer einmalig den finalen Stand direkt nachfragen.
            const finalData = await apiFetch(getApiBase() + '&action=background_status', { timeout: 15000 });
            const processed = Number(finalData.processed || 0);
            const skipped = Number(finalData.skipped || 0);
            const errors = Number(finalData.errors || 0);

            successCount = processed;
            errorCount = errors;

            updateProgress(100);
            stopHeartbeat();
            stopBackgroundPoll();
            showBackgroundHint(false);

            if (bar) {
                bar.classList.remove('active');
                bar.classList.add(errors === 0 ? 'progress-bar-success' : 'progress-bar-warning');
            }

            const elapsed = formatElapsed(Date.now() - startTime);
            const status = (config.refreshDone || 'Refresh fertig') + ' in ' + elapsed +
                ' — aktualisiert: ' + processed + ', unverändert: ' + skipped +
                (errors > 0 ? ', Fehler: ' + errors : '');

            setStatus(status, errors > 0 ? 'warning' : 'success');
            setStatusBadge(errors > 0 ? 'warning' : 'success', errors > 0 ? undefined : (config.refreshDone || 'Refresh fertig'));

            // finalData.chunks ist nur die waehrend dieses Laufs neu geschriebene Anzahl
            // (aus IndexerService::sync()), nicht die tatsaechliche Gesamtgroesse des
            // Index - dafuer wie zuvor separat den echten aktuellen Stand nachfragen.
            try {
                const countData = await apiFetch(getApiBase() + '&action=count', { timeout: 15000 });
                if (countData && countData.success) {
                    updateIndexCountBadge(countData.total);
                }
            } catch (e2) {
                // Anzeige bleibt dann einfach auf dem alten Stand - kein Blocker.
            }
        } catch (e) {
            stopHeartbeat();
            stopBackgroundPoll();
            showBackgroundHint(false);
            if (cancelled) {
                setStatus('Refresh abgebrochen.', 'warning');
                setStatusBadge('cancelled');
            } else {
                setStatus('Refresh-Fehler: ' + e.message, 'error');
                setStatusBadge('error');
            }
        } finally {
            setRunButtonsDisabled(false);
        }
    }

    async function processNextTask() {
        if (cancelled || currentTaskIndex >= totalCount) {
            finishIndexing();
            return;
        }

        const task    = tasks[currentTaskIndex];
        const percent = Math.round((currentTaskIndex / totalCount) * 100);
        updateProgress(percent);

        const label = taskLabel(task);
        const currentTypeLabel = taskTypeLabel(task);
        setStatus(
            'Aufgabe ' + (currentTaskIndex + 1) + ' von ' + totalCount + ' (' + currentTypeLabel + ') — ' +
            indexedChunks + ' Abschnitte bereits indiziert…',
            ''
        );
        setDetail('<span class="text-muted">&#8987; ' + label + ' – Embedding wird berechnet…</span>');

        const formData = new FormData();
        for (const key in task) {
            formData.append('task[' + key + ']', task[key]);
        }

        try {
            const data = await apiFetch(getApiBase() + '&action=process', {
                method:  'POST',
                body:    formData,
                timeout: 300000, // 5 min per task – large articles or slow local models
            });

            lastActivityTime = Date.now();

            if (data.success) {
                ++successCount;
                const title  = data.title  || label;
                const chunks = Number(data.chunks || 0);
                indexedChunks += chunks;
                updateIndexCountBadge(indexedChunks);
                setDetail('&#10003; <strong>' + title + '</strong> &mdash; ' + chunks + ' Abschnitt(e) &mdash; insgesamt ' + indexedChunks + ' im Index');
            } else {
                ++errorCount;
                const errMsg = (data.error || 'Unbekannter Fehler');
                errorLog.push({ label: label, error: errMsg });
                console.warn('Klxm Indexer – Fehler bei Element:', task, data.error);
                setDetail(
                    '<span class="text-danger">&#10007; ' + label + '</span>' +
                    ' &mdash; ' + errMsg
                );
            }
        } catch (e) {
            if (cancelled) return;
            ++errorCount;
            const errMsg = e.message;
            errorLog.push({ label: label, error: errMsg });
            lastActivityTime = Date.now(); // update so heartbeat doesn't alarm falsely
            console.warn('Klxm Indexer – Exception bei Element:', task, e);
            setDetail(
                '<span class="text-danger">&#10007; ' + label + '</span>' +
                ' &mdash; ' + errMsg
            );
        }

        ++currentTaskIndex;

        const delay = getDelay();
        if (delay > 0 && !cancelled) await sleep(delay);

        await processNextTask();
    }

    function finishIndexing() {
        stopHeartbeat();
        showBackgroundHint(false);
        updateProgress(100);

        const bar = el('ai-chat-progress-bar');
        if (bar) {
            bar.classList.remove('active');
            bar.classList.add(errorCount === 0 ? 'progress-bar-success' : 'progress-bar-warning');
        }

        const elapsed = formatElapsed(Date.now() - startTime);
        // Zwei unterschiedliche Zahlen bewusst getrennt ausweisen: "Aufgaben"
        // (Artikel/URLs/Dateien/...) sind NICHT dasselbe wie "Abschnitte" (die
        // tatsächlichen Zeilen im Index, je nach Chunking oft ein Vielfaches
        // der Aufgabenzahl) - genau diese Vermischung sorgte bisher für
        // verwirrende Zahlen wie "128" mitten in einem Lauf, der am Ende 2000
        // Abschnitte im Index hinterlässt.
        const summary = successCount + ' von ' + totalCount + ' Aufgaben erfolgreich, ' +
            indexedChunks + ' Abschnitte im Index';
        const errPart = errorCount > 0 ? '  ✗ Fehler: ' + errorCount : '';
        const finalMessage = errorCount === 0
            ? 'Der Index wurde erfolgreich aufgebaut (' + summary + ').'
            : 'Der Index wurde teilweise aufgebaut (' + summary + '). ' + errorCount + ' Fehler wurden protokolliert.';

        if (cancelled) {
            setStatusBadge('cancelled');
            const container = el('ai-chat-progress-container');
            if (container) container.style.display = 'block';
            setStatus(
                'Abgebrochen nach ' + currentTaskIndex + ' von ' + totalCount +
                ' Aufgaben (' + indexedChunks + ' Abschnitte indiziert). ✓ ' + successCount + '  ✗ ' + errorCount,
                'warning'
            );
        } else if (errorCount === 0) {
            setStatusBadge('success');
            setStatus('Fertig in ' + elapsed + '! ' + summary, 'success');
            showSuccessState(finalMessage);
        } else {
            setStatusBadge('warning');
            const container = el('ai-chat-progress-container');
            if (container) container.style.display = 'block';
            setStatus(
                'Fertig in ' + elapsed + '! ' + summary + errPart,
                'warning'
            );
        }

        if (errorLog.length > 0) {
            let html = '<div style="margin-top: 15px; padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 200px; overflow-y: auto;">';
            html += '<p><strong>' + (config.errorLog || 'Fehler-Log:') + '</strong></p><ul style="list-style: none; padding-left: 0; font-size: 0.9em;">';
            errorLog.forEach(err => {
                html += '<li style="margin-bottom: 5px;"><span class="text-danger">&#10007; ' + err.label + '</span>: ' + err.error + '</li>';
            });
            html += '</ul></div>';
            setDetail(html);
        } else {
            setDetail('');
        }

        setRunButtonsDisabled(false);
        const startBtn = el('ai-chat-start-btn');
        if (startBtn) startBtn.textContent = config.btnRetry || 'Erneut indexieren';

        // indexedChunks ist die tatsächlich beobachtete Zahl (foreground: pro
        // Aufgabe akkumuliert; background: direkt aus dem Status-Poll) - kein
        // Neuladen mehr nötig, um die aktuelle Größe zu sehen.
        updateIndexCountBadge(indexedChunks);
    }

    // ── Bootstrap ────────────────────────────────────────────────────────────
    function init() {
        // Nur initialisieren wenn die Indexierungs-UI im DOM ist
        const cfgEl = el('ai-chat-indexer-config');
        if (!cfgEl) return;

        // Verhindert doppelte Event-Listener bei wiederholtem rex:ready
        if (cfgEl.dataset.initialized === '1') return;
        cfgEl.dataset.initialized = '1';

        try { config = JSON.parse(cfgEl.dataset.config || '{}'); } catch (e) { /* ignore */ }
        config.refreshRunning = config.refreshRunning || 'Inkrementelles Refresh läuft…';
        config.refreshDone = config.refreshDone || 'Refresh fertig';

        // Zustand bei PJAX-Navigation zurücksetzen
        tasks            = [];
        currentTaskIndex = 0;
        totalCount       = 0;
        indexedChunks    = 0;
        successCount     = 0;
        errorCount       = 0;
        errorLog         = [];
        cancelled        = false;
        startTime        = null;
        lastActivityTime = null;
        backgroundMode   = false;
        stopBackgroundPoll();
        stopHeartbeat();

        registerGlobalButtonHandlers();
        // Restore selected delay
        const delaySelect = el('ai-chat-delay-select');
        if (delaySelect) {
            try {
                const saved = sessionStorage.getItem('klxm-indexer-delay');
                if (saved !== null) delaySelect.value = saved;
                delaySelect.addEventListener('change', () => {
                    try {
                        sessionStorage.setItem('klxm-indexer-delay', delaySelect.value);
                    } catch (e) {
                        // ignore sessionStorage issues
                    }
                });
            } catch (e) {
                // ignore sessionStorage issues
            }
        }

        setStatusBadge('idle');
        checkBackgroundAvailability();
        // Überschreibt den "idle"-Zustand oben wieder, falls tatsächlich ein
        // Hintergrundlauf aktiv ist - siehe dortige Erklärung.
        resumeBackgroundRunIfActive();
    }

    // Wird ein Hintergrundlauf gestartet und die Seite später (neuer Tab, PJAX-
    // Navigation weg und zurück, Browser neu geöffnet) erneut aufgerufen, wusste
    // die UI bisher nichts von einem noch laufenden Hintergrundlauf - der Status
    // kommt ja ausschließlich aus IndexRunStore auf dem Server, nicht aus dem
    // (dann längst verworfenen) Browser-Zustand. Deshalb beim Laden einmal aktiv
    // nachfragen und die Fortschrittsanzeige automatisch wieder andocken, statt
    // stumm im Ausgangszustand zu bleiben, obwohl serverseitig etwas läuft.
    async function resumeBackgroundRunIfActive() {
        let data;
        try {
            data = await apiFetch(getApiBase() + '&action=background_status', { timeout: 10000 });
        } catch (e) {
            return;
        }

        if (!data || data.status !== 'running') {
            return;
        }

        cancelled     = false;
        successCount  = 0;
        errorCount    = 0;
        errorLog      = [];
        indexedChunks = 0;
        totalCount    = 0;
        currentTaskIndex = 0;
        startTime     = Date.now();
        lastActivityTime = Date.now();
        backgroundMode = true;

        setRunButtonsDisabled(true);
        setStatusBadge('running-bg');
        showProgress(true);
        updateProgress(0);
        setDetail('');
        showBackgroundHint(true);
        setStatus('Ein Hintergrundlauf ist bereits aktiv – Fortschritt wird geladen…', '');

        const bar = el('ai-chat-progress-bar');
        if (bar) {
            bar.classList.add('active');
            bar.classList.remove('progress-bar-success', 'progress-bar-warning', 'progress-bar-danger');
        }

        startHeartbeat();

        try {
            await pollBackgroundStatus();
            finishIndexing();
        } catch (e) {
            stopHeartbeat();
            stopBackgroundPoll();
            showBackgroundHint(false);
            setStatus('Fehler: ' + e.message, 'error');
            setStatusBadge('error');
            setRunButtonsDisabled(false);
        }
    }

    // Einmalig beim Laden der Seite prüfen, ob Hintergrundausführung möglich ist,
    // und den "Im Hintergrund indexieren"-Button entsprechend freischalten/sperren
    // - der Button soll VOR dem Klick schon zeigen, ob er etwas tun kann, statt
    // das erst nach dem Klick zu entscheiden (das war vorher am "Jetzt
    // indexieren"-Button selbst nicht erkennbar).
    async function checkBackgroundAvailability() {
        // Refresh (inkrementell) laeuft jetzt ebenfalls immer ueber Api\ReindexWorker
        // (siehe handleRefresh()) statt eines synchronen Inline-Requests, braucht also
        // dieselbe Verfuegbarkeitspruefung wie "Im Hintergrund indexieren".
        const buttons = [
            el('ai-chat-start-background-btn'),
            el('ai-chat-refresh-btn'),
        ].filter(Boolean);
        if (buttons.length === 0) {
            return;
        }

        buttons.forEach(function (btn) {
            btn.disabled = true;
            btn.title = config.backgroundButtonChecking || 'Prüfe Verfügbarkeit…';
        });

        try {
            const data = await apiFetch(getApiBase() + '&action=background_available', { timeout: 10000 });
            backgroundAvailable = !!(data && data.success && data.available);
            const reason = (data && data.reason) ? data.reason : '';

            buttons.forEach(function (btn) {
                btn.disabled = !backgroundAvailable;
                btn.title = backgroundAvailable
                    ? (config.backgroundButtonAvailable || 'Läuft serverseitig weiter, auch wenn Sie diesen Tab schließen.')
                    : (config.backgroundButtonUnavailable ? config.backgroundButtonUnavailable.replace('%s', reason) : ('Auf diesem Server nicht möglich: ' + reason));
            });
        } catch (e) {
            backgroundAvailable = false;
            buttons.forEach(function (btn) {
                btn.disabled = true;
                btn.title = 'Verfügbarkeit konnte nicht geprüft werden.';
            });
        }
    }

    // REDAXO Backend nutzt PJAX – rex:ready (jQuery-Event) feuert nach jeder Navigation.
    // DOMContentLoaded würde nach PJAX-Navigation NICHT mehr feuern.
    registerGlobalButtonHandlers();

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('rex:ready', function () {
            init();
        });
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
}());
