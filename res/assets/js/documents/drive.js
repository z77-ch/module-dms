/**
 * DMS Drive — in-place pane updates (R6c).
 *
 * The Drive is server-rendered: every folder / crumb / file link is a real
 * `<a href>` that, without JS, triggers a full-reload navigation (CSS/server-first,
 * conventions.md#javascript). This script progressively enhances those links: it
 * intercepts a plain left-click and instead GETs the link's `data-pane` endpoint
 * (built server-side — the client never constructs URLs), which returns
 * `replace-html` commands that swap the four panes in place. Modifier / middle
 * clicks fall through to the native `href` so "open in new tab" still works.
 *
 * One delegated listener on `document` survives the pane swaps (the replaced
 * `<a>` nodes are descendants, never the listener target).
 *
 * Scope marker: the Drive's interactive surface spans two DOM subtrees — the `.dms-drive`
 * fragment (tree/list/preview panes + document actions) AND the backend shell header slots hc1
 * (upload) + hc2 (breadcrumb path + folder/trash actions). Both carry `data-drive-scope`, so the
 * guards below accept a click anywhere in the Drive without leaking onto unrelated pages.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        // Let the browser handle new-tab / new-window / non-primary clicks.
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        if (!window._Z77 || !_Z77.core || !_Z77.core.fetch) {
            return; // core.js absent → leave the href / native behaviour as the fallback
        }

        // Upload button: opens the upload modal for the folder currently open. The button
        // lives in the shell header band (hc1), so its target folder comes from the refreshed
        // breadcrumb pane's server-built `data-add-url` (stays current across pane swaps).
        var upload = e.target.closest('[data-drive-upload]');
        if (upload && upload.closest('[data-drive-scope]')) {
            var bc  = document.querySelector('.dms-drive__breadcrumb');
            var url = bc && bc.getAttribute('data-add-url');
            if (url) {
                e.preventDefault();
                _Z77.core.fetch.get(url);
            }
            return;
        }

        // New-folder button: opens the folder-create modal for the folder currently open
        // (or the root). Same breadcrumb-derived, server-built URL mechanism as the upload
        // button, so it stays current across pane swaps.
        var folderAdd = e.target.closest('[data-drive-folder-add]');
        if (folderAdd && folderAdd.closest('[data-drive-scope]')) {
            var bcf = document.querySelector('.dms-drive__breadcrumb');
            var furl = bcf && bcf.getAttribute('data-folder-add-url');
            if (furl) {
                e.preventDefault();
                _Z77.core.fetch.get(furl);
            }
            return;
        }

        // Trash button: opens the trash panel carrying the CURRENT selection (so a
        // restore can refresh the panes behind the modal in place). Same
        // breadcrumb-derived, server-built URL mechanism as the upload button.
        var trash = e.target.closest('[data-drive-trash]');
        if (trash && trash.closest('[data-drive-scope]')) {
            var bct  = document.querySelector('.dms-drive__breadcrumb');
            var turl = bct && bct.getAttribute('data-trash-url');
            if (turl) {
                e.preventDefault();
                _Z77.core.fetch.get(turl);
            }
            return;
        }

        // Document action (rename/move/delete): opens the modal at the server-built URL.
        // Delegated because these buttons live in the preview pane, which is replaced on
        // every pane refresh (so per-element wiring would be lost). The modal's own form
        // handles submit; on success the action returns pane-refresh commands.
        var modal = e.target.closest('[data-modal]');
        if (modal && modal.closest('[data-drive-scope]')) {
            e.preventDefault();
            _Z77.core.fetch.get(modal.getAttribute('data-modal'));
            return;
        }

        // Folder / crumb / file link: in-place pane update instead of a full reload. Crumb links
        // live in the hc2 header slot; tree/list links in the fragment — both are `data-drive-scope`.
        var link = e.target.closest('a[data-pane]');
        if (!link || !link.closest('[data-drive-scope]')) {
            return;
        }

        e.preventDefault();
        _Z77.core.fetch.get(link.getAttribute('data-pane'));
    });

    /* ── bulk selection (v1: documents — delete / move) ─────────────────────
     *
     * The selection surface is server-rendered checkboxes; the action bar's
     * APPEARANCE is pure CSS (`:has()`, see _filelist.scss). This block adds only
     * what CSS cannot: shift-click range selection, the live counter, all/none,
     * and opening the bulk modal — by appending the checked ids to the SERVER-BUILT
     * modal base URL on the list container (the client never constructs URL
     * structure, only the selection parameter — same level as a form field).
     *
     * Delegated on `document` like the pane handler above: the list pane is
     * replaced on every refresh, which also naturally clears the selection.
     */
    var lastChecked = null; // shift-range anchor; stale after a pane swap (indexOf miss → no range)

    function bulkBoxes(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[data-bulk-check]'));
    }

    function bulkIds(list) {
        return bulkBoxes(list).filter(function (cb) { return cb.checked; })
            .map(function (cb) { return cb.value; });
    }

    function bulkCount(list) {
        var el = list.querySelector('[data-bulk-count]');
        if (el) { el.textContent = bulkIds(list).length + ' ausgewählt'; }
    }

    document.addEventListener('click', function (e) {
        // Row checkbox: shift-range + counter. (Keyboard activation fires click too.)
        var cb = e.target.closest('[data-bulk-check]');
        if (cb && cb.closest('[data-drive-scope]')) {
            var list  = cb.closest('.dms-filelist');
            var boxes = bulkBoxes(list);
            var a     = boxes.indexOf(lastChecked);
            if (e.shiftKey && a !== -1) {
                var b = boxes.indexOf(cb);
                boxes.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (x) {
                    x.checked = cb.checked;
                });
            }
            lastChecked = cb;
            bulkCount(list);
            return;
        }

        // All / none.
        var toggle = e.target.closest('[data-bulk-all], [data-bulk-none]');
        if (toggle && toggle.closest('[data-drive-scope]')) {
            var tList   = toggle.closest('.dms-filelist');
            var checked = toggle.hasAttribute('data-bulk-all');
            bulkBoxes(tList).forEach(function (x) { x.checked = checked; });
            bulkCount(tList);
            return;
        }

        // Bulk action button: open the modal for the current selection.
        var btn = e.target.closest('[data-bulk-action]');
        if (btn && btn.closest('[data-drive-scope]') && window._Z77 && _Z77.core && _Z77.core.fetch) {
            var bList = btn.closest('.dms-filelist');
            var ids   = bulkIds(bList);
            var url   = bList.getAttribute(btn.getAttribute('data-bulk-action') === 'move'
                ? 'data-bulk-move-url' : 'data-bulk-delete-url');
            if (ids.length && url) {
                e.preventDefault();
                _Z77.core.fetch.get(url + '&ids=' + ids.join(','));
            }
        }
    });
}());
