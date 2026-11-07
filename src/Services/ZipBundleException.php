<?php

namespace Z77\Module\Dms\Services;

/**
 * A refusal of a ZIP bundle ({@see ZipBundleService}, DMS-ZIP-001) that the user may read as it
 * is: the message is in the reader's language (`dms.zip.*`, ADR-056) and names what is wrong, the code is the HTTP status the endpoint
 * answers with (400 bad input, 403 a right withdrawn or nobody logged in, 404 unknown, expired
 * or another person's, 409 not ready or a document changed, 422 nothing to pack, 429 too many
 * unfinished). Everything else that fails is NOT one of these and is answered with a generic
 * message by the endpoint ({@see \Z77\Module\Dms\Ui\ZipBundleTrait}).
 */
final class ZipBundleException extends \RuntimeException
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
