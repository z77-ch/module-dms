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

        // Folder tools (edit / move / delete): static hc2 toolbar buttons (ADR-033 —
        // they used to sit inside the breadcrumb pane). Same mechanism again: the
        // server-built URL lives on the refreshed pane; an empty URL means no folder
        // is selected, and syncFolderTools() has hidden the button already.
        var folderTool = e.target.closest('[data-drive-folder-edit],[data-drive-folder-move],[data-drive-folder-delete]');
        if (folderTool && folderTool.closest('[data-drive-scope]')) {
            var bcx  = document.querySelector('.dms-drive__breadcrumb');
            var attr = folderTool.hasAttribute('data-drive-folder-edit') ? 'data-folder-edit-url'
                     : folderTool.hasAttribute('data-drive-folder-move') ? 'data-folder-move-url'
                     : 'data-folder-delete-url';
            var xurl = bcx && bcx.getAttribute(attr);
            if (xurl) {
                e.preventDefault();
                _Z77.core.fetch.get(xurl);
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

    /* ── folder-tool visibility ─────────────────────────────────────────────
     *
     * The three folder tools are STATIC shell buttons, but whether there is a
     * folder to act on changes with every pane swap. The truth sits on the
     * refreshed breadcrumb pane (empty data URL = nothing selected); this only
     * mirrors it onto the buttons' `hidden`. A MutationObserver instead of a
     * hook into the fetch pipeline: the pane is replaced by generic
     * `replace-html` commands that know nothing about the Drive.
     */
    function syncFolderTools() {
        var bc = document.querySelector('.dms-drive__breadcrumb');
        [['data-drive-folder-edit', 'data-folder-edit-url'],
         ['data-drive-folder-move', 'data-folder-move-url'],
         ['data-drive-folder-delete', 'data-folder-delete-url']].forEach(function (pair) {
            var button = document.querySelector('[' + pair[0] + ']');
            if (button) {
                button.hidden = !(bc && bc.getAttribute(pair[1]));
            }
        });
    }

    if (document.querySelector('[data-drive-folder-edit]')) {
        new MutationObserver(syncFolderTools)
            .observe(document.body, { childList: true, subtree: true });
        syncFolderTools();
    }

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

    /* ── manual order (drag & drop within the list pane) ─────────────────────
     *
     * Why JS (conventions.md#javascript): reordering by dragging has no CSS or
     * server-rendered equivalent — a form with up/down buttons would cost one
     * round-trip per step. Same mechanics as the backend-user list: the row is
     * server-rendered `draggable`, the drop posts {id, new_index, entity_csrf} to
     * the SERVER-BUILT `data-sort-url` (new_index = position among the OTHER rows),
     * and on success the row is relocated in the DOM — the server has already
     * renumbered, so the next pane render shows the same order. Delegated on
     * `document` because the list pane is replaced on every refresh. Rows can only
     * be dropped among their own siblings (one folder = one list).
     */
    var dragRow = null, dropRow = null, dropZone = null;

    function sortRows(list) {
        return Array.prototype.slice.call(list.querySelectorAll('.dms-file[data-doc-id]'));
    }

    function clearDrop() {
        document.querySelectorAll('.dms-file--drop-before, .dms-file--drop-after').forEach(function (r) {
            r.classList.remove('dms-file--drop-before', 'dms-file--drop-after');
        });
        dropRow = null; dropZone = null;
    }

    document.addEventListener('dragstart', function (e) {
        var row = e.target.closest && e.target.closest('.dms-file[data-doc-id]');
        if (!row || !row.closest('.dms-filelist[data-sort-url]')) return;
        dragRow = row;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.dataset.docId);
        row.classList.add('dms-file--dragging');
    });

    document.addEventListener('dragend', function () {
        clearDrop();
        if (dragRow) { dragRow.classList.remove('dms-file--dragging'); }
        dragRow = null;
    });

    document.addEventListener('dragover', function (e) {
        if (!dragRow) return;
        var row = e.target.closest && e.target.closest('.dms-file[data-doc-id]');
        if (!row || row === dragRow || row.parentNode !== dragRow.parentNode) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        var rect = row.getBoundingClientRect();
        var zone = (e.clientY - rect.top) < rect.height / 2 ? 'before' : 'after';
        if (dropRow !== row || dropZone !== zone) {
            clearDrop();
            dropRow = row; dropZone = zone;
            row.classList.add('dms-file--drop-' + zone);
        }
    });

    document.addEventListener('drop', function (e) {
        if (!dragRow || !dropRow || !dropZone) return;
        e.preventDefault();
        var list   = dragRow.closest('.dms-filelist[data-sort-url]');
        var moved  = dragRow, target = dropRow, zone = dropZone;
        var rest   = sortRows(list).filter(function (r) { return r !== moved; });
        var index  = rest.indexOf(target) + (zone === 'after' ? 1 : 0);
        clearDrop();
        if (!window._Z77 || !_Z77.core || !_Z77.core.fetch) return;
        _Z77.core.fetch.post(list.getAttribute('data-sort-url'), {
            id: parseInt(moved.dataset.docId, 10),
            new_index: index,
            entity_csrf: moved.dataset.sortToken
        }).then(function (env) {
            if (env && env.status === 'success') {
                target.parentNode.insertBefore(moved, zone === 'before' ? target : target.nextSibling);
            }
        });
    });
}());
