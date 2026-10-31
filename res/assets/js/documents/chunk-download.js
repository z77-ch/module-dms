/* documents/chunk-download.js — the browser side of the DMS chunked download
 * (DMS-CHUNK-DL-001, z77-rudaz.ch 2026-10-31). The host caps a response body (cyon: about
 * 500 MB → 413), so a file of several GB cannot come down in one answer. This module asks
 * for it in explicit byte ranges (`Range: bytes=a-b`; the Drive passes
 * `dmsConfig['downloadChunkBytes']`, 32 MB) and hands the pieces to the disk without
 * holding the file in RAM where the browser allows it.
 *
 * Rule 7: the script only MOVES bytes the server already decided to hand out — every
 * piece is a normal GET that passes the same gate as the plain download link (session,
 * `read` on the document). It builds no markup from data, inserts no HTML, keeps nothing
 * in storage, sends no token of its own and talks to the own origin only (a foreign URL
 * is refused). No dependency.
 *
 * API (standalone — a project page wires it into its own template):
 *
 *   Z77ChunkDownload.download(url, {
 *       filename,     // optional; else from the first answer's Content-Disposition
 *       chunkBytes,   // optional piece size, default 8 MB (the Drive passes downloadChunkBytes)
 *       onProgress,   // optional fn(loadedBytes, totalBytes)
 *       signal        // optional AbortSignal — stops every request (and the browser download)
 *   }) → Promise of {size, filename, via: 'worker'|'picker'|'blob'}
 *   A refusal or a broken connection rejects with an Error whose message is for the user;
 *   an abort rejects with an Error named 'AbortError'.
 *
 * `url` is any same-origin URL that answers `Range:` with 206 + Content-Range (every
 * `FileResponse` does): the Drive's `…/document/download?id=`, a project's own action.
 *
 * Pieces: the first request asks `bytes=0-(chunk-1)`; its Content-Range names the total
 * size (no extra HEAD), its ETag the version. Then strictly sequential `bytes=a-b` with an
 * EXPLICIT end, never open-ended. Every answer must be 206, its Content-Range must start
 * where asked, end no later than asked and name the first total; the body must be exactly
 * that long; the ETag must be the first one — else the file changed during the download
 * and it stops. A shorter piece than asked is accepted (a server may cap a range — the
 * Content-Range says which bytes came) and the next one starts behind it. Each piece is
 * tried up to 3 times on a network error, 5xx, 429 or a short body, with 1 s / 2 s
 * backoff.
 *
 * Where the bytes go — the first that works:
 *   (a) service worker (Firefox, Chrome, Edge, Safari): `chunk-download-sw.js` beside this
 *       file streams the pieces into a download of the browser's own (Content-Length = the
 *       total, so the browser shows its progress too); nothing is held in RAM beyond one
 *       or two pieces. Needs a secure context (HTTPS or localhost) and a normal window —
 *       Firefox has no service worker in a private window.
 *   (b) `showSaveFilePicker` (Chromium only) when (a) is not available: the user picks the
 *       target, the pieces are written straight into it. Needs `filename` for a sensible
 *       suggestion.
 *   (c) last resort: the pieces are collected into one Blob and saved through an
 *       `<a download>` — this costs RAM about the file size (Firefox private window).
 */
(function (root) {
    'use strict';

    var CHUNK         = 8 * 1024 * 1024;
    var RETRIES       = 3;
    var SW_PREFIX     = '__z77-download/';
    var SW_START_MS   = 15000;   // the worker must pick the download up within this
    var SW_ACTIVE_MS  = 10000;   // registration + activation
    var SW_PING_MS    = 10000;   // keep the worker alive while it streams
    var FRAME_LINGER  = 30000;   // the hidden frame stays a while after the end
    var CHANGED       = 'Die Datei wurde während des Downloads geändert — bitte neu starten.';

    function noop() {}

    function error(message, final) {
        var err = new Error(message);
        err.final = !!final;
        return err;
    }

    function abortError(message) {
        var err = new Error(message || 'abgebrochen');
        err.name = 'AbortError';
        err.final = true;
        return err;
    }

    function wait(ms, signal) {
        return new Promise(function (resolve, reject) {
            if (signal && signal.aborted) { reject(abortError()); return; }
            setTimeout(function () {
                if (signal && signal.aborted) reject(abortError()); else resolve();
            }, ms);
        });
    }

    /** Absolute URL when `url` has the origin of `base`, else ''. */
    function sameOrigin(url, base) {
        try {
            var u = new URL(String(url), base);
            return u.origin === new URL(base).origin ? u.href : '';
        } catch (e) {
            return '';
        }
    }

    /** `bytes a-b/total` → {start, end, total}; `bytes *\/total` → {start: -1, end: -1, total}. */
    function parseContentRange(value) {
        var m = /^\s*bytes\s+(?:(\d+)-(\d+)|\*)\/(\d+)\s*$/i.exec(value || '');
        if (!m) return null;
        return {
            start: m[1] === undefined ? -1 : parseInt(m[1], 10),
            end:   m[2] === undefined ? -1 : parseInt(m[2], 10),
            total: parseInt(m[3], 10)
        };
    }

    /** A header value is bytes read as Latin-1; a UTF-8 name comes back readable. */
    function latin1ToUtf8(s) {
        if (!/[\u0080-\u00ff]/.test(s) || /[^\u0000-\u00ff]/.test(s) || typeof TextDecoder !== 'function') return s;
        var bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) bytes[i] = s.charCodeAt(i);
        try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch (e) { return s; }
    }

    function cleanName(name) {
        return String(name || '').replace(/[\u0000-\u001f\u007f]/g, '').replace(/[\\/]/g, '_').trim();
    }

    /** The filename of a Content-Disposition value (`filename*` first), or ''. */
    function filenameFrom(value) {
        if (!value) return '';
        var star = /filename\*\s*=\s*[^']*'[^']*'([^;]+)/i.exec(value);
        if (star) {
            try { return cleanName(decodeURIComponent(star[1].trim())); } catch (e) { /* fall back to filename= */ }
        }
        var plain = /filename\s*=\s*(?:"((?:[^"\\]|\\.)*)"|([^;]+))/i.exec(value);
        if (!plain) return '';
        var name = plain[1] !== undefined ? plain[1].replace(/\\(.)/g, '$1') : plain[2].trim();
        return cleanName(latin1ToUtf8(name));
    }

    /** Content-Disposition for a download: ASCII fallback + RFC 5987 UTF-8 name. */
    function contentDisposition(name) {
        var safe  = cleanName(name) || 'download';
        var ascii = safe.replace(/[^\x20-\x7e]/g, '_').replace(/["\\]/g, '_');
        var ext   = encodeURIComponent(safe).replace(/['()*]/g, function (c) {
            return '%' + c.charCodeAt(0).toString(16).toUpperCase();
        });
        return 'attachment; filename="' + ascii + '"; filename*=UTF-8\'\'' + ext;
    }

    /** Why a piece was refused, for the user; transient answers are retried. */
    function statusError(r) {
        if (r.redirected || r.status === 401 || r.status === 403) {
            return error('Kein Zugriff oder Sitzung abgelaufen — bitte neu anmelden.', true);
        }
        if (r.status === 429 || r.status >= 500) return error('Server-Fehler (' + r.status + ')', false);
        if (r.status === 200) return error('Der Server liefert diese Datei nicht in Teilstücken.', true);
        if (r.status === 404) return error('Datei nicht gefunden.', true);
        if (r.status === 413) return error('Teilstück zu gross für den Server.', true);
        return error('Server-Fehler (' + r.status + ')', true);
    }

    /**
     * Sequential range reader — the one implementation the page AND the service worker use.
     * `open()` fetches the first piece and resolves {size, etag, filename, type}; `next()`
     * resolves the next piece (Uint8Array) or null at the end; `loaded()` counts the bytes
     * handed out. opts: {chunkBytes, signal, fetch, retries, backoff(attempt) → ms}.
     */
    function reader(url, opts) {
        opts = opts || {};
        var fetchFn = opts.fetch || function (u, init) { return root.fetch(u, init); };
        var chunk   = opts.chunkBytes > 0 ? Math.floor(opts.chunkBytes) : CHUNK;
        var signal  = opts.signal || null;
        var retries = opts.retries || RETRIES;
        var backoff = opts.backoff || function (attempt) { return 1000 * Math.pow(2, attempt - 1); };
        var st      = { size: -1, etag: null, pos: 0, loaded: 0, first: null };

        function received(r, start, end) {
            var opening = st.size < 0;
            var range   = parseContentRange(r.headers.get('Content-Range'));

            // An empty file: nothing is satisfiable, the 416 names the total 0.
            if (opening && r.status === 416 && range && range.total === 0) {
                return { start: 0, end: -1, total: 0, etag: r.headers.get('ETag'), bytes: new Uint8Array(0), headers: r.headers };
            }
            if (r.status !== 206) throw statusError(r);
            if (!range || range.start !== start || range.end < start || range.end > end || range.end >= range.total) {
                throw error('Der Server hat ein falsches Teilstück geliefert.', true);
            }
            var etag = r.headers.get('ETag');
            if (!opening && (range.total !== st.size || etag !== st.etag)) throw error(CHANGED, true);

            return r.arrayBuffer().then(function (buf) {
                if (buf.byteLength !== range.end - range.start + 1) throw error('Teilstück unvollständig empfangen.', false);
                return { start: range.start, end: range.end, total: range.total, etag: etag, bytes: new Uint8Array(buf), headers: r.headers };
            }, function () {
                if (signal && signal.aborted) throw abortError();
                throw error('Verbindung unterbrochen.', false);
            });
        }

        function once(start, end) {
            var init = { headers: { Range: 'bytes=' + start + '-' + end }, credentials: 'same-origin', cache: 'no-store' };
            if (signal) init.signal = signal;
            return fetchFn(url, init).then(function (r) {
                return received(r, start, end);
            }, function () {
                if (signal && signal.aborted) throw abortError();
                throw error('Verbindung unterbrochen.', false);
            });
        }

        function piece(start, end) {
            var attempt = 0;
            function go() {
                attempt++;
                return once(start, end).catch(function (err) {
                    if (err.final || attempt >= retries) throw err;
                    return wait(backoff(attempt), signal).then(go);
                });
            }
            return go();
        }

        return {
            open: function () {
                return piece(0, chunk - 1).then(function (p) {
                    st.size  = p.total;
                    st.etag  = p.etag;
                    st.first = p.bytes;
                    st.pos   = p.end + 1;
                    var type = (p.headers.get('Content-Type') || '').split(';')[0].trim();
                    return {
                        size:     p.total,
                        etag:     p.etag,
                        filename: filenameFrom(p.headers.get('Content-Disposition')),
                        type:     type || 'application/octet-stream'
                    };
                });
            },
            next: function () {
                if (st.size < 0) return Promise.reject(error('reader: open() first', true));
                if (st.first !== null) {
                    var first = st.first;
                    st.first = null;
                    if (first.length > 0) {
                        st.loaded += first.length;
                        return Promise.resolve(first);
                    }
                }
                if (st.pos >= st.size) return Promise.resolve(null);
                return piece(st.pos, Math.min(st.pos + chunk, st.size) - 1).then(function (p) {
                    st.pos = p.end + 1;
                    st.loaded += p.bytes.length;
                    return p.bytes;
                });
            },
            loaded: function () { return st.loaded; }
        };
    }

    /**
     * The service worker's answer to its synthetic download URL — here, not in the worker,
     * so it is tested with the reader. job: {url, filename, chunkBytes}; env: {fetch,
     * post(message), backoff}. Returns {response: Promise<Response>, abort(), done:
     * Promise}. Messages to the page: start · progress {loaded, total} · done {total,
     * filename} · error {message} · cancelled (the user stopped the browser's download).
     */
    function respond(job, env) {
        env = env || {};
        var post       = env.post || noop;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var stream     = null;
        var finished   = false;
        var settle     = noop;
        var done       = new Promise(function (resolve) { settle = resolve; });
        var rd = reader(job.url, {
            chunkBytes: job.chunkBytes,
            signal:     controller ? controller.signal : null,
            fetch:      env.fetch,
            backoff:    env.backoff
        });

        function end(message) {
            if (finished) return false;
            finished = true;
            if (message) post(message);
            settle();
            return true;
        }

        function abort() {
            if (!end(null)) return;
            if (controller) controller.abort();
            if (stream) { try { stream.error(abortError()); } catch (e) { /* already closed */ } }
        }

        post({ type: 'start' });

        var response = rd.open().then(function (meta) {
            var name = cleanName(job.filename) || meta.filename || 'download';
            var body = new ReadableStream({
                start: function (c) { stream = c; },
                pull: function (c) {
                    return rd.next().then(function (bytes) {
                        if (finished) return;
                        if (bytes === null) {
                            c.close();
                            end({ type: 'done', total: meta.size, filename: name });
                            return;
                        }
                        c.enqueue(bytes);
                        post({ type: 'progress', loaded: rd.loaded(), total: meta.size });
                    }, function (err) {
                        if (!end(err.name === 'AbortError' ? { type: 'cancelled' } : { type: 'error', message: err.message })) return;
                        c.error(err);
                    });
                },
                cancel: function () {
                    // The user stopped the download in the browser.
                    if (end({ type: 'cancelled' }) && controller) controller.abort();
                }
            });
            return new Response(body, {
                status: 200,
                headers: {
                    'Content-Type':           'application/octet-stream',
                    'Content-Disposition':    contentDisposition(name),
                    'Content-Length':         String(meta.size),
                    'X-Content-Type-Options': 'nosniff',
                    'Cache-Control':          'no-store'
                }
            });
        }, function (err) {
            end(err.name === 'AbortError' ? { type: 'cancelled' } : { type: 'error', message: err.message });
            return new Response(err.message, { status: 502, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
        });

        return { response: response, abort: abort, done: done };
    }

    // ── page side ────────────────────────────────────────────────────────────────

    var IS_PAGE = typeof document !== 'undefined';

    // The worker lives beside this file, published with it (min ↔ min); its scope is
    // this directory — nothing else of the site is in reach of it.
    var SW = (function () {
        var src = IS_PAGE && document.currentScript ? document.currentScript.src : '';
        if (!src) return null;
        var dir = src.replace(/[?#].*$/, '').replace(/[^\/]*$/, '');
        return {
            url:   dir + 'chunk-download-sw' + (/\.min\.js(?:[?#]|$)/.test(src) ? '.min' : '') + '.js',
            scope: dir
        };
    }());

    var _worker = null;

    function workerUsable(opts) {
        return IS_PAGE && SW !== null && opts.serviceWorker !== false
            && !!root.navigator && !!root.navigator.serviceWorker
            && typeof MessageChannel === 'function' && root.isSecureContext !== false
            && !!sameOrigin(SW.url, root.location.href);
    }

    function pickerUsable() {
        return IS_PAGE && typeof root.showSaveFilePicker === 'function';
    }

    /** The registered, ACTIVE worker (a newer one installing is waited for). */
    function activeWorker() {
        if (_worker) return _worker;
        _worker = root.navigator.serviceWorker.register(SW.url, { scope: SW.scope, updateViaCache: 'none' }).then(function (reg) {
            return new Promise(function (resolve, reject) {
                var timer = setTimeout(function () { reject(new Error('activation timeout')); }, SW_ACTIVE_MS);
                var w = reg.installing || reg.waiting || reg.active;
                function check() {
                    if (w && w.state === 'activated') { clearTimeout(timer); resolve(w); return; }
                    if (!w || w.state === 'redundant') { clearTimeout(timer); reject(new Error('no worker')); }
                }
                if (w) w.addEventListener('statechange', check);
                check();
            });
        });
        _worker.catch(function () { _worker = null; });
        return _worker;
    }

    function token() {
        var bytes = new Uint8Array(16), hex = '';
        root.crypto.getRandomValues(bytes);
        for (var i = 0; i < bytes.length; i++) hex += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
        return hex;
    }

    /** (a) The worker streams the pieces into a download of the browser's own. */
    function viaWorker(job) {
        function fallback(message) {
            var err = new Error(message);
            err.fallback = true;
            return err;
        }
        return activeWorker().catch(function () {
            throw fallback('Service Worker nicht verfügbar.');
        }).then(function (worker) {
            return new Promise(function (resolve, reject) {
                var channel = new MessageChannel();
                var port    = channel.port1;
                var id      = token();
                var frame   = null;
                var settled = false;
                var ping    = setInterval(function () { worker.postMessage({ type: 'z77-download-ping' }); }, SW_PING_MS);
                var timer   = setTimeout(function () { giveUp('Service Worker hat nicht übernommen.'); }, SW_START_MS);

                function finish(ok, value) {
                    if (settled) return;
                    settled = true;
                    clearInterval(ping);
                    clearTimeout(timer);
                    if (job.signal) job.signal.removeEventListener('abort', onAbort);
                    setTimeout(function () {
                        port.close();
                        if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
                    }, FRAME_LINGER);
                    (ok ? resolve : reject)(value);
                }
                // Nothing reached the user yet: tell the worker to drop the job, try (b)/(c).
                function giveUp(message) {
                    port.postMessage({ type: 'abort' });
                    finish(false, fallback(message));
                }
                function onAbort() {
                    port.postMessage({ type: 'abort' });
                    finish(false, abortError());
                }

                port.onmessage = function (e) {
                    var m = e.data || {};
                    if (m.type === 'ready') {
                        // The worker holds the job: navigate a hidden frame to its URL — the
                        // worker answers it with the download.
                        frame = document.createElement('iframe');
                        frame.hidden = true;
                        frame.setAttribute('aria-hidden', 'true');
                        frame.src = SW.scope + SW_PREFIX + id;
                        document.body.appendChild(frame);
                    } else if (m.type === 'start') {
                        clearTimeout(timer);
                    } else if (m.type === 'progress') {
                        job.onProgress(m.loaded, m.total);
                    } else if (m.type === 'done') {
                        job.onProgress(m.total, m.total);
                        finish(true, { size: m.total, filename: m.filename, via: 'worker' });
                    } else if (m.type === 'cancelled') {
                        finish(false, abortError('Download abgebrochen.'));
                    } else if (m.type === 'error') {
                        finish(false, error(m.message || 'Download fehlgeschlagen.', true));
                    }
                };
                if (job.signal) job.signal.addEventListener('abort', onAbort);
                worker.postMessage({
                    type:       'z77-download',
                    token:      id,
                    url:        job.url,
                    filename:   job.filename,
                    chunkBytes: job.chunkBytes
                }, [channel.port2]);
            });
        });
    }

    /** Pull every piece of an opened reader into `sink(bytes)` (may return a promise). */
    function pump(rd, meta, job, sink) {
        function next() {
            return rd.next().then(function (bytes) {
                if (bytes === null) return meta;
                return Promise.resolve(sink(bytes)).then(function () {
                    job.onProgress(rd.loaded(), meta.size);
                    return next();
                });
            });
        }
        return next();
    }

    function newReader(job) {
        return reader(job.url, { chunkBytes: job.chunkBytes, signal: job.signal });
    }

    /** (b) The user picks the target file; the pieces are written straight into it. */
    function viaPicker(job) {
        return root.showSaveFilePicker({ suggestedName: job.filename || 'download' }).then(function (handle) {
            return handle.createWritable();
        }, function (err) {
            throw err && err.name === 'AbortError' ? abortError('Download abgebrochen.') : err;
        }).then(function (writable) {
            var rd = newReader(job);
            return rd.open().then(function (meta) {
                return pump(rd, meta, job, function (bytes) { return writable.write(bytes); });
            }).then(function (meta) {
                return writable.close().then(function () {
                    return { size: meta.size, filename: job.filename || meta.filename, via: 'picker' };
                });
            }, function (err) {
                return Promise.resolve(writable.abort()).catch(noop).then(function () { throw err; });
            });
        });
    }

    /** (c) All pieces into one Blob, saved through <a download> — RAM ≈ file size. */
    function viaBlob(job) {
        var rd = newReader(job), parts = [];
        return rd.open().then(function (meta) {
            return pump(rd, meta, job, function (bytes) { parts.push(bytes); });
        }).then(function (meta) {
            var name = job.filename || meta.filename || 'download';
            var href = URL.createObjectURL(new Blob(parts, { type: meta.type }));
            parts = [];
            var a = document.createElement('a');
            a.href = href;
            a.download = name;
            a.hidden = true;
            document.body.appendChild(a);
            a.click();
            a.parentNode.removeChild(a);
            // Revoked later: some browsers read the object URL after click() returned.
            setTimeout(function () { URL.revokeObjectURL(href); }, 60000);
            return { size: meta.size, filename: name, via: 'blob' };
        });
    }

    function download(url, opts) {
        opts = opts || {};
        var target = IS_PAGE ? sameOrigin(url, root.location.href) : '';
        if (!target) return Promise.reject(error('Nur Dateien dieser Website können so geladen werden.', true));
        if (opts.signal && opts.signal.aborted) return Promise.reject(abortError());

        var job = {
            url:        target,
            filename:   cleanName(opts.filename),
            chunkBytes: opts.chunkBytes > 0 ? Math.floor(opts.chunkBytes) : CHUNK,
            signal:     opts.signal || null,
            onProgress: typeof opts.onProgress === 'function' ? opts.onProgress : noop
        };

        if (workerUsable(opts)) {
            return viaWorker(job).catch(function (err) {
                if (!err.fallback) throw err;
                // The picker needs a fresh user gesture; after a failed worker attempt it
                // may be gone — then the Blob.
                var active = root.navigator.userActivation && root.navigator.userActivation.isActive;
                return pickerUsable() && active ? viaPicker(job) : viaBlob(job);
            });
        }
        return pickerUsable() ? viaPicker(job) : viaBlob(job);
    }

    root.Z77ChunkDownload = {
        download: download,
        // Shared with chunk-download-sw.js (importScripts) — one implementation of the
        // piece checks for the page and the worker.
        reader:             reader,
        respond:            respond,
        sameOrigin:         sameOrigin,
        filenameFrom:       filenameFrom,
        contentDisposition: contentDisposition
    };
}(typeof self !== 'undefined' ? self : this));
