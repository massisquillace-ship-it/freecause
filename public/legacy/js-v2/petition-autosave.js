(function () {
    'use strict';

    var form = document.querySelector('form[data-petition-form]');
    if (!form) {
        return;
    }

    var storage;
    try {
        storage = window.localStorage;
        var probe = '__fc_probe__';
        storage.setItem(probe, '1');
        storage.removeItem(probe);
    } catch (e) {
        return;
    }
    if (!storage) {
        return;
    }

    var locale = form.getAttribute('data-draft-locale') || 'new';
    var editId = form.getAttribute('data-draft-id') || 'new';
    var userId = form.getAttribute('data-draft-user') || 'guest';
    var key = 'fc_petition_draft_' + userId + '_' + locale + '_' + editId;

    var status = form.querySelector('[data-autosave-status]');
    var statusTimer = null;

    function setStatus(text) {
        if (!status) {
            return;
        }
        status.textContent = text;
        if (statusTimer) {
            window.clearTimeout(statusTimer);
            statusTimer = null;
        }
        if (text) {
            statusTimer = window.setTimeout(function () {
                status.textContent = '';
            }, 2500);
        }
    }

    function isSaveable(field) {
        if (!field || !field.name) {
            return false;
        }
        if (field.name === '_token' || field.name === 'MAX_FILE_SIZE') {
            return false;
        }
        var tag = field.tagName;
        if (tag === 'SELECT' || tag === 'TEXTAREA') {
            return true;
        }
        if (tag === 'INPUT') {
            var type = (field.getAttribute('type') || 'text').toLowerCase();
            if (type === 'file' || type === 'password' || type === 'hidden' || type === 'checkbox' || type === 'radio') {
                return false;
            }
            return true;
        }
        return false;
    }

    function collectFields() {
        var out = [];
        var nodes = form.querySelectorAll('input, textarea, select');
        for (var i = 0; i < nodes.length; i++) {
            if (isSaveable(nodes[i])) {
                out.push(nodes[i]);
            }
        }
        return out;
    }

    function readDraft() {
        try {
            var raw = storage.getItem(key);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    function saveDraft() {
        var fields = collectFields();
        var data = {};
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            data[f.name] = f.value;
        }
        try {
            storage.setItem(key, JSON.stringify(data));
            setStatus(form.getAttribute('data-draft-saved') || 'Draft saved');
        } catch (e) {
            setStatus('');
        }
    }

    function restoreDraft() {
        var draft = readDraft();
        if (!draft) {
            return;
        }
        var fields = collectFields();
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (!Object.prototype.hasOwnProperty.call(draft, f.name)) {
                continue;
            }
            // Attribution is an explicit saved choice, including an empty 'Myself'.
            // Never replace it with the newest organization on a later visit.
            if (f.name === 'organization_id' && editId === 'new' && typeof draft[f.name] === 'string') {
                var choice = draft[f.name];
                if (!Array.prototype.some.call(f.options, function (option) { return option.value === choice; })) {
                    var unavailable = document.createElement('option');
                    unavailable.value = choice;
                    unavailable.textContent = form.getAttribute('data-organization-unavailable');
                    f.appendChild(unavailable);
                }
                f.value = choice;
                continue;
            }
            var current = f.value;
            var stored = draft[f.name];
            if ((current === '' || current == null) && stored) {
                f.value = stored;
            }
        }
    }

    function clearDraft() {
        try {
            storage.removeItem(key);
        } catch (e) {
        }
    }

    var debounceTimer = null;
    function onInput() {
        if (debounceTimer) {
            window.clearTimeout(debounceTimer);
        }
        debounceTimer = window.setTimeout(saveDraft, 800);
    }

    restoreDraft();

    form.addEventListener('input', onInput, true);
    form.addEventListener('change', onInput, true);

    form.addEventListener('submit', function () {
        if (debounceTimer) {
            window.clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        clearDraft();
    });
})();
