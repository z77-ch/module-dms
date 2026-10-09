/* documents/upload-poster.js — the Drive's one piece of upload knowledge that is NOT
 * general: a poster frame grabbed out of a video in the browser (P4).
 *
 * Since 2026-10-09 the Drive uses THE upload component (`Z77\Shared`, UPLOAD-001) instead
 * of its own 396-line uploader. What the component cannot know is what a module must send
 * ALONGSIDE a file — so it offers a per-file field provider, and this is the Drive's:
 * `data-upload-extra="dms-poster"` on the form picks it, the function answers
 * `{poster: Blob}` for a video and nothing for anything else.
 *
 * Why in the browser at all: the server would have to decode the video to get a frame
 * (ffmpeg — not on shared hosting). The extraction is the proven code of the old uploader,
 * moved unchanged: frame at 5 s for a clip over 15 s, else 1 s; wait for a decodable frame
 * (`readyState >= 2`); longest edge ~1250 px; JPEG quality 0.75; a 10 s timeout that also
 * frees the decoder, so one unreadable video cannot starve the files behind it.
 *
 * A failure here is never the upload's: the component treats a rejected provider as «no
 * extra fields» and sends the video without a poster.
 */
window._Z77 = window._Z77 || {};

(function () {
    if (!_Z77.upload || !_Z77.upload.provider) return;

    _Z77.upload.provider('dms-poster', function (file) {
        if (!file.type || file.type.indexOf('video/') !== 0) {
            return null;
        }

        return extractVideoPoster(file).then(function (blob) {
            return blob ? { poster: blob } : null;
        });
    });

    /** Resolves to an image/jpeg Blob or null — never rejects. */
    function extractVideoPoster(file) {
        return new Promise(function (resolve) {
            var url     = URL.createObjectURL(file);
            var video   = document.createElement('video');
            var settled = false;
            var timer   = setTimeout(finishNull, 10000);

            function cleanup() {
                clearTimeout(timer);
                try { video.pause(); } catch (e) {}
                try { video.removeAttribute('src'); video.load(); } catch (e) {}   // free the decoder
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
                // Poll until a frame is actually decodable.
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
})();
