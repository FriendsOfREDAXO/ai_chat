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

  SearchUi.prototype.setAnswerLoading = function (isLoading, label) {
    this.isChatLoading = !!isLoading;

    if (!this.answerHost) {
      return;
    }

    if (!this.isChatLoading) {
      return;
    }

    this.answerHost.innerHTML = '';

    var box = document.createElement('div');
    box.className = 'ai-search-answer ai-search-answer-loading';

    var title = document.createElement('div');
    title.className = 'ai-search-answer-title';
    title.textContent = this.t('search_panel_title', 'Antwort');

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

    // Live-Suche (debounced) bleibt der Regelfall - der Button ist fuer Nutzer:innen gedacht,
    // die lieber explizit "suchen" ausloesen (z.B. nach Abbruch der Eingabe per Tab/Klick weg
    // vom Feld) statt sich auf Tippen zu verlassen, sowie als sichtbarer Hinweis, dass hier
    // ueberhaupt gesucht werden kann.
    this.submitButton = document.createElement('button');
    this.submitButton.type = 'button';
    this.submitButton.className = 'ai-search-submit';
    this.submitButton.textContent = this.t('search_submit_label', 'Suchen');

    header.appendChild(this.input);
    header.appendChild(this.submitButton);
    panel.appendChild(header);

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
      self.search();
    });

    this.input.addEventListener('input', function () {
      var nextQuery = self.input.value.trim();
      if (nextQuery.length < self.minimumQueryLength) {
        if (self.debounceTimer) {
          clearTimeout(self.debounceTimer);
        }
        self.renderToolbar([]);
        self.renderChatAction('');
        self.renderAnswer('');
        self.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');
        return;
      }

      if (self.debounceTimer) {
        clearTimeout(self.debounceTimer);
      }
      self.debounceTimer = setTimeout(function () {
        self.search();
      }, self.searchDebounceMs);
    });

    this.input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        self.close();
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

  SearchUi.prototype.open = function (query) {
    if (typeof query === 'string') {
      this.input.value = query;
    }

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
    if (runSearch) {
      this.search();
    }
  };

  SearchUi.prototype.search = function () {
    var self = this;
    var query = this.input.value.trim();
    var nonce = ++this.requestNonce;

    if (query === '' || query.length < this.minimumQueryLength) {
      this.renderToolbar([]);
      this.renderChatAction('');
      this.renderAnswer('');
      this.renderEmpty('Starte mit einem Suchbegriff oder einer Frage.');
      return;
    }

    var payload = {
      mode: 'search',
      message: query,
      scope: this.searchScope,
      source_types: this.selectedTypes,
      source_labels: this.selectedLabels,
      limit: 30
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

        if (!result.ok || (result.data && result.data.error)) {
          var serverMessage = (result.data && result.data.error) ? String(result.data.error) : ('HTTP ' + String(result.status));
          throw new Error('Suche fehlgeschlagen: ' + serverMessage);
        }

        var data = result.data || {};

        if (data.privacy_warning_message) {
          self.renderToolbar([]);
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
          if (!data.nonsense_query && self.shouldAskChatForEmptyResults(query)) {
            self.setAnswerLoading(true, 'Die KI prüft, ob sie helfen kann...');
            self.fetchChatAnswer(query, nonce);
            return;
          }

          self.renderAnswer('');
          self.renderEmpty('Keine Treffer gefunden. Bitte versuchen Sie einen anderen Suchbegriff oder eine klarere Frage.');
          return;
        }

        self.renderChatAction(query);
        self.renderHits(hits);

        if (self.shouldAskChat(query, hits.length)) {
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
        if (data.summary_available) {
          self.fetchSearchSummary(query, hits, nonce);
        }
      })
      .catch(function (error) {
        if (nonce !== self.requestNonce) {
          return;
        }

        self.setAnswerLoading(false);
        self.renderAnswer('');
        self.renderEmpty('Fehler beim Laden der Suche: ' + toErrorMessage(error, 'Unbekannter Fehler'));
      });
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

  SearchUi.prototype.fetchChatAnswer = function (query, nonce) {
    var self = this;
    self.setAnswerLoading(true, 'KI antwortet...');
    var payload = {
      mode: 'chat',
      message: query,
      scope: this.chatScope,
      include_followups: false
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

        self.setAnswerLoading(false);
        if (!result.ok || (result.data && result.data.error)) {
          var message = (result.data && result.data.error) ? String(result.data.error) : ('HTTP ' + String(result.status));
          self.renderAnswer('<p>Keine KI-Antwort: ' + message + '</p>');
          return;
        }

        var data = result.data || {};

        var html = typeof data.answer === 'string' ? data.answer.trim() : '';
        self.renderAnswer(html);
      })
      .catch(function () {
        if (nonce !== self.requestNonce) {
          return;
        }

        self.setAnswerLoading(false);
        self.renderAnswer('');
      });
  };

  // Asynchroner Folgeaufruf zu 'summary_available' aus der mode=search-Antwort - siehe
  // Kommentar am Aufruf in search(). Bewusst OHNE sichtbaren Ladezustand: die Trefferliste
  // steht bereits vollstaendig, das ist nur eine optionale Ergaenzung obendrauf, kein Ersatz
  // fuer eine erwartete Antwort wie bei fetchChatAnswer().
  SearchUi.prototype.fetchSearchSummary = function (query, hits, nonce) {
    var self = this;
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
        if (summary !== '') {
          self.renderAnswer(summary, 'summary');
        }
      })
      .catch(function () {
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
        var inlineNonce = ++self.requestNonce;
        self.fetchChatAnswer(text, inlineNonce);
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

  SearchUi.prototype.renderToolbar = function (filters, labelFilters) {
    var self = this;
    this.toolbar.innerHTML = '';

    if (filters.length === 0 && (!labelFilters || labelFilters.length === 0)) {
      return;
    }

    if (filters.length > 0) {
      var allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'ai-search-chip' + (this.selectedTypes.length === 0 ? ' active' : '');
      allBtn.textContent = this.t('search_filter_all', 'Alle');
      allBtn.addEventListener('click', function () {
        self.selectedTypes = [];
        self.search();
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
        chip.addEventListener('click', function () {
          if (active) {
            self.selectedTypes = self.selectedTypes.filter(function (item) { return item !== value; });
          } else {
            self.selectedTypes.push(value);
          }
          self.search();
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
        chip.addEventListener('click', function () {
          if (active) {
            self.selectedLabels = self.selectedLabels.filter(function (item) { return item !== value; });
          } else {
            self.selectedLabels.push(value);
          }
          self.search();
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

      link.appendChild(title);
      link.appendChild(meta);
      link.appendChild(snippet);

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
