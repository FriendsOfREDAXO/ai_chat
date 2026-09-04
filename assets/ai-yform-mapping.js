(function () {
    'use strict';

    function getRoot() {
        return document.getElementById('ai-yform-mapping-root');
    }

    function getColumnsMap() {
        var root = getRoot();
        if (!root) {
            return {};
        }

        var raw = root.dataset.columnsMap || '{}';
        try {
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    }

    function uid(prefix) {
        return prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function replaceTokens(html, tokens) {
        var output = html;
        Object.keys(tokens).forEach(function (token) {
            output = output.split(token).join(tokens[token]);
        });
        return output;
    }

    function createElementFromTemplate(template, tokens) {
        if (!template) {
            return null;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = replaceTokens(template.innerHTML, tokens).trim();
        return wrapper.firstElementChild;
    }

    function fillColumnSelect(select, tableName) {
        if (!select) {
            return;
        }

        var currentValue = String(select.dataset.currentValue || select.value || '');
        var allowEmpty = String(select.dataset.allowEmpty || '1') !== '0';
        var columnsMap = getColumnsMap();
        var columns = Array.isArray(columnsMap[tableName]) ? columnsMap[tableName] : [];

        var options = [];
        if (allowEmpty) {
            options.push({ value: '', label: select.dataset.emptyLabel || '— optional —' });
        }

        if (currentValue && !columns.some(function (column) {
            return String(column && column.name ? column.name : '') === currentValue;
        })) {
            options.unshift({ value: currentValue, label: currentValue + ' (nicht in Tabelle)' });
        }

        columns.forEach(function (column) {
            var name = String(column && column.name ? column.name : '').trim();
            if (!name) {
                return;
            }
            var label = String(column && column.label ? column.label : name).trim() || name;
            options.push({ value: name, label: label });
        });

        var html = '';
        options.forEach(function (option) {
            var selected = option.value === currentValue ? ' selected' : '';
            html += '<option value="' + option.value.replace(/"/g, '&quot;') + '"' + selected + '>' + option.label.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });

        select.innerHTML = html;
        if (currentValue) {
            select.value = currentValue;
        }
    }

    function refreshProfile(profileEl) {
        if (!profileEl) {
            return;
        }

        var tableSelect = profileEl.querySelector('.js-table-select');
        var tableName = tableSelect ? String(tableSelect.value || '') : '';
        profileEl.querySelectorAll('.js-column-select').forEach(function (select) {
            fillColumnSelect(select, tableName);
        });
    }

    // Nur das zum gewaehlten URL-Modus passende Feld anzeigen (URL-Feld/-Profil/-Template
    // schliessen sich gegenseitig aus) statt alle drei immer nebeneinander stehen zu lassen -
    // vorher war z.B. bei "URL-Profil (Namespace)" nicht ersichtlich, dass "URL-Feld"/
    // "URL-Template" fuer diesen Modus gar keine Wirkung haben.
    function updateUrlModeVisibility(urlModeSelect) {
        if (!urlModeSelect) {
            return;
        }

        var row = urlModeSelect.closest('.row');
        if (!row) {
            return;
        }

        var mode = String(urlModeSelect.value || 'field');
        row.querySelectorAll('[data-url-mode-field]').forEach(function (fieldEl) {
            fieldEl.style.display = fieldEl.getAttribute('data-url-mode-field') === mode ? '' : 'none';
        });
    }

    function bindRepeater(repeaterEl) {
        if (!repeaterEl || repeaterEl.dataset.bound === '1') {
            return;
        }

        repeaterEl.dataset.bound = '1';
    }

    function bindProfile(profileEl) {
        if (!profileEl || profileEl.dataset.bound === '1') {
            return;
        }

        profileEl.dataset.bound = '1';

        var tableSelect = profileEl.querySelector('.js-table-select');
        if (tableSelect) {
            tableSelect.addEventListener('change', function () {
                refreshProfile(profileEl);
            });
        }

        var urlModeSelect = profileEl.querySelector('.js-url-mode-select');
        if (urlModeSelect) {
            urlModeSelect.addEventListener('change', function () {
                updateUrlModeVisibility(urlModeSelect);
            });
            updateUrlModeVisibility(urlModeSelect);
        }

        profileEl.querySelectorAll('.klxm-repeater').forEach(function (repeaterEl) {
            bindRepeater(repeaterEl);
        });

        refreshProfile(profileEl);
    }

    function handleDelegatedClick(event) {
        var addRepeaterButton = event.target.closest('[data-add-repeater-row]');
        if (addRepeaterButton) {
            var repeaterEl = addRepeaterButton.closest('.klxm-repeater');
            var itemsEl = repeaterEl ? repeaterEl.querySelector('.klxm-repeater-items') : null;
            var template = repeaterEl ? repeaterEl.querySelector('template.klxm-repeater-template') : null;
            if (!repeaterEl || !itemsEl || !template) {
                return;
            }

            event.preventDefault();
            var rowHtml = createElementFromTemplate(template, {
                '__ROW__': uid('row')
            });
            if (!rowHtml) {
                return;
            }

            itemsEl.appendChild(rowHtml);
            var profileCard = repeaterEl.closest('[data-profile-card]');
            if (profileCard) {
                refreshProfile(profileCard);
            }
            return;
        }

        var removeRepeaterButton = event.target.closest('[data-remove-repeater-row]');
        if (removeRepeaterButton) {
            var row = removeRepeaterButton.closest('[data-repeater-row]');
            if (row) {
                event.preventDefault();
                row.remove();
            }
        }
    }

    function init() {
        var root = getRoot();
        if (!root) {
            return;
        }

        document.querySelectorAll('[data-profile-card]').forEach(function (profileEl) {
            bindProfile(profileEl);
        });

        if (!document.body.dataset.klxmYformMappingBound) {
            document.body.dataset.klxmYformMappingBound = '1';
            document.addEventListener('click', handleDelegatedClick);
        }
    }

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('rex:ready', init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
