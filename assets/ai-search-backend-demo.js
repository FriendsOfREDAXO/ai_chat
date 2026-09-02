(function () {
  'use strict';

  function createButton(text, className, onClick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = className;
    btn.textContent = text;
    btn.addEventListener('click', onClick);
    return btn;
  }

  function getRoot() {
    return document.getElementById('ai-search-root');
  }

  function applySkin(vars) {
    var root = getRoot();
    if (!root) {
      return;
    }

    Object.keys(vars).forEach(function (name) {
      root.style.setProperty(name, vars[name]);
    });
  }

  function mountInlineInputDemo(container, instance) {
    if (!container) {
      return;
    }

    container.innerHTML = '';

    var wrap = document.createElement('div');
    wrap.className = 'ai-search-demo-row';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control ai-search-demo-input';
    input.placeholder = 'Begriff eingeben für Inline-Suche ohne Modal...';

    var searchBtn = createButton('Inline suchen', 'btn btn-primary', function () {
      runInlineSearch();
    });

    var modalBtn = createButton('Als Modal öffnen', 'btn btn-default', function () {
      instance.open(input.value.trim());
    });

    var results = document.createElement('div');
    results.className = 'ai-search-demo-popup-results';
    results.style.marginTop = '10px';

    var state = {
      requestId: 0,
      lastHits: []
    };

    function renderInfo(message) {
      results.innerHTML = '';
      var info = document.createElement('div');
      info.className = 'ai-search-demo-empty';
      info.textContent = message;
      results.appendChild(info);
    }

    function renderHits(hits) {
      results.innerHTML = '';

      if (!Array.isArray(hits) || hits.length === 0) {
        renderInfo('Keine Treffer gefunden.');
        return;
      }

      hits.slice(0, 8).forEach(function (hit) {
        var item = document.createElement('a');
        item.className = 'ai-search-demo-result';
        item.href = hit.url || '#';
        item.target = '_blank';
        item.rel = 'noopener noreferrer';

        var title = document.createElement('strong');
        title.textContent = hit.title || 'Ohne Titel';

        var meta = document.createElement('span');
        meta.className = 'ai-search-demo-result-meta';

        var typeLabel = (hit.type_label || hit.type || 'Treffer');
        if (typeLabel === 'sitemap_url') {
          typeLabel = 'Seite';
        }

        meta.textContent = typeLabel;

        item.appendChild(title);
        item.appendChild(meta);
        results.appendChild(item);
      });
    }

    function runInlineSearch() {
      var query = input.value.trim();
      var requestId = ++state.requestId;

      if (query === '') {
        state.lastHits = [];
        renderInfo('Suchbegriff eingeben für Live-Treffer.');
        return;
      }

      renderInfo('Suche läuft...');

      fetch(instance.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          mode: 'search',
          scope: 'frontend',
          message: query,
          limit: 8
        })
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (requestId !== state.requestId) {
            return;
          }

          var hits = (data && Array.isArray(data.hits)) ? data.hits : [];
          state.lastHits = hits;
          renderHits(hits);
        })
        .catch(function () {
          if (requestId !== state.requestId) {
            return;
          }

          renderInfo('Inline-Suche aktuell nicht verfügbar.');
        });
    }

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runInlineSearch();
      }
    });

    input.addEventListener('input', function () {
      if (input.value.trim() === '') {
        state.lastHits = [];
        renderInfo('Suchbegriff eingeben für Live-Treffer.');
      }
    });

    wrap.appendChild(input);
    wrap.appendChild(searchBtn);
    wrap.appendChild(modalBtn);
    container.appendChild(wrap);
    container.appendChild(results);

    renderInfo('Suchbegriff eingeben für Live-Treffer.');
  }

  function mountDropdownDemo(container, instance) {
    if (!container) {
      return;
    }

    container.innerHTML = '';

    var popupId = 'ai-search-popup-demo';
    var presets = [
      'Was macht die KLXM?',
      'Wie erreiche ich den Kontakt?',
      'Welche Referenzen gibt es?'
    ];

    var launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.className = 'ai-search-icon-trigger';
    launcher.setAttribute('aria-label', 'Suche öffnen');
    launcher.setAttribute('aria-haspopup', 'dialog');
    launcher.setAttribute('aria-controls', popupId);
    launcher.setAttribute('aria-expanded', 'false');
    launcher.innerHTML = '<span aria-hidden="true" class="ai-search-icon">⌕</span><span class="ai-search-icon-text">Suche</span>';

    var popup = document.createElement('div');
    popup.id = popupId;
    popup.className = 'ai-search-demo-popup';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-modal', 'false');
    popup.setAttribute('aria-label', 'Schnellsuche');
    popup.hidden = true;

    var popupHead = document.createElement('div');
    popupHead.className = 'ai-search-demo-popup-head';
    popupHead.textContent = 'Schnellsuche';

    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control ai-search-demo-popup-input';
    input.placeholder = 'Tippen für Live-Ergebnisse...';
    input.setAttribute('aria-label', 'Suchbegriff eingeben');

    var askAi = document.createElement('button');
    askAi.type = 'button';
    askAi.className = 'btn btn-primary btn-sm ai-search-demo-ask-ai';
    askAi.textContent = 'Frag die KI';
    askAi.hidden = true;

    var aiTimer = null;
    var AI_DELAY_MS = 1400;

    function hideAskAi() {
      askAi.hidden = true;
    }

    function scheduleAskAi(query) {
      if (aiTimer) {
        clearTimeout(aiTimer);
      }

      hideAskAi();

      if (query === '') {
        return;
      }

      aiTimer = setTimeout(function () {
        askAi.hidden = false;
      }, AI_DELAY_MS);
    }

    askAi.addEventListener('click', function () {
      var query = input.value.trim();
      if (query === '') {
        return;
      }

      closePopup();
      instance.open(query);
    });

    var list = document.createElement('div');
    list.className = 'ai-search-demo-popup-results';

    var listState = { requestId: 0 };

    function openPopup() {
      popup.hidden = false;
      launcher.setAttribute('aria-expanded', 'true');
      input.focus();
      hideAskAi();
      renderPresets();
    }

    function closePopup() {
      if (aiTimer) {
        clearTimeout(aiTimer);
      }
      hideAskAi();
      popup.hidden = true;
      launcher.setAttribute('aria-expanded', 'false');
      launcher.focus();
    }

    function renderPresets() {
      list.innerHTML = '';
      presets.forEach(function (query) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-search-demo-result';
        btn.textContent = query;
        btn.addEventListener('click', function () {
          closePopup();
          instance.open(query);
        });
        list.appendChild(btn);
      });
    }

    function renderHits(query, hits) {
      list.innerHTML = '';

      if (!Array.isArray(hits) || hits.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'ai-search-demo-empty';
        empty.textContent = 'Keine Treffer. Anfrage im Spotlight öffnen?';

        var ask = document.createElement('button');
        ask.type = 'button';
        ask.className = 'btn btn-default btn-sm';
        ask.textContent = 'Im Spotlight öffnen';
        ask.addEventListener('click', function () {
          closePopup();
          instance.open(query);
        });

        list.appendChild(empty);
        list.appendChild(ask);
        return;
      }

      hits.slice(0, 6).forEach(function (hit) {
        var item = document.createElement('a');
        item.className = 'ai-search-demo-result';
        item.href = hit.url || '#';
        item.target = '_blank';
        item.rel = 'noopener noreferrer';

        var title = document.createElement('strong');
        title.textContent = hit.title || 'Ohne Titel';

        var meta = document.createElement('span');
        meta.className = 'ai-search-demo-result-meta';
        meta.textContent = hit.type || 'Treffer';

        item.appendChild(title);
        item.appendChild(meta);
        list.appendChild(item);
      });

      var openAll = document.createElement('button');
      openAll.type = 'button';
      openAll.className = 'btn btn-default btn-sm';
      openAll.textContent = 'Alle im Spotlight anzeigen';
      openAll.addEventListener('click', function () {
        closePopup();
        instance.open(query);
      });
      list.appendChild(openAll);
    }

    function runLiveSearch() {
      var query = input.value.trim();
      var requestId = ++listState.requestId;

      scheduleAskAi(query);

      if (query === '') {
        renderPresets();
        return;
      }

      fetch(instance.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          mode: 'search',
          scope: 'frontend',
          message: query,
          limit: 6
        })
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (requestId !== listState.requestId) {
            return;
          }
          renderHits(query, data && data.hits ? data.hits : []);
          scheduleAskAi(query);
        })
        .catch(function () {
          if (requestId !== listState.requestId) {
            return;
          }

          list.innerHTML = '';
          var error = document.createElement('div');
          error.className = 'ai-search-demo-empty';
          error.textContent = 'Live-Suche aktuell nicht verfügbar.';
          list.appendChild(error);
          scheduleAskAi(query);
        });
    }

    launcher.addEventListener('click', function () {
      if (popup.hidden) {
        openPopup();
      } else {
        closePopup();
      }
    });

    input.addEventListener('input', runLiveSearch);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePopup();
      }
      if (event.key === 'Enter') {
        event.preventDefault();
        closePopup();
        instance.open(input.value);
      }
    });

    document.addEventListener('mousedown', function (event) {
      if (popup.hidden) {
        return;
      }

      if (!container.contains(event.target)) {
        closePopup();
      }
    });

    popup.appendChild(popupHead);
    popup.appendChild(input);
    popup.appendChild(askAi);
    popup.appendChild(list);

    container.appendChild(launcher);
    container.appendChild(popup);
    renderPresets();
  }

  function mountSkinningDemo(container) {
    if (!container) {
      return;
    }

    container.innerHTML = '';

    var wrap = document.createElement('div');
    wrap.className = 'ai-search-demo-skins';

    wrap.appendChild(createButton('Ocean', 'btn btn-default', function () {
      applySkin({
        '--ai-search-accent': '#0b65d8',
        '--ai-search-chip-active-bg': '#0b65d8',
        '--ai-search-panel-bg': 'rgba(248, 248, 251, 0.88)'
      });
    }));

    wrap.appendChild(createButton('Sunset', 'btn btn-default', function () {
      applySkin({
        '--ai-search-accent': '#e0551b',
        '--ai-search-chip-active-bg': '#e0551b',
        '--ai-search-panel-bg': 'rgba(255, 247, 240, 0.92)'
      });
    }));

    wrap.appendChild(createButton('Forest', 'btn btn-default', function () {
      applySkin({
        '--ai-search-accent': '#0b7a4b',
        '--ai-search-chip-active-bg': '#0b7a4b',
        '--ai-search-panel-bg': 'rgba(241, 250, 246, 0.92)'
      });
    }));

    container.appendChild(wrap);
  }

  function initDemo() {
    var container = document.getElementById('ai-search-demo');
    if (!container || !window.AiSearch) {
      return;
    }

    var apiUrl = container.getAttribute('data-api-url') || '/index.php?rex-api-call=ai_chat_query';
    var instance = window.AiSearch.mountDemo(container, {
      apiUrl: apiUrl,
      autoButton: false,
      placeholder: 'Suche oder Frage im Demo-Modus...',
      showChatActionButton: true,
      chatActionBehavior: 'widget',
      chatActionLabel: 'Frag die KI (Chat)'
    });

    mountInlineInputDemo(document.getElementById('ai-search-demo-inline'), instance);
    mountDropdownDemo(document.getElementById('ai-search-demo-dropdown'), instance);
    mountSkinningDemo(document.getElementById('ai-search-demo-skinning'));
  }

  if (typeof window.jQuery !== 'undefined') {
    window.jQuery(document).on('rex:ready', initDemo);
  } else {
    document.addEventListener('DOMContentLoaded', initDemo);
  }
})();
