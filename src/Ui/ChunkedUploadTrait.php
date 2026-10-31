<?php
namespace Z77\Module\Dms\Ui;

use Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Module\Dms\Entities\Document,
    Z77\Module\Dms\Services\ChunkedUploadService,
    Z77\Module\Dms\Services\ChunkUploadException,
    Z77\Module\Dms\Services\DuplicateUploadException,
    Z77\Module\Dms\Services\NameConflictException
;

/**
 * The endpoints of the chunked upload (F3) — mount them in ANY controller with
 * `use ChunkedUploadTrait;`. The Drive does (through {@see DriveControllerTrait}); a project
 * page that lets members upload into the DMS does the same in its own controller. The
 * browser side is `documents/chunk-upload.js` (`Z77ChunkUpload.upload(file, {base, …})`,
 * `base` = this controller's URL).
 *
 * **Protection is the domain's, not this trait's.** Every action ends in
 * {@see ChunkedUploadService}, which gates `write` on the target folder for the SESSION
 * principal (Authz) and answers «not found» to anyone but the identity that began the
 * upload. The host controller only decides the URL and the coarse role (who reaches the
 * page at all). For a member that means: the member session must be the identity that
 * holds folder `write` — through a role ACE on the folder for a role the project names in
 * `dmsConfig['principalRolesHook']` (F1; `Principal::fromAuthUser` + `PrincipalRoles`).
 * All actions are `#[Fetch]`: a POST then passes the global CSRF gate (`X-CSRF-Token`
 * header, DMS-SEC-001 rule), which `chunk-upload.js` sends from `meta[name=csrf-token]`.
 *
 * Actions (URL = host controller URL + kebab action), all answer JSON:
 *
 *   POST chunk-begin   folder_id, name, size, [overwrite=1], [show_original=1]
 *                      → {ok:true, uploadId, chunkBytes}
 *   POST chunk-append  upload_id, index, chunk (multipart file, exactly chunkBytes; the last the rest)
 *                      → {ok:true, received:<count>}
 *   GET|POST chunk-status  upload_id → {ok:true, name, size, chunkBytes, chunks, received:[indices]}
 *   POST chunk-finish  upload_id, [overwrite=1], [poster] (multipart image, videos only)
 *                      → {ok:true,  status:"success",   data:{id, name}, commands?}
 *                      → {ok:false, status:"duplicate", data:{message}, error}   (200, identical bytes there)
 *                      → {ok:false, status:"conflict",  data:{message}, error}   (409, upload kept —
 *                        finish again with overwrite=1, no bytes re-sent)
 *   POST chunk-abort   upload_id → {ok:true}
 *
 *   Any refusal: {ok:false, status:"error", error:"<German message>", data:{message}} with
 *   400 / 403 / 404 / 409 / 413 / 415 / 500 / 507 — never a stack trace.
 *
 * A host may add envelope commands to a successful finish by overriding
 * {@see chunkUploadCommands()} (the Drive refreshes its panes and closes the modal).
 *
 * The using class MUST provide `json()` (AbstractBaseController).
 */
trait ChunkedUploadTrait
{
    #[Fetch, HttpMethod('POST')]
    protected function chunkBeginAction(): JsonResponse
    {
        return $this->chunkAnswer(function (): array {
            $request  = DI::getRequest();
            $folderId = (int) $request->getPostParameter('folder_id');
            $size     = (string) $request->getPostParameter('size');
            if ($folderId <= 0) {
                throw new ChunkUploadException('Bitte einen Ziel-Ordner wählen.', 400);
            }
            if (!ctype_digit($size) || strlen($size) > 18) {
                throw new ChunkUploadException('Ungültige Dateigrösse.', 400);
            }

            $service  = ChunkedUploadService::create();
            $uploadId = $service->begin(
                $folderId,
                (string) $request->getPostParameter('name'),
                (int) $size,
                (bool) $request->getPostParameter('overwrite'),
                (bool) $request->getPostParameter('show_original'),
            );

            return ['ok' => true, 'uploadId' => $uploadId, 'chunkBytes' => $service->chunkBytes()];
        });
    }

    #[Fetch, HttpMethod('POST')]
    protected function chunkAppendAction(): JsonResponse
    {
        return $this->chunkAnswer(function (): array {
            $request = DI::getRequest();
            $index   = (string) $request->getPostParameter('index');
            $chunk   = $request->getUploadedFile('chunk');
            if (!ctype_digit($index) || strlen($index) > 9) {
                throw new ChunkUploadException('Ungültige Teilstück-Nummer.', 400);
            }
            if ($chunk === null || !$chunk->isOk()) {
                throw new ChunkUploadException('Teilstück nicht empfangen.', 400);
            }

            $received = ChunkedUploadService::create()->append(
                $this->chunkUploadId(),
                (int) $index,
                $chunk,
                (string) $request->getPostParameter('sha256'), // the browser's hash of this chunk
            );

            return ['ok' => true, 'received' => $received];
        });
    }

    #[Fetch]
    protected function chunkStatusAction(): JsonResponse
    {
        return $this->chunkAnswer(function (): array {
            return ['ok' => true] + ChunkedUploadService::create()->status($this->chunkUploadId());
        });
    }

    #[Fetch, HttpMethod('POST')]
    protected function chunkFinishAction(): JsonResponse
    {
        return $this->chunkAnswer(function (): array {
            $request   = DI::getRequest();
            $overwrite = $request->getPostParameter('overwrite');
            $doc       = ChunkedUploadService::create()->finish(
                $this->chunkUploadId(),
                $overwrite === null ? null : (bool) $overwrite,
                $request->getUploadedFile('poster'),
            );

            $answer   = ['ok' => true, 'status' => 'success', 'data' => ['id' => (int) $doc->getId(), 'name' => $doc->getOriginalName()]];
            $commands = $this->chunkUploadCommands($doc);
            if ($commands !== []) {
                $answer['commands'] = $commands;
            }

            return $answer;
        });
    }

    #[Fetch, HttpMethod('POST')]
    protected function chunkAbortAction(): JsonResponse
    {
        return $this->chunkAnswer(function (): array {
            ChunkedUploadService::create()->abort($this->chunkUploadId());

            return ['ok' => true];
        });
    }

    /**
     * Envelope commands a successful finish carries (`[['action' => …, …], …]`, the
     * {@see \Z77\Core\Http\Response\EnvelopeFields::addCommand} shape). None by default;
     * the host overrides it.
     *
     * @return list<array<string, mixed>>
     */
    protected function chunkUploadCommands(Document $doc): array
    {
        return [];
    }

    /** The upload id from the query (status via GET) or the body; validated by the service. */
    private function chunkUploadId(): string
    {
        $request = DI::getRequest();

        return (string) ($request->getPostParameter('upload_id') ?? $request->getGetParameter('upload_id') ?? '');
    }

    /** Runs one action and turns every failure into the JSON refusal of the contract. */
    private function chunkAnswer(\Closure $work): JsonResponse
    {
        try {
            return $this->json($work());
        } catch (ChunkUploadException $e) {
            $code = $e->getCode();
            return $this->chunkRefusal($e->getMessage(), is_int($code) && $code >= 400 && $code < 600 ? $code : 400, $e->retry);
        } catch (NotFoundException) {
            // Authz never says whether a folder exists (DMS model) — neither does this.
            return $this->chunkRefusal('Ziel-Ordner nicht gefunden oder keine Berechtigung.', 404);
        } catch (DuplicateUploadException $e) {
            return $this->json(['ok' => false, 'status' => 'duplicate', 'error' => $e->getMessage(),
                                'data' => ['message' => $e->getMessage()]]);
        } catch (NameConflictException $e) {
            return $this->json(['ok' => false, 'status' => 'conflict', 'error' => $e->getMessage(),
                                'data' => ['message' => $e->getMessage()]], 409);
        } catch (\RuntimeException $e) {
            // The domain's own refusals for the user are German (sealed, retention); the
            // technical ones carry their class as a prefix («UploadService: …») — those are
            // logged and answered in words a user can act on.
            $message = $e->getMessage();
            if (preg_match('/^[A-Za-z]+: /', $message) !== 1) {
                return $this->chunkRefusal($message, 422);
            }
            error_log('[dms] chunked upload: ' . $message);
            return $this->chunkRefusal(match (true) {
                str_contains($message, 'is not allowed') => 'Dateityp nicht erlaubt.',
                str_contains($message, 'memory')         => 'Bild zu gross für den Arbeitsspeicher des Servers.',
                default                                  => 'Die Datei konnte nicht gespeichert werden.',
            }, str_contains($message, 'is not allowed') ? 415 : 500);
        } catch (\Throwable $e) {
            error_log('[dms] chunked upload: ' . $e);
            return $this->chunkRefusal('Die Datei konnte nicht gespeichert werden.', 500);
        }
    }

    /** `retry: true` = the client may send the same chunk again (hash mismatch). */
    private function chunkRefusal(string $message, int $status, bool $retry = false): JsonResponse
    {
        $body = ['ok' => false, 'status' => 'error', 'error' => $message, 'data' => ['message' => $message]];
        if ($retry) {
            $body['retry'] = true;
        }

        return $this->json($body, $status);
    }
}
