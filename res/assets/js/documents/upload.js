/* documents/upload.js — per-file, sequential XHR uploader inside the popup modal.
 *
 * The Drive upload endpoint is per-file (one request per file, no redirect — see
 * DriveControllerTrait::uploadAction). This client drives the queue: it builds a row per
 * selected file, gates each file against the two size caps client-side, then uploads them
 * STRICTLY SEQUENTIALLY via XMLHttpRequest (needed for real upload progress — fetch() cannot
 * report upload progress). Each row shows a live progress bar, a status, and a cancel button.
 *
 * Two caps (P1.3), read off the form:
 *   data-max-bytes       transport cap — every file (PHP won't accept more over the wire);
 *   data-max-image-bytes memory cap    — image/* only (GD decodes the pixels). A video is
 *                                        moved server-side, so it is bounded by the transport
 *                                        cap alone.
 *
 * Per-file envelope handled: success {id,name} · duplicate {name} · conflict {name} ·
 * error {name,message}. A conflict PAUSES the queue on that row with an inline
 * "überschreiben? [Ja] [Nein]" prompt: Ja re-posts the same file with overwrite=1, Nein
 * marks the row skipped; either way the queue then advances.
 *
 * Registered as a lazy script init, run per popup-open with the popup body as scope
 * (see DriveControllerTrait::addAction → load-script command).
 */
_Z77.scriptInit = _Z77.scriptInit || {};
_Z77.scriptInit['documents-upload'] = function (scope) {
    var form = (scope || document).querySelector('[data-z77-upload-form]');
    if (!form || form._z77UploadBound) return;
    form._z77UploadBound = true;

    var input     = form.querySelector('input[type=file]');
    var list      = form.querySelector('[data-z77-upload-list]');
    var submitBtn = form.querySelector('[data-z77-upload-submit]');
    var folderSel = form.querySelector('[name="folder_id"]');
    var showOrig  = form.querySelector('[name="show_original"]');
    var meta      = document.querySelector('meta[name="csrf-token"]');
    var csrf      = meta ? meta.getAttribute('content') : '';

    var maxBytes      = parseInt(form.getAttribute('data-max-bytes'), 10) || 0;
    var maxImageBytes = parseInt(form.getAttribute('data-max-image-bytes'), 10) || 0;

    var items    = [];    // { file, row, fill, status, note, cancelBtn, xhr, state }
    var running  = false; // a queue pass is in flight (guards double-start)

    function mb(bytes) {
        return (bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0);
    }

    // The applicable cap for a file: images decode in RAM (memory cap), everything else is
    // bounded only by the transport cap (moved server-side, never read into memory).
    function capFor(file) {
        return (file.type && file.type.indexOf('image/') === 0) ? maxImageBytes : maxBytes;
    }

    // ── row rendering ────────────────────────────────────────────────────────────
    function setState(item, state, statusText) {
        item.state = state;
        item.row.className = 'dms-upload-row dms-upload-row--' + state;
        if (statusText !== undefined) item.note.textContent = statusText;
        // The row fill is only meaningful while uploading (grows to %) or on done (full);
        // any other terminal/pending state collapses it back so the row reads cleanly.
        if (item.progress && state !== 'uploading' && state !== 'done') {
            item.progress.style.width = '0%';
        }
        // Cancel is only meaningful while queued / uploading / awaiting a conflict answer.
        var cancellable = state === 'queued' || state === 'uploading' || state === 'conflict';
        item.cancelBtn.hidden = !cancellable;
    }

    function buildRow(file) {
        var row = document.createElement('li');
        row.className = 'dms-upload-row';

        // Full-row progress fill (semi-transparent accent) that grows left→right BEHIND the
        // content while uploading — the name/status read on top of it.
        var progress = document.createElement('div');
        progress.className = 'dms-upload-row__progress';

        var name = document.createElement('span');
        name.className = 'dms-upload-row__name';
        name.textContent = file.name;
        name.title = file.name;

        var note = document.createElement('span');
        note.className = 'dms-upload-row__status';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'dms-upload-row__cancel';
        cancel.setAttribute('aria-label', 'Abbrechen');
        cancel.textContent = '✕';

        row.appendChild(progress); // first → behind the content
        row.appendChild(name);
        row.appendChild(note);
        row.appendChild(cancel);

        return { row: row, progress: progress, note: note, cancelBtn: cancel };
    }

    // Build the queue from the current selection, applying the client-side size gate.
    function renderList() {
        list.innerHTML = '';
        items = [];
        var files = input.files;
        if (!files || files.length === 0) { list.hidden = true; return; }
        list.hidden = false;

        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var parts = buildRow(file);
            var item = {
                file: file, row: parts.row, progress: parts.progress,
                note: parts.note, cancelBtn: parts.cancelBtn, xhr: null, state: 'queued'
            };
            var cap = capFor(file);
            if (cap > 0 && file.size > cap) {
                setState(item, 'error', 'Datei zu gross (max. ' + mb(cap) + ' MB)');
            } else {
                setState(item, 'queued', 'Bereit');
            }
            parts.cancelBtn.addEventListener('click', (function (it) {
                return function () { cancelItem(it); };
            })(item));
            list.appendChild(parts.row);
            items.push(item);
        }
    }

    // ── queue ───────────────────────────────────────────────────────────────────
    function nextQueued() {
        for (var i = 0; i < items.length; i++) {
            if (items[i].state === 'queued') return items[i];
        }
        return null;
    }

    function advance() {
        var item = nextQueued();
        if (item) { uploadItem(item, false); return; }
        running = false;
        finishRun();
    }

    function cancelItem(item) {
        if (item.state === 'uploading') {
            if (item.xhr) {
                item.xhr.abort(); // in flight → onabort marks cancelled + advances
            } else {
                // Cancelled during async poster extraction (no request yet): the
                // maybePoster().then guard sees 'cancelled' and advances the queue.
                setState(item, 'cancelled', 'Abgebrochen');
            }
            return;
        }
        if (item.state === 'queued') {
            setState(item, 'cancelled', 'Abgebrochen');
            return; // never started; the running pass will skip it
        }
        if (item.state === 'conflict') {
            clearConflict(item);
            setState(item, 'cancelled', 'Abgebrochen');
            advance();
        }
    }

    function uploadItem(item, overwrite) {
        setState(item, 'uploading', overwrite ? 'Überschreibe…' : 'Lade hoch…');
        item.progress.style.width = '0%';

        // A video may carry a browser-extracted poster (P4); resolves to null otherwise. A
        // failure ANYWHERE in the prep step (poster extraction, FormData, xhr) MUST NOT strand
        // the queue — every path either sends or marks the row terminal and advances.
        maybePoster(item.file).then(function (poster) {
            if (item.state === 'cancelled') { advance(); return; } // cancelled during extraction
            sendItem(item, overwrite, poster);
        }).catch(function () {
            if (item.state === 'cancelled') { advance(); return; }
            sendItem(item, overwrite, null); // poster failed → upload the video without one
        });
    }

    function sendItem(item, overwrite, poster) {
        try {
            var data = new FormData();
            data.append('files[]', item.file, item.file.name);
            if (folderSel) data.append('folder_id', folderSel.value);
            if (showOrig && showOrig.checked) data.append('show_original', '1');
            if (overwrite) data.append('overwrite', '1');
            if (poster) data.append('poster', poster, 'poster.jpg');

            var xhr = new XMLHttpRequest();
            item.xhr = xhr;
            xhr.open('POST', form.getAttribute('action'));
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    item.progress.style.width = pct + '%';
                    item.note.textContent = pct + ' %';
                }
            };
            xhr.onload = function () {
                item.xhr = null;
                var env = null;
                try { env = JSON.parse(xhr.responseText); } catch (err) { env = null; }
                handleEnvelope(item, env);
            };
            xhr.onerror = function () {
                item.xhr = null;
                setState(item, 'error', 'Verbindungsfehler');
                advance();
            };
            xhr.onabort = function () {
                item.xhr = null;
                setState(item, 'cancelled', 'Abgebrochen');
                advance();
            };
            xhr.send(data);
        } catch (err) {
            item.xhr = null;
            setState(item, 'error', 'Upload fehlgeschlagen');
            advance(); // never leave the queue stuck on a throw
        }
    }

    function handleEnvelope(item, env) {
        if (!env || !env.status) {
            setState(item, 'error', 'Unerwartete Antwort');
            advance();
            return;
        }
        switch (env.status) {
            case 'success':
                item.progress.style.width = '100%';
                setState(item, 'done', 'Hochgeladen');
                advance();
                break;
            case 'duplicate':
                setState(item, 'skipped', 'Bereits vorhanden — übersprungen');
                advance();
                break;
            case 'conflict':
                askOverwrite(item); // PAUSE the queue on this row until answered
                break;
            default: // 'error'
                var msg = (env.data && env.data.message) ? env.data.message : 'Fehler';
                setState(item, 'error', msg);
                advance();
        }
    }

    // ── inline conflict prompt ────────────────────────────────────────────────────
    function askOverwrite(item) {
        setState(item, 'conflict', 'Existiert bereits — überschreiben?');
        var prompt = document.createElement('span');
        prompt.className = 'dms-upload-row__prompt';

        var yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'be-btn be-btn--sm';
        yes.textContent = 'Ja';
        var no = document.createElement('button');
        no.type = 'button';
        no.className = 'be-btn be-btn--sm be-btn--ghost';
        no.textContent = 'Nein';

        yes.addEventListener('click', function () {
            clearConflict(item);
            uploadItem(item, true); // re-post the SAME file with overwrite consent
        });
        no.addEventListener('click', function () {
            clearConflict(item);
            setState(item, 'skipped', 'Übersprungen');
            advance();
        });

        prompt.appendChild(yes);
        prompt.appendChild(no);
        item.row.appendChild(prompt);
        item.prompt = prompt;
    }

    function clearConflict(item) {
        if (item.prompt) { item.prompt.remove(); item.prompt = null; }
    }

    // ── completion ────────────────────────────────────────────────────────────────
    function finishRun() {
        if (submitBtn) submitBtn.disabled = false;
        var anySuccess = items.some(function (it) { return it.state === 'done'; });
        if (anySuccess) {
            _Z77.core.popup.close();
            refreshDrive();
        }
        // else: keep the modal open so the per-row errors stay visible.
    }

    // Refresh the Drive panes in place: GET the current folder's pane endpoint (the last
    // active breadcrumb crumb carries it, server-built). Full reload as the fallback.
    function refreshDrive() {
        var crumbs = document.querySelectorAll('.dms-drive__breadcrumb .dms-drive__crumb--active');
        var last   = crumbs[crumbs.length - 1];
        var url    = last && last.getAttribute('data-pane');
        if (url && window._Z77 && _Z77.core && _Z77.core.fetch) {
            _Z77.core.fetch.get(url);
        } else {
            window.location.reload();
        }
    }

    // ── video poster (P4, ported from wdv-5.1.0 Media.js) ─────────────────────────
    // For a video, extract ONE frame to an image/jpeg Blob the server runs through the GD
    // pipeline (→ s/m/l/xl variants). For anything else, or on any failure/timeout, resolve
    // null — a missing poster MUST NOT block the video upload.
    function maybePoster(file) {
        if (!file.type || file.type.indexOf('video/') !== 0) {
            return Promise.resolve(null);
        }
        return extractVideoPoster(file).catch(function () { return null; });
    }

    // Resolve to an image/jpeg Blob or null. Frame time 5 s (clip > 15 s) else 1 s; wait for
    // a decodable frame (readyState >= 2) before drawing; longest edge ~1250 px, quality 0.75.
    function extractVideoPoster(file) {
        return new Promise(function (resolve) {
            var url     = URL.createObjectURL(file);
            var video   = document.createElement('video');
            var settled = false;
            // Skip (don't hang) if the frame never decodes — also releases the video decoder
            // promptly so a later file in the queue isn't starved of a decoder ("the last one").
            var timer   = setTimeout(finishNull, 10000);

            function cleanup() {
                clearTimeout(timer);
                try { video.pause(); } catch (e) {}
                try { video.removeAttribute('src'); video.load(); } catch (e) {} // free the decoder
                URL.revokeObjectURL(url);
            }
            function finishNull() { if (settled) return; settled = true; cleanup(); resolve(null); }
            function finishBlob(blob) { if (settled) return; settled = true; cleanup(); resolve(blob || null); }

            video.muted = true;
            video.playsInline = true;
            video.preload = 'auto';
            video.addEventListener('error', finishNull);
            video.addEventListener('loadedmetadata', function () {
                var t = (video.duration && video.duration > 15) ? 5 : 1;
                try { video.currentTime = Math.min(t, video.duration || t); } catch (e) { finishNull(); }
            });
            video.addEventListener('seeked', function () { grab(0); });

            function grab(attempt) {
                if (settled) return;
                // Poll until a frame is actually decodable (the old code's resize() retry loop).
                if (video.readyState < 2 && attempt < 20) {
                    setTimeout(function () { grab(attempt + 1); }, 100);
                    return;
                }
                var w = video.videoWidth, h = video.videoHeight;
                if (!w || !h) { finishNull(); return; }
                var scale = Math.min(1, 1250 / Math.max(w, h));
                var cw = Math.round(w * scale), ch = Math.round(h * scale);
                var canvas = document.createElement('canvas');
                canvas.width = cw; canvas.height = ch;
                try {
                    canvas.getContext('2d').drawImage(video, 0, 0, cw, ch);
                } catch (e) { finishNull(); return; }
                canvas.toBlob(finishBlob, 'image/jpeg', 0.75);
            }

            video.src = url;
        });
    }

    // ── wiring ────────────────────────────────────────────────────────────────────
    input.addEventListener('change', renderList);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (running) return;
        if (!input.files || input.files.length === 0) {
            _Z77.core.message.show('error', 'Bitte mindestens eine Datei wählen.');
            return;
        }
        if (items.length === 0) renderList();
        if (!nextQueued()) {
            // Nothing uploadable (all gated out) — surface it, keep the modal open.
            _Z77.core.message.show('error', 'Keine gültige Datei zum Hochladen.');
            return;
        }
        running = true;
        if (submitBtn) submitBtn.disabled = true;
        advance();
    });
};
