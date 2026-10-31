<?php

namespace Z77\Module\Dms\Services;

/**
 * A refusal of the chunked upload ({@see ChunkedUploadService}, F3) that the user may read
 * as it is: the message is German and names what is wrong, the code is the HTTP status the
 * endpoint answers with (400 bad input, 404 unknown upload, 409 incomplete, 413 too large,
 * 415 type refused, 507 no disk space). Everything else that fails is NOT one of these and
 * is answered with a generic message by the endpoint ({@see \Z77\Module\Dms\Ui\ChunkedUploadTrait}).
 */
final class ChunkUploadException extends \RuntimeException
{
    /**
     * @param bool $retry the client may send the SAME chunk again (a damaged transfer —
     *                    the bytes differ from the SHA-256 the browser computed), as
     *                    opposed to a refusal that a repeat cannot fix
     */
    public function __construct(string $message, int $code = 400, public readonly bool $retry = false)
    {
        parent::__construct($message, $code);
    }
}
