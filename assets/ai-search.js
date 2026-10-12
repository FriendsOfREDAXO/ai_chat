(function () {
  'use strict';

  // Aktualisiert, sobald die Übersetzungen geladen sind (siehe SearchUi()-
  // Konstruktor) - bis dahin (und falls kein <ai-chat> mit ui-language auf
  // der Seite steht) bleibt Deutsch der sichere Standard.
  var currentIntlLocale = 'de-DE';

  function asArray(value) {
    return Array.isArray(value) ? value : [];
  }

  function toErrorMessage(error, fallback) {
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
      return error.message;
    }
    return fallback;
  }

  function formatUpdatedAt(value) {
    var raw = typeof value === 'string' ? value.trim() : '';
    if (raw === '') {
      return '';
    }

    var normalized = raw.replace(' ', 'T');
    var date = new Date(normalized);
    if (isNaN(date.getTime())) {
      return raw;
    }

    try {
      return new Intl.DateTimeFormat(currentIntlLocale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    } catch (e) {
      return raw;
    }
  }

  function formatForcalSnippet(value) {
    var text = typeof value === 'string' ? value.trim() : '';
    if (text === '') {
      return '';
    }

    if (!/^Nächste Termine:/i.test(text)) {
      return text;
    }

    return text
      .replace(/^Nächste Termine:\s*/i, 'Nächste Termine: ')
      .replace(/\s*\|\s*/g, ' • ');
  }

  function createCalendarIcon() {
    var icon = document.createElement('span');
    icon.className = 'ai-search-item-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9ZM5 6a1 1 0 0 0-1 1v1h16V7a1 1 0 0 0-1-1H5Z"/></svg>';
    return icon;
  }

  function createProviderIcon(svgMarkup) {
    var icon = document.createElement('span');
    icon.className = 'ai-search-item-icon';
    icon.setAttribute('aria-hidden', 'true');

    var svg = typeof svgMarkup === 'string' ? svgMarkup.trim() : '';
    if (svg === '' || svg.indexOf('<svg') === -1) {
      return null;
    }

    icon.innerHTML = svg;
    return icon;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function findApiUrl() {
    var chat = document.querySelector('ai-chat');
    if (chat && chat.getAttribute('api-url')) {
      return chat.getAttribute('api-url');
    }
    return '/index.php?rex-api-call=ai_chat_query';
  }

  // Chat und Suche teilen sich im Frontend dasselbe aufgelöste Profil (siehe
  // boot.php) - die Suche hat kein eigenes ui-language-Attribut, "leiht" es
  // sich daher vom <ai-chat>-Element, falls vorhanden (analog zu
  // findApiUrl()/getChatBranding()). Ohne Chat auf der Seite bleibt Deutsch
  // der Standard.
  function findUiLanguage() {
    var chat = document.querySelector('ai-chat');
    return (chat && chat.getAttribute('ui-language')) || 'de';
  }

  function getChatBranding() {
    var chat = document.querySelector('ai-chat');
    if (!chat) {
      return {
        color: '',
        avatarUrl: ''
      };
    }

    return {
      color: (chat.getAttribute('primary-color') || '').trim(),
      avatarUrl: (chat.getAttribute('avatar-url') || '').trim()
    };
  }

  function htmlToText(html) {
    var div = document.createElement('div');
    div.innerHTML = String(html || '');
    return (div.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function SearchUi(options) {
    // Widget-Übersetzungen (siehe assets/ai-i18n.js). Leer bis geladen; t()
    // faellt bis dahin auf den mitgegebenen deutschen Text zurueck - render()
    // wartet also nicht darauf, patcht bereits gezeigte Texte nur nachtraeglich.
    this.i18n = {};
    if (window.AiChatI18n && typeof window.AiChatI18n.load === 'function') {
      var self = this;
      window.AiChatI18n.load(findUiLanguage()).then(function (dict) {
        self.i18n = dict || {};
        currentIntlLocale = self.i18n.intl_locale || currentIntlLocale;
        self.applyTranslations();
      });
    }

    this.apiUrl = (options && options.apiUrl) || findApiUrl();
    this.placeholder = (options && options.placeholder) || 'Suche oder Frage eingeben...';
    var fallbackScope = (options && options.scope) || 'frontend';
    this.searchScope = (options && options.searchScope) || fallbackScope;
    this.chatScope = (options && options.chatScope) || (fallbackScope === 'frontend' ? 'search' : fallbackScope);
    this.showChatActionButton = !!(options && options.showChatActionButton);
    this.chatActionLabel = (options && options.chatActionLabel) || 'Frag die KI';
    this.chatActionBehavior = (options && options.chatActionBehavior) || 'inline';
    this.autoButton = !options || options.autoButton !== false;
    // KI im Such-Fenster (Profil-Einstellung „KI im Such-Fenster“, von boot.php als
    // window.AiSearchConfig.aiMode mitgegeben): 'search' = nur Trefferliste, keine KI;
    // 'answer' = Trefferliste + KI-Antwort bei Fragen (bisheriges Verhalten);
    // 'chat' = wie 'answer', dazu ein Chat direkt im Fenster (Nachfragen zur Antwort).
    var globalConfig = window.AiSearchConfig || {};
    var aiMode = (options && options.aiMode) || globalConfig.aiMode || 'answer';
    this.aiMode = ['search', 'answer', 'chat'].indexOf(aiMode) !== -1 ? aiMode : 'answer';
    if (this.aiMode === 'search') {
      this.showChatActionButton = false;
    } else if (this.aiMode === 'chat') {
      this.showChatActionButton = !options || options.showChatActionButton !== false;
      this.chatActionBehavior = 'inline';
    }
    // Start der KI (Profil-Einstellung „KI-Antwort starten“, window.AiSearchConfig.aiTrigger):
    // 'auto' = bei Fragen schon beim Tippen (bisher), 'button' = nur über „Frag die KI“ – beim Tippen
    // läuft dann nur die schnelle Suche, keine KI-Anfrage (auch keine Zusammenfassung).
    var aiTrigger = (options && options.aiTrigger) || globalConfig.aiTrigger || 'auto';
    this.aiTrigger = aiTrigger === 'button' ? 'button' : 'auto';
    if (this.aiTrigger === 'button' && this.aiMode !== 'search') {
      this.showChatActionButton = true;
      this.chatActionBehavior = 'inline';
    }
    this.searchAbort = null;
    // Art der letzten Suche (erweitert = mit Vektoren, nach Enter/„Suchen“) – Filter-Klicks suchen genauso, sonst
    // passen Trefferzahlen und Ergebnis nicht zusammen (Filter mit 0 Treffern, Filterleiste verschwand)
    this.lastExtended = false;
    this.lastFilters = [];
    this.lastLabelFilters = [];
    this.chatHistory = [];
    this.chatNonce = 0;
    // Filter-Chips: 'single' = immer nur ein Filter aktiv (Klick wechselt, erneuter Klick
    // hebt auf), 'multi' = Filter lassen sich kombinieren (bisheriges Verhalten).
    this.filterMode = (options && options.filterMode === 'multi') ? 'multi' : 'single';
    this.selectedTypes = [];
    this.selectedLabels = [];
    this.debounceTimer = null;
    this.minimumQueryLength = 3;
    this.searchDebounceMs = 700;
    this.requestNonce = 0;
    this.isChatLoading = false;

    this.root = null;
    this.overlay = null;
    this.input = null;
    this.toolbar = null;
    this.actions = null;
    this.answerHost = null;
    this.results = null;
    this.trigger = null;
  }

  SearchUi.prototype.setAnswerLoading = function (isLoading, label, variant) {
    this.isChatLoading = !!isLoading;

    if (!this.answerHost) {
      return;
    }

    if (!this.isChatLoading) {
      return;
    }

    this.answerHost.innerHTML = '';

    var isSummary = variant === 'summary';

    var box = document.createElement('div');
    box.className = isSummary ? 'ai-search-answer ai-search-answer-summary ai-search-answer-loading' : 'ai-search-answer ai-search-answer-loading';

    var title = document.createElement('div');
    title.className = 'ai-search-answer-title';
    title.textContent = isSummary
      ? this.t('search_summary_title', 'Überblick über die Bereiche')
      : this.t('search_panel_title', 'Antwort');

    var loading = document.createElement('div');
    loading.className = 'ai-search-answer-loading-row';

    var dots = document.createElement('span');
    dots.className = 'ai-search-loading-dots';
    dots.innerHTML = '<span></span><span></span><span></span>';

    var text = document.createElement('span');
    text.className = 'ai-search-answer-loading-text';
    text.textContent = (label && String(label).trim() !== '') ? String(label) : 'KI antwortet...';

    loading.appendChild(dots);
    loading.appendChild(text);

    box.appendChild(title);
    box.appendChild(loading);
    this.answerHost.appendChild(box);
  };

  // Kurzform für window.AiChatI18n.t() gegen die aktuell geladenen
  // Übersetzungen dieser Instanz.
  SearchUi.prototype.t = function (key, fallback, vars) {
    if (window.AiChatI18n && typeof window.AiChatI18n.t === 'function') {
      return window.AiChatI18n.t(this.i18n, key, fallback, vars);
    }
    return fallback || key;
  };

  // Patcht die wenigen bereits gemounteten statischen Texte (Trigger-Button,
  // Panel-Titel), NACHDEM die Übersetzungen geladen sind - mount() selbst
  // bleibt synchron und zeigt bis dahin weiterhin sofort den deutschen
  // Standardtext.
  SearchUi.prototype.applyTranslations = function () {
    if (this.trigger) {
      this.trigger.textContent = this.t('search_trigger_label', 'Suche');
    }
    if (this.answerHost) {
      var title = this.answerHost.querySelector('.ai-search-answer-title');
      if (title) {
        var isSummary = !!this.answerHost.querySelector('.ai-search-answer-summary');
        title.textContent = isSummary
          ? this.t('search_summary_title', 'Überblick über die Bereiche')
          : this.t('search_panel_title', 'Antwort');
      }
    }
  };

  SearchUi.prototype.mount = function () {
    if (this.root) {
      return this;
    }

    var root = document.createElement('div');
    root.id = 'ai-search-root';

    if (this.autoButton) {
      this.trigger = document.createElement('button');
      this.trigger.className = 'ai-search-trigger';
      this.trigger.type = 'button';
      this.trigger.textContent = this.t('search_trigger_label', 'Suche');
      root.appendChild(this.trigger);
    }

    this.overlay = document.createElement('div');
    this.overlay.className = 'ai-search-overlay';

    var panel = document.createElement('div');
    panel.className = 'ai-search-panel';

    var header = document.createElement('div');
    header.className = 'ai-search-header';

    this.input = document.createElement('input');
    this.input.className = 'ai-search-input';
    this.input.type = 'search';
    this.input.autocomplete = 'off';
    this.input.placeholder = this.placeholder;
    // Passwort-Manager (Safari/Passwörter, 1Password, LastPass …) halten ein einzelnes Textfeld
    // ohne Namen sonst für ein Benutzername-Feld und bieten Zugangsdaten an. „search“ im Namen
    // und die Ignorier-Attribute verhindern das; autocomplete="off" allein reicht Safari nicht.
    this.input.name = 'search';
    this.input.id = 'ai-search-input';
    this.input.setAttribute('role', 'searchbox');
    this.input.setAttribute('aria-label', this.placeholder);
    this.input.setAttribute('enterkeyhint', 'search');
    this.input.setAttribute('autocapitalize', 'off');
    this.input.setAttribute('data-1p-ignore', '');
    this.input.setAttribute('data-lpignore', 'true');
    this.input.setAttribute('data-bwignore', '');
    this.input.setAttribute('data-form-type', 'other');

    // Live-Suche (debounced) bleibt der Regelfall - der Button ist fuer Nutzer:innen gedacht,
    // die lieber explizit "suchen" ausloesen (z.B. nach Abbruch der Eingabe per Tab/Klick weg
    // vom Feld) statt sich auf Tippen zu verlassen, sowie als sichtbarer Hinweis, dass hier
    // ueberhaupt gesucht werden kann.
    this.submitButton = document.createElement('button');
    this.submitButton.type = 'button';
    this.submitButton.className = 'ai-search-submit';
    this.submitButton.textContent = this.t('search_submit_label', 'Suchen');

    // Eingabe löschen – sichtbar, sobald etwas im Feld steht (das eingebaute „x“ von type=search
    // fehlt auf vielen Handys bzw. ist dort kaum zu treffen)
    this.clearButton = document.createElement('button');
    this.clearButton.type = 'button';
    this.clearButton.className = 'ai-search-clear';
    this.clearButton.hidden = true;
    this.clearButton.setAttribute('aria-label', this.t('search_clear_label', 'Eingabe löschen'));
    this.clearButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm4.24 12.83-1.41 1.41L12 13.41l-2.83 2.83-1.41-1.41L10.59 12 7.76 9.17l1.41-1.41L12 10.59l2.83-2.83 1.41 1.41L13.41 12l2.83 2.83Z"></path></svg>';

    // Schließen – auf dem Handy gibt es keine Esc-Taste und kaum Hintergrund zum Antippen
    this.closeButton = document.createElement('button');
    this.closeButton.type = 'button';
    this.closeButton.className = 'ai-search-close';
    this.closeButton.setAttribute('aria-label', this.t('search_close_label', 'Suche schließen'));
    this.closeButton.title = this.t('search_close_label', 'Suche schließen');
    this.closeButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.3 5.71 12 12.01l-6.3-6.3-1.41 1.41 6.3 6.3-6.3 6.3 1.41 1.41 6.3-6.3 6.3 6.3 1.41-1.41-6.3-6.3 6.3-6.3z"></path></svg>';

    // Fortschrittsbalken unter der Kopfzeile: zeigt, dass gesucht wird (Tippen → Suche läuft → Treffer)
    this.progress = document.createElement('div');
    this.progress.className = 'ai-search-progress';
    this.progress.setAttribute('aria-hidden', 'true');

    header.appendChild(this.input);
    header.appendChild(this.clearButton);
    header.appendChild(this.submitButton);
    header.appendChild(this.closeButton);
    panel.appendChild(header);
    panel.appendChild(this.progress);

    this.status = document.createElement('div');
    this.status.className = 'ai-search-status';
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    panel.appendChild(this.status);

    this.toolbar = document.createElement('div');
    this.toolbar.className = 'ai-search-toolbar';
    panel.appendChild(this.toolbar);

    this.actions = document.createElement('div');
    this.actions.className = 'ai-search-actions';
    panel.appendChild(this.actions);

    this.answerHost = document.createElement('div');
    this.answerHost.className = 'ai-search-answer-host';
    panel.appendChild(this.answerHost);

    this.results = document.createElement('div');
    this.results.className = 'ai-search-results';
    panel.appendChild(this.results);

    this.overlay.appendChild(panel);
    root.appendChild(this.overlay);

    document.body.appendChild(root);
    this.root = root;

    this.bindEvents();
    this.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');

    return this;
  };

  SearchUi.prototype.bindEvents = function () {
    var self = this;

    if (this.trigger) {
      this.trigger.addEventListener('click', function () {
        self.open();
      });
    }

    // Nur schliessen, wenn SOWOHL mousedown ALS AUCH click auf dem Hintergrund selbst lagen -
    // ein einzelner click-Check allein reicht nicht: markiert man Text im Suchfeld per Maus-Drag
    // und die Bewegung verlaesst kurz dessen Grenzen, kann der Klick beim Loslassen ausserhalb
    // (auf dem Overlay-Hintergrund) enden, obwohl die Auswahl klar im Feld begonnen hat - das
    // Suchfenster schloss sich dadurch faelschlich mitten in der Texteingabe.
    var overlayMouseDownOnBackdrop = false;
    this.overlay.addEventListener('mousedown', function (event) {
      overlayMouseDownOnBackdrop = event.target === self.overlay;
    });
    this.overlay.addEventListener('click', function (event) {
      if (event.target === self.overlay && overlayMouseDownOnBackdrop) {
        self.close();
      }
      overlayMouseDownOnBackdrop = false;
    });

    this.submitButton.addEventListener('click', function () {
      if (self.debounceTimer) {
        clearTimeout(self.debounceTimer);
      }
      self.search(true);
    });

    this.clearButton.addEventListener('click', function () {
      self.reset();
      self.input.focus();
    });

    this.closeButton.addEventListener('click', function () {
      self.close();
    });

    this.input.addEventListener('input', function () {
      var nextQuery = self.input.value.trim();
      self.clearButton.hidden = self.input.value === '';
      self.lastExtended = false;
      if (nextQuery.length < self.minimumQueryLength) {
        if (self.debounceTimer) {
          clearTimeout(self.debounceTimer);
        }
        self.abortSearch();
        self.setSearching(false);
        self.clearToolbar();
        self.renderChatAction('');
        self.renderAnswer('');
        self.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');
        return;
      }

      if (self.debounceTimer) {
        clearTimeout(self.debounceTimer);
      }
      // sofort zeigen, dass etwas passiert – die Anfrage selbst startet erst nach der Tipp-Pause
      self.setSearching(true);
      self.debounceTimer = setTimeout(function () {
        self.search();
      }, self.searchDebounceMs);
    });

    this.input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        self.close();
        return;
      }
      // Enter = "jetzt wirklich suchen" (erweiterte Suche mit Vektoranteil), analog
      // zum Submit-Button - vorher gab es hier gar keinen Enter-Handler, ein
      // Tastendruck lief nur ueber das normale "input"-Event mit Debounce.
      if (event.key === 'Enter') {
        event.preventDefault();
        if (self.debounceTimer) {
          clearTimeout(self.debounceTimer);
        }
        self.search(true);
      }
    });

    document.addEventListener('keydown', function (event) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        self.open();
      }
      if (event.key === 'Escape') {
        self.close();
      }
    });
  };

  // Laufende Suche anzeigen: Fortschrittsbalken, aria-busy, Statusmeldung für Screenreader
  SearchUi.prototype.setSearching = function (isSearching) {
    if (!this.root) {
      return;
    }
    this.root.classList.toggle('ai-search-busy', !!isSearching);
    if (this.results) {
      this.results.setAttribute('aria-busy', isSearching ? 'true' : 'false');
    }
    if (this.status) {
      this.status.textContent = isSearching ? this.t('search_status_searching', 'Suche läuft …') : '';
    }
  };

  SearchUi.prototype.abortSearch = function () {
    if (this.searchAbort) {
      this.searchAbort.abort();
      this.searchAbort = null;
    }
  };

  // Suche zurücksetzen: Feld leeren, laufende Anfragen verwerfen, Ausgangszustand
  SearchUi.prototype.reset = function () {
    if (this.debounceTimer) {
      clearTimeout(this.debounceTimer);
    }
    this.abortSearch();
    ++this.requestNonce;
    ++this.chatNonce;
    this.chatHistory = [];
    this.input.value = '';
    this.lastQuestionMarkQuery = '';
    this.lastExtended = false;
    this.selectedTypes = [];
    this.selectedLabels = [];
    this.lastFilters = [];
    this.lastLabelFilters = [];
    this.clearButton.hidden = true;
    this.setSearching(false);
    this.isChatLoading = false;
    this.clearToolbar();
    this.renderChatAction('');
    this.renderAnswer('');
    this.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');
  };

  SearchUi.prototype.open = function (query) {
    if (typeof query === 'string') {
      this.input.value = query;
    }
    this.clearButton.hidden = this.input.value === '';

    this.overlay.classList.add('open');
    this.input.focus();
    if (this.input.value.trim() !== '') {
      this.search();
    }
  };

  SearchUi.prototype.close = function () {
    this.overlay.classList.remove('open');
  };

  SearchUi.prototype.setQuery = function (query, runSearch) {
    this.input.value = typeof query === 'string' ? query : '';
    this.clearButton.hidden = this.input.value === '';
    if (runSearch) {
      this.search();
    }
  };

  // extended=true (nur bei explizitem Abschicken - Enter/Submit-Button, siehe
  // bindEvents() weiter unten) loest zusaetzlich zur bestehenden Keyword-Suche eine
  // echte Vektorsuche server-seitig aus (ChatQueryService::search()) - findet dadurch
  // auch thematisch verwandte, aber wortverschiedene Treffer. Bewusst NICHT beim
  // debounced Live-Tippen (extended bleibt dort false/undefined): ein Embedding-
  // API-Aufruf bei jedem Tastendruck waere spuerbar langsamer und liesse die Suche
  // von einem konfigurierten KI-Provider abhaengen, was dem Live-Tippen-Fall
  // widerspricht (siehe README "Suche funktioniert auch ohne KI-Provider").
  SearchUi.prototype.search = function (extended) {
    var self = this;
    var query = this.input.value.trim();
    var nonce = ++this.requestNonce;
    this.chatHistory = [];
    ++this.chatNonce;

    if (query === '' || query.length < this.minimumQueryLength) {
      this.setSearching(false);
      this.clearToolbar();
      this.renderChatAction('');
      this.renderAnswer('');
      this.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');
      return;
    }

    this.lastExtended = true === extended;

    // vorherige, noch laufende Suche abbrechen (schnelles Weitertippen erzeugt sonst Anfragen-Staus)
    this.abortSearch();
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    this.searchAbort = controller;
    this.setSearching(true);

    var payload = {
      mode: 'search',
      message: query,
      scope: this.searchScope,
      source_types: this.selectedTypes,
      source_labels: this.selectedLabels,
      limit: 30,
      extended: true === extended
    };

    fetch(this.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload),
      signal: controller ? controller.signal : undefined
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return {
            ok: response.ok,
            status: response.status,
            data: data
          };
        });
      })
      .then(function (result) {
        if (nonce !== self.requestNonce) {
          return;
        }
        self.searchAbort = null;
        self.setSearching(false);

        if (!result.ok || (result.data && result.data.error)) {
          var serverMessage = (result.data && result.data.error) ? String(result.data.error) : ('HTTP ' + String(result.status));
          throw new Error('Suche fehlgeschlagen: ' + serverMessage);
        }

        var data = result.data || {};

        if (data.privacy_warning_message) {
          self.clearToolbar();
          self.renderChatAction('');
          self.renderAnswer('<p>' + String(data.privacy_warning_message) + '</p>');
          self.renderEmpty('Aus Datenschutzgründen wurde diese Eingabe nicht verarbeitet.');
          return;
        }

        var hits = asArray(data.hits);
        self.renderToolbar(asArray(data.filters && data.filters.source_types), asArray(data.filters && data.filters.labels));

        if (hits.length === 0) {
          self.renderHits([]);
          self.renderChatAction(query);

          // nonsense_query (server-seitig erkannt, siehe ChatQueryService::
          // looksLikeNonsenseQuery()) - keine KI anfragen fuer offensichtliches
          // Kauderwelsch, direkt die einfache "keine Treffer"-Meldung zeigen.
          if (!data.nonsense_query && ((self.aiAuto() && self.shouldAskChatForEmptyResults(query)) || self.askedWithQuestionMark(query))) {
            self.setAnswerLoading(true, 'Die KI prüft, ob sie helfen kann...');
            self.fetchChatAnswer(query, nonce);
            return;
          }

          self.renderAnswer('');
          self.renderEmpty(self.showChatActionButton && self.aiMode !== 'search'
            ? self.t('search_empty_ask_ai', 'Keine Treffer gefunden. Versuch einen anderen Suchbegriff – oder frag die KI.')
            : 'Keine Treffer gefunden. Bitte versuchen Sie einen anderen Suchbegriff oder eine klarere Frage.');
          return;
        }

        self.renderChatAction(query);
        self.renderHits(hits);

        if ((self.aiAuto() && self.shouldAskChat(query, hits.length)) || self.askedWithQuestionMark(query)) {
          self.setAnswerLoading(true, 'KI analysiert die Treffer...');
          self.fetchChatAnswer(query, nonce);
          return;
        }

        self.setAnswerLoading(false);
        self.renderAnswer('');

        // Bereich-uebergreifende Zusammenstellung: bewusst NICHT Teil der mode=search-Antwort
        // (das liess die Trefferliste selbst auf einen vollen KI-Aufruf warten - fuehlte sich
        // nicht mehr wie Live-Suche an). 'summary_available' ist nur eine guenstige, sofort
        // verfuegbare Eignungspruefung (ChatQueryService::search()); die eigentliche
        // Zusammenstellung wird erst HIER, NACH dem Rendern der Treffer, separat nachgeladen -
        // niedrigere Prioritaet als eine echte Frage-Antwort oben, ueberschreibt deren
        // Ladeanzeige daher nicht.
        if (data.summary_available && self.aiAuto()) {
          self.fetchSearchSummary(query, hits, nonce);
        }
      })
      .catch(function (error) {
        if (nonce !== self.requestNonce || (error && error.name === 'AbortError')) {
          return;
        }

        self.searchAbort = null;
        self.setSearching(false);
        self.setAnswerLoading(false);
        self.renderAnswer('');
        self.renderEmpty('Fehler beim Laden der Suche: ' + toErrorMessage(error, 'Unbekannter Fehler'));
      });
  };

  // KI ohne Klick starten? Nur im Modus „automatisch“ (siehe aiTrigger im Konstruktor)
  // Modus „per Button“: endet die Eingabe mit einem Fragezeichen, ist die Frage erkennbar fertig – dann startet
  // die KI-Antwort wie nach Klick auf „Frag die KI“ (einmal je Frage, nicht erneut bei Filterwechsel o. Ä.).
  SearchUi.prototype.askedWithQuestionMark = function (query) {
    if (this.aiMode === 'search' || this.aiTrigger !== 'button') {
      return false;
    }
    var text = typeof query === 'string' ? query.trim() : '';
    if (!/[?？]$/.test(text) || text.replace(/[?？\s]+$/, '').length < this.minimumQueryLength) {
      return false;
    }
    if (this.lastQuestionMarkQuery === text) {
      return false;
    }
    this.lastQuestionMarkQuery = text;
    return true;
  };

  SearchUi.prototype.aiAuto = function () {
    return this.aiMode !== 'search' && this.aiTrigger === 'auto';
  };

  SearchUi.prototype.hasQuestionIntent = function (query) {
    var text = typeof query === 'string' ? query.trim() : '';
    if (text === '') {
      return false;
    }

    var lowered = text
      .toLowerCase()
      .replace(/^[\s'"„“‚‘]+/, '')
      .replace(/[.!:;,]+$/, '');

    if (/[?？]/.test(text)) {
      return true;
    }

    if (lowered.length >= 60) {
      return true;
    }

    var normalized = lowered.replace(/^(bitte|kannst du|kannst|könntest|koenntest|würdest|wuerdest|wäre|waere|sag mir|sagen sie|sagen Sie)\s+/, '');

    var questionWords = /(wer|wie|was|wann|wo|wohin|woher|welche|welcher|welches|warum|weshalb|wieso|wofuer|wofür|kann|kannst|koennte|könnte|soll|sollte|darf|ist|sind|gibt es|macht|mache|machst|machen)/i;
    return questionWords.test(normalized) || questionWords.test(lowered);
  };

  SearchUi.prototype.shouldAskChat = function (query, hitCount) {
    if (hitCount === 0) {
      return false;
    }

    return this.hasQuestionIntent(query);
  };

  SearchUi.prototype.shouldAskChatForEmptyResults = function (query) {
    var text = typeof query === 'string' ? query.trim() : '';
    if (text === '') {
      return false;
    }

    if (this.hasQuestionIntent(query)) {
      return true;
    }

    return text.length >= 30;
  };

  // KI-Antwort holen. Mit Streaming (Server: Einstellung „Streaming“, Antwort als text/event-stream)
  // erscheint der Text schon während die KI schreibt – der erste Satz nach ~1 s statt der ganzen
  // Antwort nach mehreren Sekunden; am Ende ersetzt die formatierte Antwort (HTML) den Rohtext.
  // Ohne Streaming (JSON-Antwort) wie bisher.
  SearchUi.prototype.fetchChatAnswer = function (query, nonce) {
    var self = this;
    self.setAnswerLoading(true, 'KI antwortet...');
    var payload = {
      mode: 'chat',
      message: query,
      scope: this.chatScope,
      include_followups: false
    };
    var canStream = typeof TextDecoder !== 'undefined' && typeof ReadableStream !== 'undefined';

    var finish = function (data) {
      if (nonce !== self.requestNonce) {
        return;
      }
      self.setAnswerLoading(false);
      var html = typeof data.answer === 'string' ? data.answer.trim() : '';
      self.renderAnswer(html);
      // Antwort steht – „Frag die KI“ erst bei einer neuen Eingabe wieder anbieten
      if (self.aiTrigger === 'button' && self.actions && html !== '') {
        self.actions.innerHTML = '';
      }
      if (self.aiMode === 'chat' && html !== '') {
        self.chatHistory = [
          { role: 'user', text: query },
          { role: 'assistant', text: typeof data.answer_text === 'string' && data.answer_text.trim() !== '' ? data.answer_text.trim() : htmlToText(html) }
        ];
        self.renderChatComposer();
        if (self.actions) {
          self.actions.innerHTML = '';
        }
      }
    };

    var fail = function (message) {
      if (nonce !== self.requestNonce) {
        return;
      }
      self.setAnswerLoading(false);
      self.renderAnswer(message ? '<p>Keine KI-Antwort: ' + escapeHtml(message) + '</p>' : '');
      self.renderChatAction(query); // erneut versuchen möglich
    };

    // Rohtext während des Streamens: eigener Kasten, Text als Text (kein HTML)
    var streamBox = null;
    var streamText = '';
    var showChunk = function (chunk) {
      if (nonce !== self.requestNonce) {
        return;
      }
      streamText += chunk;
      if (!streamBox) {
        self.setAnswerLoading(false);
        self.answerHost.innerHTML = '';
        var box = document.createElement('div');
        box.className = 'ai-search-answer ai-search-answer-streaming';
        var title = document.createElement('div');
        title.className = 'ai-search-answer-title';
        title.textContent = self.t('search_panel_title', 'Antwort');
        streamBox = document.createElement('div');
        streamBox.className = 'ai-search-answer-stream';
        box.appendChild(title);
        box.appendChild(streamBox);
        self.answerHost.appendChild(box);
      }
      // Markdown-Zeichen im Rohtext dezent entfernen (**fett**, Überschriften-Rauten, Link-Klammern)
      streamBox.textContent = streamText.replace(/\*\*|__|^#+\s*/gm, '').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1');
    };

    fetch(this.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': canStream ? 'text/event-stream, application/json' : 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        var type = response.headers.get('Content-Type') || '';
        if (canStream && response.ok && type.indexOf('text/event-stream') !== -1 && response.body) {
          return self.readAnswerStream(response, nonce, showChunk).then(function (result) {
            if (result.error) {
              fail(result.error);
            } else {
              finish(result.data || {});
            }
          });
        }
        return response.json().then(function (data) {
          if (!response.ok || (data && data.error)) {
            fail((data && data.error) ? String(data.error) : ('HTTP ' + String(response.status)));
            return;
          }
          finish(data || {});
        });
      })
      .catch(function () {
        fail('');
      });
  };

  // Server-Sent Events der Chat-API lesen: start → chunk {text} … → complete {answer, answer_text} | error {message}
  SearchUi.prototype.readAnswerStream = function (response, nonce, onChunk) {
    var self = this;
    var reader = response.body.getReader();
    var decoder = new TextDecoder();
    var buffer = '';
    var result = { data: null, error: null };

    var processFrame = function (part) {
      var eventName = 'message';
      var eventData = '';
      part.split(/\n/).forEach(function (line) {
        if (line.indexOf('event:') === 0) {
          eventName = line.slice(6).trim();
        } else if (line.indexOf('data:') === 0) {
          eventData += (eventData ? '\n' : '') + line.slice(5).trim();
        }
      });
      if (!eventData) {
        return;
      }
      var payload;
      try {
        payload = JSON.parse(eventData);
      } catch (e) {
        return;
      }
      if (eventName === 'chunk' && typeof payload.text === 'string') {
        onChunk(payload.text);
      } else if (eventName === 'complete') {
        result.data = payload;
      } else if (eventName === 'error') {
        result.error = typeof payload.message === 'string' ? payload.message : 'Fehler';
      }
    };

    var pump = function () {
      return reader.read().then(function (step) {
        if (nonce !== self.requestNonce) {
          reader.cancel();
          return result;
        }
        if (step.done) {
          if (buffer.trim()) {
            processFrame(buffer);
          }
          return result;
        }
        buffer += decoder.decode(step.value, { stream: true });
        var parts = buffer.split(/\n\n/);
        buffer = parts.pop() || '';
        parts.forEach(processFrame);
        return pump();
      });
    };

    return pump();
  };

  // Asynchroner Folgeaufruf zu 'summary_available' aus der mode=search-Antwort - siehe
  // Kommentar am Aufruf in search(). Die Trefferliste steht bereits vollstaendig, das ist nur
  // eine optionale Ergaenzung obendrauf, kein Ersatz fuer eine erwartete Antwort wie bei
  // fetchChatAnswer() - deshalb ein dezenter eigener Ladezustand ('summary'-Variante) statt
  // z.B. die Trefferliste selbst zu blockieren. Ohne jede Anzeige wirkte der KI-Aufruf (kann
  // je nach Provider/Backend mehrere Sekunden dauern) wie ein stillschweigend nicht
  // funktionierendes Feature statt wie eine laufende Anfrage.
  SearchUi.prototype.fetchSearchSummary = function (query, hits, nonce) {
    var self = this;
    self.setAnswerLoading(true, self.t('search_summary_loading', 'Wird zusammengefasst…'), 'summary');
    var payload = {
      mode: 'search_summary',
      message: query,
      scope: this.searchScope,
      hits: asArray(hits).map(function (hit) {
        return {
          title: hit && hit.title,
          snippet: hit && hit.snippet,
          label: hit && hit.label,
          url: hit && hit.url
        };
      })
    };

    fetch(this.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(payload)
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (nonce !== self.requestNonce) {
          return;
        }
        var summary = data && typeof data.summary === 'string' ? data.summary.trim() : '';
        // renderAnswer('') raeumt bei leerer Zusammenstellung den zuvor gezeigten
        // Ladezustand wieder ab, statt ihn stehen zu lassen (best effort, siehe unten -
        // keine Fehleranzeige, die Trefferliste bleibt in jedem Fall die Hauptantwort).
        self.renderAnswer(summary, 'summary');
      })
      .catch(function () {
        if (nonce === self.requestNonce) {
          self.renderAnswer('');
        }
        // Best effort - Trefferliste steht bereits, keine Fehleranzeige noetig.
      });
  };

  SearchUi.prototype.findChatWidget = function () {
    var widgets = Array.prototype.slice.call(document.querySelectorAll('ai-chat'));
    if (widgets.length === 0) {
      return null;
    }

    var preferred = widgets.find(function (el) {
      return (el.getAttribute('scope') || '').toLowerCase() === 'frontend';
    });

    return preferred || widgets[0] || null;
  };

  SearchUi.prototype.startInteractiveChat = function (query) {
    var self = this;
    return new Promise(function (resolve) {
      var text = typeof query === 'string' ? query.trim() : '';
      if (text === '') {
        resolve(false);
        return;
      }

      function tryStart() {
        var widget = self.findChatWidget();
        if (!widget || typeof widget.startQuery !== 'function') {
          return false;
        }

        widget.startQuery(text);
        self.close();
        return true;
      }

      if (tryStart()) {
        resolve(true);
        return;
      }

      if (!window.customElements || typeof window.customElements.whenDefined !== 'function') {
        resolve(false);
        return;
      }

      window.customElements.whenDefined('ai-chat')
        .then(function () {
          resolve(tryStart());
        })
        .catch(function () {
          resolve(false);
        });
    });
  };

  SearchUi.prototype.renderChatAction = function (query) {
    var self = this;
    if (!this.actions) {
      return;
    }

    this.actions.innerHTML = '';

    if (!this.showChatActionButton) {
      return;
    }

    var text = typeof query === 'string' ? query.trim() : '';
    if (text === '') {
      return;
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ai-search-chat-action';

    var branding = getChatBranding();
    if (branding.color) {
      btn.style.setProperty('--ai-search-chat-brand', branding.color);
    }

    var icon = document.createElement('span');
    icon.className = 'ai-search-chat-action-icon';

    if (branding.avatarUrl) {
      var image = document.createElement('img');
      image.src = branding.avatarUrl;
      image.alt = '';
      image.setAttribute('aria-hidden', 'true');
      icon.appendChild(image);
    } else {
      icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"></path></svg>';
    }

    var textNode = document.createElement('span');
    textNode.className = 'ai-search-chat-action-text';
    textNode.textContent = this.chatActionLabel;

    btn.appendChild(icon);
    btn.appendChild(textNode);
    btn.addEventListener('click', function () {
      if (self.chatActionBehavior === 'inline') {
        // aktuellen Feldinhalt nehmen (die Suche kann noch auf ein älteres Wort laufen)
        var current = self.input.value.trim() || text;
        if (self.debounceTimer) {
          clearTimeout(self.debounceTimer);
        }
        btn.disabled = true;
        btn.classList.add('is-loading');
        var inlineNonce = ++self.requestNonce;
        self.setSearching(false);
        self.fetchChatAnswer(current, inlineNonce);
        return;
      }

      self.startInteractiveChat(text).then(function (started) {
        if (started) {
          return;
        }

        var fallbackNonce = ++self.requestNonce;
        self.fetchChatAnswer(text, fallbackNonce);
      });
    });

    this.actions.appendChild(btn);
  };

  // variant unterscheidet eine echte Frage-Antwort (fetchChatAnswer(), Standard) von der
  // Bereich-uebergreifenden KI-Zusammenstellung (fetchSearchSummary(), variant='summary') -
  // beide teilten sich vorher denselben Titel "Antwort", obwohl es inhaltlich und in der
  // Verlaesslichkeit (eine Zusammenstellung ist eine grobe Orientierung ueber mehrere Bereiche,
  // keine direkte Antwort auf eine gestellte Frage) zwei unterschiedliche Dinge sind.
  // Chat im Such-Fenster (aiMode 'chat'): unter der KI-Antwort ein Verlauf mit Eingabefeld
  // fuer Nachfragen. Jede Nachfrage geht mit dem bisherigen Verlauf (history, wie beim
  // Chat-Widget) an mode=chat. Eine neue Suche im Hauptfeld beginnt ein neues Gespraech.
  SearchUi.prototype.renderChatComposer = function () {
    var self = this;
    if (!this.answerHost) {
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'ai-search-chat';

    var log = document.createElement('div');
    log.className = 'ai-search-chat-log';
    log.setAttribute('aria-live', 'polite');

    var form = document.createElement('form');
    form.className = 'ai-search-chat-form';
    form.setAttribute('autocomplete', 'off');

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'search-followup';
    input.className = 'ai-search-chat-input';
    input.placeholder = this.t('search_chat_placeholder', 'Nachfrage stellen …');
    input.setAttribute('aria-label', input.placeholder);
    input.setAttribute('enterkeyhint', 'send');
    input.setAttribute('data-1p-ignore', '');
    input.setAttribute('data-lpignore', 'true');
    input.setAttribute('data-bwignore', '');
    input.setAttribute('data-form-type', 'other');
    input.maxLength = 1000;

    var send = document.createElement('button');
    send.type = 'submit';
    send.className = 'ai-search-chat-send';
    send.textContent = this.t('search_chat_send', 'Senden');

    form.appendChild(input);
    form.appendChild(send);
    wrap.appendChild(log);
    wrap.appendChild(form);
    this.answerHost.appendChild(wrap);

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        input.blur();
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var text = input.value.trim();
      if (text === '' || send.disabled) {
        return;
      }
      input.value = '';
      self.sendChatMessage(text, log, input, send);
    });
  };

  SearchUi.prototype.appendChatMessage = function (log, role, html, isText) {
    var item = document.createElement('div');
    item.className = 'ai-search-chat-msg ai-search-chat-msg-' + role;
    var label = document.createElement('span');
    label.className = 'ai-search-chat-who';
    label.textContent = role === 'user' ? this.t('search_chat_you', 'Du') : this.t('search_panel_title', 'Antwort');
    var body = document.createElement('div');
    body.className = 'ai-search-chat-body ai-search-answer-body';
    if (isText) {
      body.textContent = html;
    } else {
      body.innerHTML = html;
    }
    item.appendChild(label);
    item.appendChild(body);
    log.appendChild(item);
    return item;
  };

  SearchUi.prototype.sendChatMessage = function (text, log, input, send) {
    var self = this;
    var nonce = ++this.chatNonce;
    var history = this.chatHistory.slice(-6);

    this.appendChatMessage(log, 'user', text, true);
    var pending = this.appendChatMessage(log, 'bot', '<span class="ai-search-loading-dots"><span></span><span></span><span></span></span>', false);
    pending.classList.add('ai-search-chat-pending');
    send.disabled = true;
    pending.scrollIntoView({ block: 'nearest' });

    fetch(this.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        mode: 'chat',
        message: text,
        scope: this.chatScope,
        include_followups: false,
        current_url: window.location.href,
        history: history
      })
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, status: response.status, data: data };
        });
      })
      .then(function (result) {
        // Neue Suche inzwischen gestartet (Verlauf geleert) oder Fenster neu aufgebaut
        if (nonce !== self.chatNonce || !document.body.contains(log)) {
          return;
        }
        var data = result.data || {};
        var html = typeof data.answer === 'string' ? data.answer.trim() : '';
        if (!result.ok || data.error || html === '') {
          html = '<p>' + self.t('search_chat_error', 'Darauf habe ich gerade keine Antwort. Bitte formuliere die Frage anders.') + '</p>';
        } else {
          self.chatHistory.push({ role: 'user', text: text });
          self.chatHistory.push({ role: 'assistant', text: typeof data.answer_text === 'string' && data.answer_text.trim() !== '' ? data.answer_text.trim() : htmlToText(html) });
        }
        pending.classList.remove('ai-search-chat-pending');
        pending.querySelector('.ai-search-chat-body').innerHTML = html;
      })
      .catch(function () {
        if (nonce !== self.chatNonce || !document.body.contains(log)) {
          return;
        }
        pending.classList.remove('ai-search-chat-pending');
        pending.querySelector('.ai-search-chat-body').textContent = self.t('search_error', 'Live-Suche aktuell nicht verfügbar.');
      })
      .then(function () {
        if (nonce === self.chatNonce) {
          send.disabled = false;
          input.focus();
        }
      });
  };

  SearchUi.prototype.renderAnswer = function (answerHtml, variant) {
    if (!this.answerHost) {
      return;
    }

    this.answerHost.innerHTML = '';

    if (!answerHtml) {
      this.setAnswerLoading(false);
      return;
    }

    var isSummary = variant === 'summary';

    var box = document.createElement('div');
    box.className = isSummary ? 'ai-search-answer ai-search-answer-summary' : 'ai-search-answer';

    var title = document.createElement('div');
    title.className = 'ai-search-answer-title';
    title.textContent = isSummary
      ? this.t('search_summary_title', 'Überblick über die Bereiche')
      : this.t('search_panel_title', 'Antwort');

    var body = document.createElement('div');
    body.className = 'ai-search-answer-body';
    body.innerHTML = answerHtml;

    box.appendChild(title);
    box.appendChild(this.buildAnswerBodyContent(body));
    this.answerHost.appendChild(box);
  };

  // Trennt einen etwaigen Quellen-Block ("Links:") vom Rest ab, BEVOR die Hoehen-Begrenzung
  // greift - der Quellen-Block bleibt so immer sichtbar, auch eingeklappt. Der Server fuegt
  // vor dem Quellen-Absatz ein <hr> ein (siehe ChatQueryService::parseMarkdown()), das hier
  // als verlaesslicher, sprachunabhaengiger Trennpunkt dient (der konfigurierbare Titel
  // "Links:"/"Quellen:" waere dem Client sonst gar nicht bekannt).
  SearchUi.prototype.buildAnswerBodyContent = function (body) {
    var fragment = document.createDocumentFragment();
    var hrs = body.querySelectorAll('hr');
    var lastHr = hrs.length > 0 ? hrs[hrs.length - 1] : null;

    var sourcesNodes = [];
    if (lastHr) {
      var node = lastHr;
      while (node) {
        var next = node.nextSibling;
        sourcesNodes.push(node);
        node = next;
      }
      sourcesNodes.forEach(function (n) { body.removeChild(n); });
    }

    var collapse = document.createElement('div');
    collapse.className = 'ai-search-answer-collapse';
    collapse.appendChild(body);
    fragment.appendChild(collapse);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ai-search-answer-toggle';
    var showMoreLabel = this.t('search_answer_show_more', 'Mehr anzeigen');
    var showLessLabel = this.t('search_answer_show_less', 'Weniger anzeigen');
    toggle.textContent = showMoreLabel;
    toggle.hidden = true;
    toggle.addEventListener('click', function () {
      var expanded = collapse.classList.toggle('ai-search-answer-expanded');
      toggle.textContent = expanded ? showLessLabel : showMoreLabel;
    });
    fragment.appendChild(toggle);

    if (sourcesNodes.length > 0) {
      var sources = document.createElement('div');
      sources.className = 'ai-search-answer-sources';
      sourcesNodes.forEach(function (n) { sources.appendChild(n); });
      fragment.appendChild(sources);
    }

    // Erst nach dem Einhaengen ins Dokument hat scrollHeight einen verlaesslichen Wert -
    // requestAnimationFrame wartet auf das naechste Layout, ohne synchron zu blockieren.
    requestAnimationFrame(function () {
      if (collapse.scrollHeight > collapse.clientHeight + 4) {
        collapse.classList.add('ai-search-answer-collapse-clamped');
        toggle.hidden = false;
      }
    });

    return fragment;
  };

  // Filterleiste wirklich leeren (Eingabe gelöscht/zu kurz, Datenschutz-Hinweis) – ohne die bisherigen Filter
  SearchUi.prototype.clearToolbar = function () {
    this.lastFilters = [];
    this.lastLabelFilters = [];
    if (this.toolbar) {
      this.toolbar.innerHTML = '';
    }
  };

  // Sofortige Rückmeldung beim Filter-Klick: gewählter Filter (oder „Alle“) sofort aktiv, nicht erst nach der Antwort
  SearchUi.prototype.markChip = function (chip) {
    if (!this.toolbar) {
      return;
    }
    var chips = this.toolbar.querySelectorAll('.ai-search-chip');
    Array.prototype.forEach.call(chips, function (c) {
      c.classList.remove('active');
      c.setAttribute('aria-pressed', 'false');
    });
    var target = chip || (this.filterMode === 'single' ? chips[0] : null);
    if (target) {
      target.classList.add('active');
      target.setAttribute('aria-pressed', 'true');
    }
  };

  SearchUi.prototype.renderToolbar = function (filters, labelFilters) {
    var self = this;
    this.toolbar.innerHTML = '';
    filters = filters || [];
    labelFilters = labelFilters || [];

    // Ein Filter ist aktiv, liefert aber nichts: die bisherige Filterleiste stehen lassen – sonst verschwinden alle
    // Filter samt „Alle“ und man kommt aus der leeren Suche nicht mehr heraus
    var filterActive = this.selectedTypes.length > 0 || this.selectedLabels.length > 0;
    if (filterActive && filters.length === 0 && labelFilters.length === 0) {
      filters = this.lastFilters;
      labelFilters = this.lastLabelFilters;
    } else {
      this.lastFilters = filters;
      this.lastLabelFilters = labelFilters;
    }

    if (filters.length === 0 && (!labelFilters || labelFilters.length === 0)) {
      return;
    }

    if (filters.length > 0) {
      var allBtn = document.createElement('button');
      allBtn.type = 'button';
      var allActive = this.selectedTypes.length === 0 && (this.filterMode === 'multi' || this.selectedLabels.length === 0);
      allBtn.className = 'ai-search-chip' + (allActive ? ' active' : '');
      allBtn.setAttribute('aria-pressed', allActive ? 'true' : 'false');
      allBtn.textContent = this.t('search_filter_all', 'Alle');
      allBtn.addEventListener('click', function () {
        self.selectedTypes = [];
        if (self.filterMode === 'single') {
          self.selectedLabels = [];
        }
        self.markChip(allBtn);
        self.search(self.lastExtended);
      });
      this.toolbar.appendChild(allBtn);

      filters.forEach(function (filter) {
        var value = filter.value || '';
        if (!value) {
          return;
        }

        var active = self.selectedTypes.indexOf(value) !== -1;
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'ai-search-chip' + (active ? ' active' : '');
        chip.textContent = (filter.label || value) + ' (' + (filter.count || 0) + ')';
        chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        chip.addEventListener('click', function () {
          if (active) {
            self.selectedTypes = self.selectedTypes.filter(function (item) { return item !== value; });
          } else if (self.filterMode === 'single') {
            self.selectedTypes = [value];
            self.selectedLabels = [];
          } else {
            self.selectedTypes.push(value);
          }
          self.markChip(active ? null : chip);
          self.search(self.lastExtended);
        });

        self.toolbar.appendChild(chip);
      });
    }

    // Zweite Facette: benannte Sitemap-Gruppen (ChatProfile::$sitemapGroups, z.B. "News") -
    // eigene Chip-Reihe, unabhängig von source_types filterbar (ein "News"-Treffer kann z.B.
    // gleichzeitig vom Typ "sitemap_url" sein).
    if (labelFilters && labelFilters.length > 0) {
      var labelSeparator = document.createElement('span');
      labelSeparator.className = 'ai-search-chip-separator';
      labelSeparator.setAttribute('aria-hidden', 'true');
      this.toolbar.appendChild(labelSeparator);

      labelFilters.forEach(function (filter) {
        var value = filter.value || '';
        if (!value) {
          return;
        }

        var active = self.selectedLabels.indexOf(value) !== -1;
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'ai-search-chip ai-search-chip-label' + (active ? ' active' : '');
        chip.textContent = (filter.label || value) + ' (' + (filter.count || 0) + ')';
        if (filter.description) {
          chip.title = String(filter.description);
        }
        chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        chip.addEventListener('click', function () {
          if (active) {
            self.selectedLabels = self.selectedLabels.filter(function (item) { return item !== value; });
          } else if (self.filterMode === 'single') {
            self.selectedLabels = [value];
            self.selectedTypes = [];
          } else {
            self.selectedLabels.push(value);
          }
          self.markChip(active ? null : chip);
          self.search(self.lastExtended);
        });

        self.toolbar.appendChild(chip);
      });
    }
  };

  SearchUi.prototype.renderHits = function (hits) {
    var self = this;
    this.results.innerHTML = '';

    if (hits.length === 0) {
      this.renderEmpty(this.t('search_empty_results', 'Keine Treffer gefunden.'));
      return;
    }

    hits.forEach(function (hit) {
      var link = document.createElement('a');
      link.className = 'ai-search-item';
      link.href = hit.url || '#';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';

      var isForcalHit = (hit.type || '').trim() === 'forcal_entry';
      var hitIcon = createProviderIcon(hit.icon_svg || '');
      if (isForcalHit) {
        link.classList.add('ai-search-item-event');
      }

      var title = document.createElement('h4');
      title.className = 'ai-search-item-title';
      title.textContent = hit.title || 'Ohne Titel';

      if (hitIcon || isForcalHit) {
        var titleWrap = document.createElement('span');
        titleWrap.className = 'ai-search-item-title-wrap';

        titleWrap.appendChild(hitIcon || createCalendarIcon());

        var titleText = document.createElement('span');
        titleText.className = 'ai-search-item-title-text';
        titleText.textContent = title.textContent;
        title.textContent = '';

        titleWrap.appendChild(titleText);
        title.appendChild(titleWrap);
      }

      var meta = document.createElement('p');
      meta.className = 'ai-search-item-meta';

      var typeLabel = (hit.type_label || '').trim();
      if (typeLabel === '') {
        var rawType = (hit.type || '').trim();
        // Avoid exposing technical source keys like "sitemap_url" to visitors.
        if (rawType !== '' && rawType.indexOf('_') === -1) {
          typeLabel = rawType;
        }
      }

      if (typeLabel !== '') {
        var badge = document.createElement('span');
        badge.className = 'ai-search-item-type';
        badge.textContent = typeLabel;
        meta.appendChild(badge);
      }

      if (hit.updatedate) {
        var time = document.createElement('span');
        time.className = 'ai-search-item-date';
        time.textContent = formatUpdatedAt(hit.updatedate);
        meta.appendChild(time);
      }

      var snippet = document.createElement('p');
      snippet.className = 'ai-search-item-snippet';
      // hit.snippet ist server-seitig bereits htmlspecialchars()-escaped, mit <mark>
      // als einzigem erlaubten Tag um Suchbegriff-Treffer (siehe
      // ChatQueryService::highlightSnippetSegment()) - deshalb hier sicher per
      // innerHTML statt textContent, formatForcalSnippet() macht nur reine
      // Text-Ersetzungen (":"/"|") auf dem bereits escapten String.
      snippet.innerHTML = isForcalHit ? formatForcalSnippet(hit.snippet || '') : (hit.snippet || '');

      // image_url ist nur bei PDF-Dokumenten (PDF-Erstseiten-Thumbnail via pdfout) und
      // Artikeln mit konfiguriertem Titelbild-Metainfo-Feld gesetzt (siehe
      // ChatQueryService::search()) - bei jedem anderen Treffer bleibt es einfach beim
      // bestehenden Typ-Icon oben im Titel, kein leerer Platzhalter.
      if (hit.image_url) {
        var thumb = document.createElement('img');
        thumb.className = 'ai-search-item-thumb';
        thumb.src = hit.image_url;
        thumb.alt = '';
        thumb.loading = 'lazy';

        var body = document.createElement('span');
        body.className = 'ai-search-item-body';
        body.appendChild(title);
        body.appendChild(meta);
        body.appendChild(snippet);

        link.classList.add('ai-search-item-has-thumb');
        link.appendChild(thumb);
        link.appendChild(body);
      } else {
        link.appendChild(title);
        link.appendChild(meta);
        link.appendChild(snippet);
      }

      link.addEventListener('click', function () {
        self.close();
      });

      self.results.appendChild(link);
    });
  };

  SearchUi.prototype.renderEmpty = function (message) {
    this.results.innerHTML = '';
    var empty = document.createElement('div');
    empty.className = 'ai-search-empty';
    empty.textContent = message;
    this.results.appendChild(empty);
  };

  function ensureInstance(options) {
    if (!window.__aiSearchInstance) {
      window.__aiSearchInstance = new SearchUi(options).mount();
    }
    return window.__aiSearchInstance;
  }

  window.AiSearch = {
    getInstance: function (options) {
      return ensureInstance(options || {});
    },

    initFrontend: function (options) {
      return ensureInstance(options || {});
    },

    openWithQuery: function (query, options) {
      var instance = ensureInstance(options || {});
      instance.open(typeof query === 'string' ? query : '');
      return instance;
    },

    mountDemo: function (container, options) {
      if (!container) {
        return null;
      }

      var instance = ensureInstance(options || {});
      container.innerHTML = '';

      var box = document.createElement('div');
      box.className = 'ai-search-demo-box';

      var p = document.createElement('p');
      p.textContent = 'Suche im Spotlight-Stil mit AJAX und Typ-Filtern.';

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ai-search-demo-btn';
      btn.textContent = 'Suche öffnen';
      btn.addEventListener('click', function () {
        instance.open();
      });

      box.appendChild(p);
      box.appendChild(btn);
      container.appendChild(box);

      return instance;
    }
  };

  function isBackendPage() {
    if (!document.body) {
      return false;
    }

    var cls = document.body.className || '';
    return cls.indexOf('rex-theme-') !== -1 || cls.indexOf('rex-page') !== -1;
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (isBackendPage()) {
      return;
    }

    window.AiSearch.initFrontend();
  });
})();
