<?php

namespace Z77\Module\Dms\Services;

/**
 * Upload name conflict: the target folder already holds a live document with the same
 * original name but DIFFERENT bytes. Never resolved silently — the caller asks the user
 * (overwrite yes/no) and retries with `overwrite: true` to replace in place
 * ({@see SaveService::replace}: id/slug/URL/ACL/mode stay, bytes/variants renew).
 */
final class NameConflictException extends \RuntimeException
{
}
