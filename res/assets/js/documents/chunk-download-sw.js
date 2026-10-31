/* documents/chunk-download-sw.js — service worker of the DMS chunked download
 * (DMS-CHUNK-DL-001, z77-rudaz.ch 2026-10-31). Path (a) of `chunk-download.js`: it turns
 * a sequence of Range pieces into ONE download of the browser's own, streamed — a file of
 * several GB never sits in RAM (Firefox has no showSaveFilePicker).
 *
 * Registered by `chunk-download.js` with the directory of both files as its scope (the
 * published assets directory, e.g. `/assets/dms/js/documents/`) — no page of the site
 * lies in it, so it controls nothing but its own synthetic URL.
 *
 * Flow: the page posts {type:'z77-download', token, url, filename, chunkBytes} with a
 * MessageChannel port; the worker keeps the job and answers `ready`; the page navigates a
 * hidden frame to `<scope>__z77-download/<token>`; the worker answers that navigation with
 * a streamed `attachment` whose pieces it fetches itself (`Z77ChunkDownload.respond`, the
 * checks of the shared reader) and reports start / progress / done / error / cancelled
 * through the port.
 *
 * Limits (Rule 7):
 *   - it intercepts ONLY `<scope>__z77-download/…`; every other request is left alone
 *     (no respondWith — the network answers as without a worker);
 *   - a token is 32 hex characters of crypto randomness, single-use, and expires 60 s
 *     after it was posted unless used; an unknown one answers 404;
 *   - it fetches only the same-origin URL posted for that token, with the page's own
 *     cookies (`credentials: 'same-origin'`) — the server gates every piece like the plain
 *     link; nothing is cached or stored.
 */
'use strict';

importScripts('chunk-download' + (/\.min\.js$/.test(self.location.pathname) ? '.min' : '') + '.js');

var PREFIX = '__z77-download/';
var TOKEN  = /^[0-9a-f]{32}$/;
var TTL    = 60000;
var jobs   = Object.create(null);

/** Drop jobs whose page never navigated to them. */
function sweep(now) {
    Object.keys(jobs).forEach(function (t) {
        if (now - jobs[t].created > TTL) {
            jobs[t].port.postMessage({ type: 'error', message: 'Download nicht gestartet (abgelaufen).' });
            delete jobs[t];
        }
    });
}

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('message', function (e) {
    var d = e.data || {};
    if (e.origin && e.origin !== self.location.origin) return;
    // Keep-alive from a page whose download is running: receiving it is the point.
    if (d.type === 'z77-download-ping') return;
    if (d.type !== 'z77-download' || !e.ports || !e.ports[0]) return;

    var port = e.ports[0];
    var url  = Z77ChunkDownload.sameOrigin(d.url, self.location.href);
    if (!TOKEN.test(String(d.token)) || !url) {
        port.postMessage({ type: 'error', message: 'Ungültiger Download-Auftrag.' });
        return;
    }
    sweep(Date.now());

    var job = {
        url:        url,
        filename:   typeof d.filename === 'string' ? d.filename : '',
        chunkBytes: Number(d.chunkBytes) || 0,
        port:       port,
        created:    Date.now(),
        abort:      null
    };
    jobs[d.token] = job;
    port.onmessage = function (m) {
        if (!m.data || m.data.type !== 'abort') return;
        if (jobs[d.token] === job) delete jobs[d.token];
        if (job.abort) job.abort();
    };
    port.postMessage({ type: 'ready' });
});

self.addEventListener('fetch', function (e) {
    var prefix = new URL(self.registration.scope).pathname + PREFIX;
    var u = new URL(e.request.url);
    if (u.origin !== self.location.origin || u.pathname.indexOf(prefix) !== 0) return;

    sweep(Date.now());                     // an expired token is gone before it is looked up
    var token = u.pathname.slice(prefix.length);
    var job   = TOKEN.test(token) ? jobs[token] : undefined;
    if (job) delete jobs[token];           // single-use

    if (!job) {
        e.respondWith(new Response('Download nicht gefunden oder abgelaufen.', {
            status: 404,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        }));
        return;
    }

    var run = Z77ChunkDownload.respond(job, {
        fetch: function (url, init) { return fetch(url, init); },
        post:  function (message) { job.port.postMessage(message); }
    });
    job.abort = run.abort;
    e.respondWith(run.response);
    e.waitUntil(run.done);
});
