<?php
namespace Z77\Module\Dms\Ui;

use Z77\Module\Dms\Entities\Document,
    Z77\Module\Dms\Services\Authz,
    Z77\Module\Dms\Services\DocumentService
;

/**
 * The media field and its picker (bauplan `media-picker-bauplan.md` P1) — the pure helpers
 * shared by the field partial (`partials/mediaField`), the picker window
 * ({@see MediaPickerTrait}) and its answers, so the address, the thumbnail and the «may this
 * be chosen» rule exist ONCE.
 *
 * A form field stores a DMS document by its ID (rename/move-safe). The host renders the partial
 * with a `name` for the hidden input; «Auswählen» opens the picker as an ADR-047 window over the
 * form, the choice answers with commands that fill THAT field — addressed by the field root's DOM
 * id, the picker's `target`. Nothing here touches the request.
 */
final class MediaField
{
    public const NS = 'Z77\\Module\\Dms';

    /** `accept` values the picker knows: name → document kinds (`DocumentKind`). '' = every kind. */
    private const ACCEPT = [
        ''      => null,
        'image' => ['image'],
    ];

    /**
     * The DOM id a choice goes to — a plain id or ''. Never a selector: the answer builds
     * `#<id> …` from it, so anything else is dropped (the article picker's rule).
     */
    public static function target(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) === 1 ? $value : '';
    }

    /** A fresh field id for a host that names none. The script re-keys a CLONE at its click. */
    public static function newId(): string
    {
        return 'dms-mf-' . bin2hex(random_bytes(4));
    }

    /** The `accept` of the request when it is one the picker knows, else null (refused). */
    public static function accept(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return array_key_exists($value, self::ACCEPT) ? $value : null;
    }

    /** Whether a document of $kind passes `accept`. */
    public static function accepts(string $accept, string $kind): bool
    {
        if (!array_key_exists($accept, self::ACCEPT)) {
            return false;
        }
        $kinds = self::ACCEPT[$accept];

        return $kinds === null || in_array($kind, $kinds, true);
    }

    /** The upload component's `accept` list for the picker's `accept` (sniffed on the server). */
    public static function uploadAccept(string $accept): array
    {
        return $accept === 'image' ? ['image/*'] : [];
    }

    /**
     * The picker root of the request: a folder id (digits) or a partition's key / slug. Kept as
     * the raw string for the address; {@see MediaPickerTrait} resolves it FIND-ONLY (never
     * `rootFolder` — a crafted value must not create a partition, S2). '' = the whole forest.
     */
    public static function root(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^(\d{1,9}|[a-z0-9][a-z0-9-]{0,63})$/', $value) === 1 ? $value : '';
    }

    /**
     * The picker's address with $state (target, root, accept, public, folder, _origin — empty
     * values left out).
     *
     * @param array<string, string|int|bool|null> $state
     */
    public static function url(string $pickUrl, array $state): string
    {
        $state = array_map(static fn($v) => is_bool($v) ? ($v ? '1' : '') : $v, $state);
        $query = http_build_query(array_filter($state, static fn($v) => $v !== null && $v !== ''));

        return $query === '' ? $pickUrl : $pickUrl . '?' . $query;
    }

    /**
     * Why a document may NOT be chosen for a field that needs a PUBLIC document, or null: the
     * web page renders only what `/media` delivers openly — effectively `public` AND an active
     * chain. Shown in the grid as the reason, asked again on the choice.
     */
    public static function publicRefusal(Document $doc, DocumentService $docs): ?string
    {
        // The ONE predicate (`/media`'s open branch, the lazy fill, the content renderers);
        // what follows only names the reason.
        if ($docs->isPubliclyDelivered($doc)) {
            return null;
        }
        if ($doc->isDeleted()) {
            return 'Nicht öffentlich (gelöscht)';
        }
        $mode = $docs->effectiveDeliveryMode($doc);
        if ($mode !== 'public') {
            return $mode === 'sealed' ? 'Nicht öffentlich (versiegelt)' : 'Nicht öffentlich (geschützt)';
        }

        return 'Nicht öffentlich (inaktiv)';
    }

    /**
     * What the field shows for $docId: `empty` (nothing chosen), `doc` (name + the `s` thumbnail
     * when the document has one) or `missing` (an id that no longer resolves for this user —
     * deleted, or not readable: the id stays in the form, the host's save judges it; the name of
     * an unreadable document is never shown, RF-4a).
     *
     * @return array{state: 'empty'|'doc'|'missing', id: int|null, name: string, thumbUrl: string|null}
     */
    public static function thumb(?int $docId, string $base): array
    {
        if ($docId === null || $docId <= 0) {
            return ['state' => 'empty', 'id' => null, 'name' => '', 'thumbUrl' => null];
        }
        $doc = DocumentService::create()->get($docId);
        if (!$doc instanceof Document || !Authz::create()->allows('document', $docId, 'read')) {
            return ['state' => 'missing', 'id' => $docId, 'name' => '', 'thumbUrl' => null];
        }

        return [
            'state'    => 'doc',
            'id'       => $docId,
            'name'     => $doc->getDisplayName(),
            'thumbUrl' => self::thumbUrl($doc, $base),
        ];
    }

    /** The `s` thumbnail through the domain-gated preview action, or null when there is none. */
    public static function thumbUrl(Document $doc, string $base): ?string
    {
        return array_key_exists('s', $doc->getVariants())
            ? self::previewUrl($doc, $base, 's')
            : null;
    }

    /**
     * The document's bytes INLINE through the domain-gated preview action of the host `$base`
     * (`DocumentDeliveryTrait`, backend `/backend/documents`) — the original, or one of its
     * variants. The one place this address is built; a module that shows a document it stored
     * (a receipt's photo) takes it from here. Whether the session may read it is the action's
     * gate (`serveFor()`), not this string's.
     */
    public static function previewUrl(Document $doc, string $base, ?string $variant = null): string
    {
        return $base . '/document/preview?id=' . (int) $doc->getId() . ($variant !== null && $variant !== '' ? '&variant=' . rawurlencode($variant) : '');
    }
}
