<?php

namespace Z77\Module\Dms\Images;

use Z77\Core\DI;

/**
 * The upload allowlist of THIS installation: {@see DocumentKind::fromMime()} plus the
 * extensions a project adds in `dmsConfig['extraUploadTypes']` (F4, z77-rudaz.ch
 * 2026-10-31 — point clouds and BIM files that `finfo` cannot name).
 *
 * An extension on the list is matched BY EXTENSION and wins over the sniff: the file
 * becomes {@see DocumentKind::File} (never inline, no variants), whatever `finfo` said —
 * so an IFC sniffed as `text/plain` or an XML no longer counts as previewable text. The
 * stored MIME is the one the list names, else `application/octet-stream`; never the
 * client's. Without an entry everything resolves exactly as before.
 *
 * Config shape — a list of extensions, or ext => MIME, mixed is fine:
 *   'extraUploadTypes' => ['laz', 'las', 'e57', 'ifc' => 'application/x-step', 'xml'],
 *
 * ⚠️ Refused here, not trusted to the project: an extension the web server may execute
 * or a browser may render as a page (php, html, svg, …) — listing it is silently
 * ignored, the type keeps its normal classification. A malformed MIME falls back to
 * `application/octet-stream`.
 */
final class UploadTypes
{
    public const FALLBACK_MIME = 'application/octet-stream';

    private const EXT  = '/^[a-z0-9]{1,16}$/';
    private const MIME = '#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#';

    /** Executable on the server or rendered as a page by a browser — never by extension. */
    private const REFUSED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp',
        'htaccess', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs',
    ];

    /** @var array<string, string> ext => stored MIME */
    private array $extra = [];

    /** @param array<int|string, mixed> $extra the raw `extraUploadTypes` value */
    public function __construct(array $extra = [])
    {
        foreach ($extra as $key => $value) {
            [$ext, $mime] = is_int($key) ? [$value, self::FALLBACK_MIME] : [$key, $value];
            if (!is_string($ext)) {
                continue;
            }
            $ext = strtolower(ltrim(trim($ext), '.'));
            if (preg_match(self::EXT, $ext) !== 1 || in_array($ext, self::REFUSED, true)) {
                continue;
            }
            $mime = is_string($mime) ? strtolower(trim($mime)) : '';
            $this->extra[$ext] = preg_match(self::MIME, $mime) === 1 ? $mime : self::FALLBACK_MIME;
        }
    }

    public static function fromConfig(): self
    {
        $raw = DI::getConfigManager()
            ->getArrayConfig('App/Config/dmsConfig', 'Z77\\Module\\Dms')
            ->get('extraUploadTypes', []);

        return new self(is_array($raw) ? $raw : []);
    }

    /** The kind for a sniffed MIME and the file's extension, or null = not allowed. */
    public function kindFor(string $mime, ?string $ext): ?DocumentKind
    {
        return $this->listed($ext) !== null ? DocumentKind::File : DocumentKind::fromMime($mime, $ext);
    }

    /** The MIME to store: the list's for a listed extension, else the sniffed one. */
    public function storedMime(string $sniffed, ?string $ext): string
    {
        return $this->listed($ext) ?? $sniffed;
    }

    /** @return array<string, string> the effective list (ext => MIME), for docs and tests */
    public function all(): array
    {
        return $this->extra;
    }

    private function listed(?string $ext): ?string
    {
        if ($ext === null || $this->extra === []) {
            return null;
        }

        return $this->extra[strtolower(ltrim($ext, '.'))] ?? null;
    }
}
