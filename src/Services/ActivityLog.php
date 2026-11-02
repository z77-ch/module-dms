<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Http\Response\ByteRange;
use Z77\Core\Http\Response\Etag;
use Z77\Module\Dms\ValueObjects\ActivityEvent;
use Z77\Shared\Auth\AuthUser;

/**
 * Tells a project's receiver about every file action (DMS-ACTIVITY-001), so a project can keep
 * its own activity log, including what admins do in the backend Drive.
 *
 * The seam: `dmsConfig['activityHook']` = FQCN of an invokable with no constructor argument,
 *   `__invoke(\Z77\Module\Dms\ValueObjects\ActivityEvent $event): void`
 * Null = nothing happens: one config read per process, no identity lookup, no file look.
 *
 * Called by the services, after the action really succeeded, never by a controller, so a new
 * caller is covered. A record, not a gate: a missing class, a wrong type or a hook that throws is
 * logged and the action goes on. Nothing may reach the action from here, so everything that can
 * fail stands in a try. A refused attempt reports nothing: it never gets this far.
 *
 * One hook instance per process, resolved once, so a loop over many documents costs one call
 * each and a broken configuration one log line.
 */
final class ActivityLog
{
    private const NS = 'Z77\\Module\\Dms';

    private static bool $resolved = false;
    private static ?object $hook = null;
    private static ?\Closure $actor = null;

    /** Whether a receiver is set: for a caller that would otherwise do work just to report. */
    public static function enabled(): bool
    {
        return self::hook() !== null;
    }

    public static function document(string $action, int $documentId, ?int $folderId, ?int $fromFolderId = null): void
    {
        self::send($action, $documentId, $folderId, $fromFolderId, null);
    }

    public static function folder(string $action, int $folderId, ?int $parentFolderId, ?int $fromFolderId = null): void
    {
        self::send($action, null, $folderId, $fromFolderId, $parentFolderId);
    }

    /**
     * An original is being answered: reported at the first piece only, which is what
     * {@see ZipBundleService::serve()} calls a download begun. Called after the gate, with the
     * ETag and the file the answer will use; the same reading of the same headers as
     * {@see \Z77\Core\Http\Response\FileResponse}, so the record and the answer cannot differ.
     */
    public static function delivered(string $action, int $documentId, ?int $folderId, string $path, string $etag): void
    {
        if (self::enabled() && self::isFirstPiece($path, $etag)) {
            self::document($action, $documentId, $folderId);
        }
    }

    /**
     * Offline seam for the harness: this class as the hook and this closure as the session, on the
     * same path as a configured one. Nothing outside the harness calls it.
     */
    public static function useHook(mixed $fqcn, ?\Closure $actor = null): void
    {
        self::$hook     = self::build($fqcn);
        self::$resolved = true;
        self::$actor    = $actor;
    }

    /** Forget the receiver: for the harness and long-running CLI processes. */
    public static function reset(): void
    {
        self::$hook     = null;
        self::$resolved = false;
        self::$actor    = null;
    }

    /** No HEAD, no 304, no 416, no later piece: those send no first byte of the file. */
    private static function isFirstPiece(string $path, string $etag): bool
    {
        try {
            $request = DI::getRequest();
            if ($request->isHead() || Etag::matches($request->getIfNoneMatch(), $etag)) {
                return false;
            }
            $range = ByteRange::parse($request->getRange(), (int) filesize($path));

            return $range === null || ($range !== false && $range[0] === 0);
        } catch (\Throwable $e) {
            error_log('[dms] activityHook: delivery not counted: ' . $e->getMessage());

            return false;
        }
    }

    private static function send(string $action, ?int $documentId, ?int $folderId, ?int $fromFolderId, ?int $parentFolderId): void
    {
        $hook = self::hook();
        if ($hook === null) {
            return;
        }
        try {
            $hook(new ActivityEvent($action, $documentId, $folderId, $fromFolderId, $parentFolderId, self::actorKey()));
        } catch (\Throwable $e) {
            // The ids stand in the log: the record is lost, the line says which one.
            error_log("[dms] activityHook failed for {$action} (document " . ($documentId ?? '-') . ', folder ' . ($folderId ?? '-') . '): ' . $e->getMessage());
        }
    }

    /** The session's `realm:id`; '' for a guest, a job's identity and a process without a session. */
    private static function actorKey(): string
    {
        try {
            if (self::$actor !== null) {
                return (string) (self::$actor)();
            }
            $user = DI::getAuthService()->getCurrentUser();

            return $user->getRealm() === AuthUser::REALM_CRON ? '' : ZipBundleService::ownerKey($user);
        } catch (\Throwable $e) {
            error_log('[dms] activityHook: no actor: ' . $e->getMessage());

            return '';
        }
    }

    private static function hook(): ?object
    {
        if (!self::$resolved) {
            self::$resolved = true;
            try {
                self::$hook = self::build(DI::getConfigManager()->getArrayConfig('App/Config/dmsConfig', self::NS)->get('activityHook', null));
            } catch (\Throwable $e) {
                error_log('[dms] activityHook: the config cannot be read: ' . $e->getMessage());
            }
        }

        return self::$hook;
    }

    /** The receiver from a config value; null, once logged, for anything that cannot be one. */
    private static function build(mixed $fqcn): ?object
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }
        if (!is_string($fqcn)) {
            error_log('[dms] activityHook: the value must be a class name, got ' . get_debug_type($fqcn) . '; nothing is reported.');
            return null;
        }
        try {
            $hook = new $fqcn(); // a missing class lands in the catch as well
        } catch (\Throwable $e) {
            error_log("[dms] activityHook: class '{$fqcn}' cannot be built: " . $e->getMessage() . '; nothing is reported.');
            return null;
        }
        if (!is_callable($hook)) {
            error_log("[dms] activityHook: class '{$fqcn}' is not invokable; nothing is reported.");
            return null;
        }

        return $hook;
    }
}
