<?php

namespace Z77\Module\Dms\Services;

/**
 * Upload dedupe (checksum): the target folder already holds a live document with the
 * same original name AND identical bytes — storing it again would only create a
 * duplicate. The caller surfaces this as an informational skip, never as an error.
 */
final class DuplicateUploadException extends \RuntimeException
{
}
