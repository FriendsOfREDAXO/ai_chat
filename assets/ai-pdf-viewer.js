// PDF-Viewer für Treffer der Suche (PDF-Dokumente aus dem Medienpool), wenn das AddOn pdfout installiert ist.
// Zeichnet die Seiten mit PDF.js aus pdfout direkt in einen Dialog (Canvas) – ohne iframe, damit Scrollen und Zoomen
// auch auf iPhone/iPad zuverlässig funktionieren. Seiten werden erst gezeichnet, wenn sie in die Nähe des sichtbaren
// Bereichs kommen, und weit entfernte wieder freigegeben (Speichergrenzen von iOS Safari).
// Aufruf: window.AiPdfViewer.open({ url, title, lib, worker, labels }) – lib/worker = pdf.mjs/pdf.worker.mjs aus pdfout.
(function () {
    'use strict';

    if (window.AiPdfViewer) {
        return;
    }

    var ZOOMS = [1, 1.5, 2, 3];
    var MAX_CANVAS_PIXELS = 12000000; // unter der Canvas-Grenze von iOS Safari (16,7 Mio.)
    var pdfjsPromise = null;
    var state = null;

    var CSS = ''
        + '.ai-pdf-viewer{--ai-pdf-bg:#f3f4f6;--ai-pdf-bar:#ffffff;--ai-pdf-text:#1f2937;--ai-pdf-muted:#4b5563;--ai-pdf-border:rgba(0,0,0,.12);'
        + 'padding:0;border:0;margin:auto;width:min(1100px,100vw);height:min(100dvh,100vh);max-width:100vw;max-height:100dvh;background:var(--ai-pdf-bg);color:var(--ai-pdf-text);'
        + 'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 90px rgba(0,0,0,.35);font:15px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}'
        + '.ai-pdf-viewer:not([open]){display:none}'
        + '.ai-pdf-viewer::backdrop{background:rgba(10,12,16,.6)}'
        + '@media (min-width:700px){.ai-pdf-viewer{height:calc(100dvh - 48px);border-radius:12px}}'
        + '.ai-pdf-bar{display:flex;align-items:center;gap:8px;padding:8px 10px;padding-top:max(8px,env(safe-area-inset-top));background:var(--ai-pdf-bar);border-bottom:1px solid var(--ai-pdf-border);flex:none}'
        + '.ai-pdf-title{flex:1;min-width:0;margin:0;font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
        + '.ai-pdf-count{color:var(--ai-pdf-muted);font-size:.85rem;white-space:nowrap;font-variant-numeric:tabular-nums}'
        + '.ai-pdf-btn{display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;padding:0 10px;border:1px solid var(--ai-pdf-border);border-radius:8px;background:transparent;color:inherit;font:inherit;text-decoration:none;cursor:pointer}'
        + '.ai-pdf-btn:hover{background:rgba(0,0,0,.05)}.ai-pdf-btn:focus-visible{outline:3px solid #0b65d8;outline-offset:2px}.ai-pdf-btn[disabled]{opacity:.4;cursor:default}'
        + '.ai-pdf-btn svg{width:22px;height:22px;fill:currentColor}'
        + '.ai-pdf-pages{position:relative;flex:1;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:12px 8px calc(24px + env(safe-area-inset-bottom));touch-action:pan-x pan-y pinch-zoom}'
        + '.ai-pdf-page{position:relative;margin:0 auto 12px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.18)}'
        + '.ai-pdf-page canvas{display:block;width:100%;height:100%}'
        + '.ai-pdf-status{margin:24px auto;max-width:520px;text-align:center;color:var(--ai-pdf-muted)}'
        + '.ai-pdf-status a{color:inherit}'
        + '@media (max-width:419px){.ai-pdf-bar{gap:6px;padding-left:8px;padding-right:8px}.ai-pdf-btn{min-width:40px;padding:0 6px}.ai-pdf-count{font-size:.78rem}}'
        + 'html.ai-pdf-open,html.ai-pdf-open body{overflow:hidden}';

    var ICON = {
        close: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4-6.3-6.3-6.3 6.3-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4 6.3 6.3 6.3-6.3z"/></svg>',
        minus: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 11h14v2H5z"/></svg>',
        plus: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/></svg>',
        download: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M11 4h2v9.2l3.3-3.3 1.4 1.4L12 17l-5.7-5.7 1.4-1.4 3.3 3.3zM5 19h14v2H5z"/></svg>'
    };

    function el(tag, cls, html) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (html) { node.innerHTML = html; }
        return node;
    }

    function injectCss() {
        if (document.getElementById('ai-pdf-viewer-css')) { return; }
        var style = el('style');
        style.id = 'ai-pdf-viewer-css';
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function loadPdfjs(lib, worker) {
        if (!pdfjsPromise) {
            // PDF.js 4 nutzt Promise.withResolvers (Safari erst ab 17.4)
            if (typeof Promise.withResolvers !== 'function') {
                Promise.withResolvers = function () {
                    var r = {};
                    r.promise = new Promise(function (resolve, reject) { r.resolve = resolve; r.reject = reject; });
                    return r;
                };
            }
            pdfjsPromise = import(lib).then(function (pdfjs) {
                pdfjs.GlobalWorkerOptions.workerSrc = worker;
                return pdfjs;
            });
        }
        return pdfjsPromise;
    }

    function build(opts) {
        var L = opts.labels || {};
        var dialog = el('dialog', 'ai-pdf-viewer');
        dialog.setAttribute('aria-labelledby', 'ai-pdf-title');
        var bar = el('div', 'ai-pdf-bar');
        var title = el('h2', 'ai-pdf-title');
        title.id = 'ai-pdf-title';
        title.textContent = opts.title || L.document || 'PDF-Dokument';
        var count = el('span', 'ai-pdf-count');
        count.setAttribute('aria-live', 'polite');
        var out = el('button', 'ai-pdf-btn', ICON.minus);
        out.type = 'button';
        out.setAttribute('aria-label', L.zoomOut || 'Verkleinern');
        var zin = el('button', 'ai-pdf-btn', ICON.plus);
        zin.type = 'button';
        zin.setAttribute('aria-label', L.zoomIn || 'Vergrößern');
        var dl = el('a', 'ai-pdf-btn', ICON.download);
        dl.href = opts.url;
        dl.setAttribute('download', '');
        dl.setAttribute('aria-label', L.download || 'Herunterladen');
        dl.title = L.download || 'Herunterladen';
        var close = el('button', 'ai-pdf-btn', ICON.close);
        close.type = 'button';
        close.setAttribute('aria-label', L.close || 'Schließen');
        bar.append(title, count, out, zin, dl, close);
        var pages = el('div', 'ai-pdf-pages');
        pages.tabIndex = 0;
        pages.setAttribute('role', 'region');
        pages.setAttribute('aria-label', L.pages || 'Seiten des Dokuments');
        dialog.append(bar, pages);
        document.body.appendChild(dialog);
        return { dialog: dialog, pages: pages, count: count, zoomOut: out, zoomIn: zin, close: close, labels: L };
    }

    function status(s, text, withLink) {
        s.pages.innerHTML = '';
        var p = el('p', 'ai-pdf-status');
        p.textContent = text;
        if (withLink) {
            p.appendChild(document.createTextNode(' '));
            var a = el('a');
            a.href = s.url;
            a.textContent = s.labels.openDirect || 'Datei direkt öffnen';
            p.appendChild(a);
        }
        s.pages.appendChild(p);
    }

    function pageWidth(s) {
        var avail = Math.max(200, s.pages.clientWidth - 16);
        return Math.round(avail * ZOOMS[s.zoom]);
    }

    function layout(s) {
        var w = pageWidth(s);
        s.items.forEach(function (it) {
            it.box.style.width = w + 'px';
            it.box.style.height = Math.round(w * it.ratio) + 'px';
            if (it.rendered) {
                it.rendered = false;
                it.stale = true;
            }
        });
        s.zoomOut.disabled = s.zoom === 0;
        s.zoomIn.disabled = s.zoom === ZOOMS.length - 1;
        refresh(s);
    }

    function render(s, it) {
        if (it.rendered || it.busy) { return; }
        it.busy = true;
        var cssW = parseFloat(it.box.style.width);
        s.doc.getPage(it.n).then(function (page) {
            if (s.closed) { return; }
            var base = page.getViewport({ scale: 1 });
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var scale = (cssW / base.width) * dpr;
            if (base.width * base.height * scale * scale > MAX_CANVAS_PIXELS) {
                scale = Math.sqrt(MAX_CANVAS_PIXELS / (base.width * base.height));
            }
            var viewport = page.getViewport({ scale: scale });
            var canvas = el('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', (s.labels.page || 'Seite') + ' ' + it.n);
            return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(function () {
                if (s.closed) { return; }
                free(it);
                it.box.appendChild(canvas);
                it.rendered = true;
                it.stale = false;
            });
        }).catch(function () { /* einzelne Seite nicht darstellbar – Platzhalter bleibt */ }).then(function () {
            it.busy = false;
            if (it.stale && it.near) { render(s, it); }
        });
    }

    function free(it) {
        var c = it.box.querySelector('canvas');
        if (c) {
            c.width = 0;
            c.height = 0;
            c.remove();
        }
        it.rendered = false;
    }

    // Seiten nahe am sichtbaren Bereich zeichnen, weit entfernte freigeben; Seitenanzeige aktualisieren
    function refresh(s) {
        if (!s.items.length) { return; }
        var top = s.pages.scrollTop;
        var h = s.pages.clientHeight;
        var current = 1;
        s.items.forEach(function (it) {
            var y = it.box.offsetTop;
            var bottom = y + it.box.offsetHeight;
            it.near = bottom > top - 2 * h && y < top + 3 * h;
            if (y <= top + h / 3) { current = it.n; }
            if (it.near) {
                if (!it.rendered || it.stale) { render(s, it); }
            } else if (it.rendered && (bottom < top - 5 * h || y > top + 6 * h)) {
                free(it);
            }
        });
        s.count.textContent = current + ' / ' + s.items.length;
        s.count.setAttribute('aria-label', (s.labels.page || 'Seite') + ' ' + current + ' / ' + s.items.length);
    }

    function close() {
        if (!state) { return; }
        var s = state;
        state = null;
        s.closed = true;
        s.items.forEach(free);
        if (s.task) { s.task.destroy(); }
        if (s.doc) { s.doc.destroy(); }
        window.removeEventListener('resize', s.onResize);
        document.documentElement.classList.remove('ai-pdf-open');
        if (s.dialog.open) { s.dialog.close(); }
        s.dialog.remove();
        if (s.opener && s.opener.focus) { s.opener.focus(); }
    }

    function open(opts) {
        if (!opts || !opts.url || !opts.lib || typeof HTMLDialogElement === 'undefined') {
            return false;
        }
        close();
        injectCss();
        var s = build(opts);
        s.url = opts.url;
        s.items = [];
        s.zoom = 0;
        s.opener = document.activeElement;
        state = s;

        s.close.addEventListener('click', close);
        s.dialog.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
        s.dialog.addEventListener('click', function (e) { if (e.target === s.dialog) { close(); } }); // Klick auf den Hintergrund
        s.zoomOut.addEventListener('click', function () { if (s.zoom > 0) { s.zoom--; layout(s); } });
        s.zoomIn.addEventListener('click', function () { if (s.zoom < ZOOMS.length - 1) { s.zoom++; layout(s); } });
        var ticking = false;
        s.pages.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(function () { ticking = false; if (!s.closed) { refresh(s); } });
            }
        }, { passive: true });
        var resizeTimer;
        s.onResize = function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () { if (!s.closed) { layout(s); } }, 150);
        };
        window.addEventListener('resize', s.onResize);

        document.documentElement.classList.add('ai-pdf-open');
        s.dialog.showModal();
        s.close.focus();
        status(s, s.labels.loading || 'Dokument wird geladen …', false);

        loadPdfjs(opts.lib, opts.worker).then(function (pdfjs) {
            if (s.closed) { return null; }
            s.task = pdfjs.getDocument({ url: opts.url, isEvalSupported: false });
            return s.task.promise;
        }).then(function (doc) {
            if (!doc || s.closed) { return null; }
            s.doc = doc;
            // Seitenformate vorab (für Platzhalter in richtiger Höhe – kein Springen beim Scrollen)
            var sizes = [];
            var chain = Promise.resolve();
            for (var n = 1; n <= doc.numPages; n++) {
                (function (n) {
                    chain = chain.then(function () {
                        return doc.getPage(n).then(function (page) {
                            var v = page.getViewport({ scale: 1 });
                            sizes.push(v.height / v.width);
                        });
                    });
                })(n);
            }
            return chain.then(function () { return sizes; });
        }).then(function (sizes) {
            if (!sizes || s.closed) { return; }
            s.pages.innerHTML = '';
            sizes.forEach(function (ratio, i) {
                var box = el('div', 'ai-pdf-page');
                s.pages.appendChild(box);
                s.items.push({ n: i + 1, ratio: ratio, box: box, rendered: false, busy: false, stale: false, near: false });
            });
            layout(s);
        }).catch(function () {
            if (!s.closed) {
                status(s, s.labels.error || 'Das Dokument konnte nicht angezeigt werden.', true);
            }
        });

        return true;
    }

    window.AiPdfViewer = { open: open, close: close };
})();
