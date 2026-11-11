/*
 * The media field's browser part (module-dms, bauplan `media-picker-bauplan.md` P1) — for
 * `partials/mediaField`. Everything else of the field is core.js and the server:
 *
 *   «Auswählen …»  [data-media-field-pick]  a `data-window-open` link — core.js opens the picker
 *                  window (ADR-047); the picker's choice answers with commands addressed to the
 *                  field by its DOM id: `set-value` on [data-media-field-input] (fires `change`),
 *                  `replace-html` of [data-media-field-thumb], `close-modal`, `focus`.
 *   «Entfernen»    [data-media-field-clear]  HERE: the hidden input emptied (a bubbling `change`
 *                  follows, as after a choice) and the thumbnail replaced by the field's own
 *                  <template data-media-field-empty> — a clone of server markup, never a string
 *                  (Rule 7). Nothing to ask the server: emptying a field is always allowed, the
 *                  host's save judges the form as a whole.
 *
 * The ADDRESS: the field root's id is the picker's `target`, so it must be unique on the page. A
 * field cloned from a <template> (a list item, P2) carries its template's id. At the click on
 * «Auswählen …» — in the CAPTURE phase, before core.js's own click handler reads the link —
 * a field whose id is missing or not unique gets a fresh one, and the link's `target` follows.
 * So a host that clones needs to do nothing; `_Z77.scriptInit['dms-media-field'](scope)` does the
 * same at once for every field in `scope` (the load-script convention, fetch.md), for a host that
 * wants the ids settled right after inserting.
 *
 * Delegated on `document`: a field that arrives later (a popup, a window, a clone) works without
 * a bind. Load it once per page: `documents/media-field` from `Z77\Module\Dms` — `addJs` on a
 * page, a `load-script` command in a fetched answer.
 */
(function () {
    'use strict';

    window._Z77 = window._Z77 || {};
    _Z77.scriptInit = _Z77.scriptInit || {};

    if (_Z77.dmsMediaField === true) return;   // loaded twice (a page AND a load-script): one listener

    function fieldOf(el) {
        return el && el.closest ? el.closest('[data-media-field]') : null;
    }

    function freshId() {
        return 'dms-mf-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    /* The field's id unique on the page — else a fresh one, and every place that names it follows. */
    function ensureUnique(field) {
        var id = field.id;
        if (id && document.querySelectorAll('#' + CSS.escape(id)).length <= 1) return id;

        var next = freshId();
        field.id = next;
        var label = field.querySelector('.be-form__label[id]');
        if (label) {
            label.id = next + '-label';
            var group = field.querySelector('[aria-labelledby]');
            if (group) group.setAttribute('aria-labelledby', label.id);
        }
        var pick = field.querySelector('[data-media-field-pick]');
        ['data-window-open', 'href'].forEach(function (name) {
            var value = pick ? pick.getAttribute(name) : null;
            if (!value) return;
            var url = new URL(value, window.location.href);
            url.searchParams.set('target', next);
            pick.setAttribute(name, url.pathname + url.search);
        });
        return next;
    }

    /* «Entfernen»: the value goes, the empty frame comes back. */
    function clear(field) {
        var input = field.querySelector('[data-media-field-input]');
        var thumb = field.querySelector('[data-media-field-thumb]');
        var empty = field.querySelector('template[data-media-field-empty]');
        var blank = empty && empty.content.firstElementChild;
        if (thumb && blank) thumb.parentNode.replaceChild(blank.cloneNode(true), thumb);
        if (input && input.value !== '') {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var pick = field.querySelector('[data-media-field-pick]');
        if (pick && typeof pick.focus === 'function') pick.focus();
    }

    document.addEventListener('click', function (e) {
        if (e.button !== 0 || !e.target.closest) return;
        var pick = e.target.closest('[data-media-field-pick]');
        if (pick) {
            var field = fieldOf(pick);
            if (field) ensureUnique(field);
            return;   // core.js opens the window
        }
        var clearBtn = e.target.closest('[data-media-field-clear]');
        if (clearBtn) {
            var owner = fieldOf(clearBtn);
            if (owner) { e.preventDefault(); clear(owner); }
        }
    }, true);

    _Z77.scriptInit['dms-media-field'] = function (scope) {
        (scope || document).querySelectorAll('[data-media-field]').forEach(ensureUnique);
    };

    _Z77.dmsMediaField = true;
})();
