(function () {
    'use strict';

    function el(id) {
        return document.getElementById(id);
    }

    function loadConfig() {
        const cfgEl = el('ai-chat-indexer-config');
        if (!cfgEl) {
            return {};
        }

        try {
            return JSON.parse(cfgEl.dataset.config || '{}');
        } catch (e) {
            return {};
        }
    }

    function setStatus(message, type) {
        const status = el('ai-chat-status-text');
        if (!status) {
            return;
        }

        status.textContent = message;
        status.className = 'klxm-indexer-status';
        if (type === 'error') status.classList.add('text-danger');
        if (type === 'success') status.classList.add('text-success');
        if (type === 'warning') status.classList.add('text-warning');
    }

    function setDetail(html) {
        const detail = el('ai-chat-detail-text');
        if (detail) {
            detail.innerHTML = html;
        }
    }

    function showProgress() {
        const box = el('ai-chat-progress-container');
        if (box) {
            box.style.display = 'block';
        }
    }

    function updateProgress(percent, state) {
        const bar = el('ai-chat-progress-bar');
        if (!bar) {
            return;
        }

        bar.style.width = percent + '%';
        bar.textContent = percent + '%';
        bar.setAttribute('aria-valuenow', String(percent));
        bar.classList.remove('progress-bar-success', 'progress-bar-warning', 'progress-bar-danger');
        if (state === 'success') bar.classList.add('progress-bar-success');
        if (state === 'warning') bar.classList.add('progress-bar-warning');
        if (state === 'error') bar.classList.add('progress-bar-danger');
    }

    async function warmCache(event) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const button = el('ai-chat-warm-cache-btn');
        const config = loadConfig();
        const confirmText = config.confirmWarmCache || 'Soll der FAQ-Cache jetzt mit den konfigurierten Fragen vorgewärmt werden?';
        if (!window.confirm(confirmText)) {
            return;
        }

        if (button) {
            button.disabled = true;
        }

        showProgress();
        updateProgress(20);
        setStatus(config.warmCacheRunning || 'Cache-Vorwärmung läuft…', '');
        setDetail('<span class="text-muted">Die konfigurierten FAQ-Fragen werden per API verarbeitet und in den Cache geschrieben…</span>');

        const apiBase = config.apiBase || 'index.php?rex-api-call=ai_chat_index';

        try {
            const response = await fetch(apiBase + '&action=warm_cache', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Ungültige JSON-Antwort vom Server: ' + text.substring(0, 200));
            }

            if (!response.ok || !data.success) {
                throw new Error((data && data.error) ? data.error : ('HTTP ' + response.status));
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
            updateProgress(100, errors > 0 ? 'warning' : 'success');
            setStatus((config.warmCacheDone || 'Cache-Vorwärmung abgeschlossen') + ' — vorbereitet: ' + prepared + ', geschrieben: ' + processed + ', übersprungen: ' + skipped + (errors > 0 ? ', Fehler: ' + errors : ''), errors > 0 ? 'warning' : 'success');
            setDetail(
                '<div class="text-muted" style="margin-bottom:6px;">Die Cache-Tabelle ist aktualisiert. Bei Bedarf Seite neu laden, um Zähler neu einzulesen.</div>' +
                '<div><strong>Bearbeitet:</strong><ul style="margin:6px 0 0 18px;">' + previewQuestions + '</ul></div>' +
                (skippedPreview ? '<div style="margin-top:8px;"><strong>Übersprungen:</strong><ul style="margin:6px 0 0 18px;">' + skippedPreview + '</ul></div>' : '')
            );

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
            updateProgress(100, 'error');
            setStatus('Fehler: ' + e.message, 'error');
            setDetail('');
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    function init() {
        const button = el('ai-chat-warm-cache-btn');
        if (!button) {
            return;
        }

        if (button.dataset.warmCacheBound === '1') {
            return;
        }

        button.dataset.warmCacheBound = '1';
        button.addEventListener('click', warmCache, true);
    }

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('rex:ready', function () {
            init();
        });
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

    init();
}());
