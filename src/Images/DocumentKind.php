<?php

namespace Z77\Module\Dms\Images;

/**
 * Classifies a stored document by its sniffed MIME type — and, by doing so, IS the
 * upload allowlist (ADR-016): {@see fromMime()} returns `null` for anything not listed,
 * and the save path refuses a `null` kind. The MIME is taken from a server-side
 * `finfo` sniff, never from the client-supplied content type.
 *
 * Each case also carries the two UI-relevant facts the framework needs up front:
 * - {@see previewable()} — may the delivery layer show it inline (browser can render it)
 *   versus force a download.
 * - {@see icon()} — a stable icon token the backend UI maps to its own icon set.
 *
 * The enum `value` is the human "Typ" shown in the management tool (e.g. `image`).
 *
 * {@see mailable()} (Phase 6) is the "may this be attached to an outgoing e-mail" policy
 * consumed by `DocumentService::send()`: everything except heavy streaming media
 * (video/audio), which should be shared via a `/media` link rather than a mailbox-busting
 * attachment.
 */
enum DocumentKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Video = 'video';
    case Audio = 'audio';
    case Word = 'word';
    case Excel = 'excel';
    case Text = 'text';
    case Json = 'json';
    case Html = 'html';
    case Archive = 'archive';

    /**
     * Resolve a sniffed MIME type to a kind, or `null` if it is not on the allowlist.
     * `$ext` is an optional secondary signal for ambiguous `text/*` types (e.g.
     * `application/json` vs a `.json` served as `text/plain`).
     */
    public static function fromMime(string $mime, ?string $ext = null): ?self
    {
        $mime = strtolower(trim($mime));
        $ext  = $ext !== null ? strtolower(ltrim($ext, '.')) : null;

        // Image / video / audio — match the whole family.
        if (str_starts_with($mime, 'image/')) {
            return self::Image;
        }
        if (str_starts_with($mime, 'video/')) {
            return self::Video;
        }
        if (str_starts_with($mime, 'audio/')) {
            return self::Audio;
        }

        return match ($mime) {
            'application/pdf' => self::Pdf,

            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                => self::Word,

            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                => self::Excel,

            'application/json' => self::Json,
            'text/html', 'application/xhtml+xml' => self::Html,

            'application/zip',
            'application/x-zip-compressed',
            'application/gzip',
            'application/x-tar',
            'application/x-7z-compressed'
                => self::Archive,

            // text/* is the ambiguous bucket — disambiguate via extension.
            'text/plain' => match ($ext) {
                'json' => self::Json,
                'html', 'htm' => self::Html,
                default => self::Text,
            },
            default => str_starts_with($mime, 'text/') ? self::Text : null,
        };
    }

    /**
     * Whether the delivery layer may show this inline (browser renders it natively)
     * instead of forcing a download.
     */
    public function previewable(): bool
    {
        return match ($this) {
            self::Image, self::Pdf, self::Video, self::Audio,
            self::Text, self::Json, self::Html => true,
            self::Word, self::Excel, self::Archive => false,
        };
    }

    /**
     * Whether image derivatives (profiles, OPEN-8) apply to this kind. Only images
     * are resampled; everything else stores the original only.
     */
    public function hasImageVariants(): bool
    {
        return $this === self::Image;
    }

    /**
     * Whether this kind accepts a client-supplied poster image that the server runs through
     * the GD/`ImageProfile` pipeline to give the document `s/m/l/xl` variants (Drive
     * thumbnail + `/media` poster). GD cannot decode video, so the frame MUST come from the
     * browser; video-only for now (audio has no frame to extract).
     */
    public function acceptsPoster(): bool
    {
        return $this === self::Video;
    }

    /**
     * Whether this kind may be sent as an e-mail attachment (Phase 6). Heavy streaming
     * media (video/audio) is excluded — it would bloat the message and is better shared
     * via the public `/media` link; everything else is attachable.
     */
    public function mailable(): bool
    {
        return match ($this) {
            self::Video, self::Audio => false,
            default => true,
        };
    }

    /**
     * Stable icon token for the backend UI to map onto its own icon set.
     */
    public function icon(): string
    {
        return 'file-' . $this->value;
    }
}
