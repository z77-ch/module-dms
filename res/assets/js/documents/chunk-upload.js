/* documents/chunk-upload.js — the browser side of the DMS chunked upload (F3, z77-rudaz.ch
 * 2026-10-31). A file of several GB cannot go up in one request: the host caps it (cyon:
 * 500 MB) and so does PHP. This module slices it and sends the pieces one after the other
 * to the endpoints of `ChunkedUploadTrait` (`<base>/chunk-begin|append|status|finish|abort`).
 *
 * Rule 7: the script only MOVES bytes. Every decision — may this identity write into the
 * folder, is the type allowed, is it a duplicate, may it overwrite — is the server's, at
 * begin / append / finish, and it re-checks every chunk (owner, gate, exact size). The
 * module builds no markup, inserts no HTML, keeps nothing in storage; the only token it
 * sends is the page's CSRF token from `meta[name=csrf-token]`, as `X-CSRF-Token` (the
 * fetch contract, FETCH-CSRF-001). No dependency.
 *
 * API (standalone — a project page wires it into its own template):
 *
 *   Z77ChunkUpload.upload(file, {
 *       base,          // URL of the controller that `use`s ChunkedUploadTrait, e.g. '/member/projekt/dokumente'
 *       folderId,      // target folder
 *       overwrite,     // true = replace a same-named document (the user confirmed)
 *       showOriginal,  // optional, Drive flag
 *       poster,        // optional Blob (video poster frame), sent with finish
 *       onProgress,    // optional fn(loadedBytes, totalBytes)
 *       signal         // optional AbortSignal — aborts and removes the upload on the server
 *   }) → Promise of the finish answer:
 *       {status:'success', data:{id, name}, commands?}
 *       {status:'duplicate', data:{message}}  identical bytes already there — nothing stored
 *       {status:'conflict',  data:{message}}  same name, other bytes — ask the user, then call
 *                                             upload() again with the SAME File and overwrite:true:
 *                                             the server kept the bytes, nothing is re-sent
 *   A refusal or a broken connection rejects with an Error whose message is for the user.
 *
 * The chunk size comes from the server (the begin answer), never from here. Every chunk
 * carries the SHA-256 of the bytes sliced from the original (WebCrypto, secure context:
 * HTTPS or localhost); the server hashes what arrived and refuses a mismatch with
 * `retry` — so the assembled file is the original, byte for byte. Chunks go
 * strictly in sequence; each one is tried up to 3 times (network error, 5xx, 429, hash
 * mismatch); when
 * that fails the module asks the server which chunks it holds (`chunk-status`) and resumes
 * from there — up to 3 such resumes per upload. A finish is never repeated: if its
 * connection breaks, the file may already be stored.
 *
 * Inside the upload component (`_Z77.upload`, UPLOAD-001) it registers as the transport
 * `dms-chunk`: a form with `data-upload-transport="dms-chunk"` hands every file bigger than
 * one chunk to it; smaller files keep the component's one-request POST.
 */
window.Z77ChunkUpload = (function () {
    var RETRIES = 3;
    var RESUMES = 3;

    var _pending = typeof WeakMap === 'function' ? new WeakMap() : null;

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    /** SHA-256 of a Blob as lower-case hex (WebCrypto; one chunk is small enough to hash whole). */
    function sha256(blob) {
        if (!window.crypto || !window.crypto.subtle) {
            var err = new Error('Sichere Verbindung (HTTPS) für den Upload grosser Dateien nötig.');
            err.final = true;
            return Promise.reject(err);
        }
        return blob.arrayBuffer().then(function (buf) {
            return window.crypto.subtle.digest('SHA-256', buf);
        }).then(function (digest) {
            var bytes = new Uint8Array(digest), hex = '';
            for (var i = 0; i < bytes.length; i++) hex += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16);
            return hex;
        });
    }

    function abortError() {
        var err = new Error('abgebrochen');
        err.name = 'AbortError';
        err.final = true;
        return err;
    }

    function transient(r) {
        // `retry`: the server got other bytes than the browser hashed — send the chunk again.
        return r.status === 0 || r.status === 429 || (r.status >= 500 && r.status !== 507)
            || !!(r.json && r.json.retry === true);
    }

    /** The global gates (CSRF, login) answer the house envelope: their reason is a flash. */
    function reason(json) {
        if (!json) return '';
        if (json.error) return json.error;
        return json.flashes && json.flashes[0] && json.flashes[0].text ? json.flashes[0].text : '';
    }

    function failure(r) {
        var err = new Error(reason(r.json) || (r.status === 0 ? 'Verbindung unterbrochen' : 'Server-Fehler (' + r.status + ')'));
        err.status = r.status;
        err.final = !transient(r);
        err.gone = r.status === 404;
        return err;
    }

    function wait(attempt, signal) {
        return new Promise(function (resolve, reject) {
            if (signal && signal.aborted) { reject(abortError()); return; }
            setTimeout(resolve, 1000 * Math.pow(2, attempt - 1));
        });
    }

    /** One request; resolves {status, json} (status 0 = no answer), rejects only on abort. */
    function request(method, url, data, signal, onProgress) {
        return new Promise(function (resolve, reject) {
            if (signal && signal.aborted) { reject(abortError()); return; }
            var xhr = new XMLHttpRequest();
            function onAbort() { xhr.abort(); }
            function done() { if (signal) signal.removeEventListener('abort', onAbort); }

            xhr.open(method, url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            var token = csrf();
            if (token) xhr.setRequestHeader('X-CSRF-Token', token);
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded); };
            }
            xhr.onload = function () {
                done();
                var json = null;
                try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
                resolve({ status: xhr.status, json: json });
            };
            xhr.onerror = function () { done(); resolve({ status: 0, json: null }); };
            xhr.onabort = function () { done(); reject(abortError()); };
            if (signal) signal.addEventListener('abort', onAbort);
            xhr.send(data || null);
        });
    }

    /** Retry a request while the answer is transient. */
    function retried(send, signal) {
        var attempt = 0;
        function once() {
            attempt++;
            return send().then(function (r) {
                if (r.status === 200 && r.json && r.json.ok) return r.json;
                if (transient(r) && attempt < RETRIES) return wait(attempt, signal).then(once);
                throw failure(r);
            });
        }
        return once();
    }

    function form(values) {
        var data = new FormData();
        Object.keys(values).forEach(function (key) {
            if (values[key] !== null && values[key] !== undefined) data.append(key, values[key]);
        });
        return data;
    }

    function upload(file, opts) {
        opts = opts || {};
        var base       = String(opts.base || '').replace(/\/+$/, '');
        var signal     = opts.signal || null;
        var onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : function () {};
        var kept       = _pending ? _pending.get(file) : null;

        if (!base) return Promise.reject(new Error('Upload-Ziel fehlt.'));

        // The user confirmed «überschreiben» after a conflict: the server still holds the
        // bytes of that upload — finish it again instead of sending everything once more.
        if (kept && opts.overwrite && kept.base === base) {
            return finish(kept.uploadId).catch(function (err) {
                if (!err.gone) throw err;
                _pending.delete(file);            // the kept upload expired meanwhile
                return fresh();
            });
        }
        return fresh();

        function fresh() {
            var ctx = { uploadId: '', chunkBytes: 0, chunks: 0, received: {} };

            return retried(function () {
                return request('POST', base + '/chunk-begin', form({
                    folder_id:     opts.folderId,
                    name:          file.name,
                    size:          String(file.size),
                    overwrite:     opts.overwrite ? '1' : null,
                    show_original: opts.showOriginal ? '1' : null
                }), signal);
            }, signal).then(function (answer) {
                ctx.uploadId   = answer.uploadId;
                ctx.chunkBytes = answer.chunkBytes;
                ctx.chunks     = Math.ceil(file.size / ctx.chunkBytes);
                return sendAll(ctx);
            }).then(function () {
                return finish(ctx.uploadId);
            }).catch(function (err) {
                // Nothing will resume this upload any more — free the server's disk. A
                // conflict is not an error: finish() kept it on purpose.
                if (ctx.uploadId && !err.gone) {
                    request('POST', base + '/chunk-abort', form({ upload_id: ctx.uploadId }), null).catch(function () {});
                }
                throw err;
            });
        }

        function confirmed(ctx) {
            var bytes = 0;
            Object.keys(ctx.received).forEach(function (i) {
                bytes += Math.min(ctx.chunkBytes, file.size - i * ctx.chunkBytes);
            });
            return bytes;
        }

        function sendAll(ctx) {
            var resumes = 0;
            function next() {
                var index = -1;
                for (var i = 0; i < ctx.chunks; i++) {
                    if (!ctx.received[i]) { index = i; break; }
                }
                if (index === -1) return Promise.resolve();

                return sendChunk(ctx, index).then(function () {
                    ctx.received[index] = true;
                    onProgress(confirmed(ctx), file.size);
                    return next();
                }, function (err) {
                    if (err.final || resumes >= RESUMES) throw err;
                    resumes++;
                    return status(ctx).then(next);
                });
            }
            return next();
        }

        function sendChunk(ctx, index) {
            var start = index * ctx.chunkBytes;
            var end   = Math.min(file.size, start + ctx.chunkBytes);
            var base0 = confirmed(ctx);
            var piece = file.slice(start, end);

            return sha256(piece).then(function (hash) {
                return retried(function () {
                    return request('POST', base + '/chunk-append', form({
                        upload_id: ctx.uploadId,
                        index:     String(index),
                        sha256:    hash,
                        chunk:     piece
                    }), signal, function (loaded) {
                        onProgress(base0 + Math.min(loaded, end - start), file.size);
                    });
                }, signal);
            });
        }

        /** Resume: the server says which chunks it holds. */
        function status(ctx) {
            return retried(function () {
                return request('GET', base + '/chunk-status?upload_id=' + encodeURIComponent(ctx.uploadId), null, signal);
            }, signal).then(function (answer) {
                ctx.received = {};
                (answer.received || []).forEach(function (i) { ctx.received[i] = true; });
                onProgress(confirmed(ctx), file.size);
            });
        }

        function finish(uploadId) {
            var data = form({ upload_id: uploadId, overwrite: opts.overwrite ? '1' : null });
            if (opts.poster instanceof Blob) data.append('poster', opts.poster, 'poster.jpg');

            return request('POST', base + '/chunk-finish', data, signal).then(function (r) {
                var answer = r.json || {};
                if (answer.status === 'success' || answer.status === 'duplicate') {
                    if (_pending) _pending.delete(file);
                    return answer;
                }
                if (answer.status === 'conflict') {
                    if (_pending) _pending.set(file, { base: base, uploadId: uploadId });
                    return answer;
                }
                var err = failure(r);
                if (r.status === 0) {
                    err.message = 'Verbindung beim Abschliessen unterbrochen — bitte prüfen, ob die Datei angekommen ist.';
                    err.gone = true;          // do not abort: the server may have stored it
                }
                throw err;
            });
        }
    }

    // The transport of the upload component (UPLOAD-001): the Drive's modal names it.
    if (window._Z77 && _Z77.upload && _Z77.upload.transport) {
        _Z77.upload.transport('dms-chunk', {
            accepts: function (file, config) {
                return !!config.base && config.chunkBytes > 0 && file.size > config.chunkBytes;
            },
            max: function (config) {
                return config.max || 0;
            },
            send: function (file, ctx) {
                return upload(file, {
                    base:         ctx.config.base,
                    folderId:     ctx.fields.folder_id,
                    overwrite:    ctx.overwrite,
                    showOriginal: !!ctx.fields.show_original,
                    poster:       ctx.extra.poster || null,
                    onProgress:   ctx.onProgress,
                    signal:       ctx.signal
                });
            }
        });
    }

    return { upload: upload };
})();
