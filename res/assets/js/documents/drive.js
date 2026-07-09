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
}());
