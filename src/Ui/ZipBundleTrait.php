<?php
namespace Z77\Module\Dms\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\ByteSourceResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Module\Dms\Services\ZipBundleException,
    Z77\Module\Dms\Services\ZipBundleService
;

/**
 * The endpoints of a ZIP bundle (DMS-ZIP-001) — no screen, no template. Mount them in ANY
 * controller with `use ZipBundleTrait;`. The Drive does (through {@see DriveControllerTrait}); a
 * project page that lets members download documents does the same in its own controller, it
 * needs neither the Drive nor `bulkDocs()`.
 *
 * **Protection is the domain's, not this trait's.** Every action ends in
 * {@see ZipBundleService}, which gates `read` per document for the SESSION principal and answers
 * «not found» to anyone but the identity that created the bundle. The host controller only
 * decides the URL and the coarse role. For a member that means: the member session must hold
 * `read`, through a role ACE for a role the project names in `dmsConfig['principalRolesHook']`.
 *
 * A host whose mail must lead to its own page overrides {@see zipMailProfile()}; the project's
 * hooks are listed at {@see ZipBundleService}, the recipe in `docs/topics/documents.md`.
 *
 * Actions (URL = host controller URL + kebab action):
 *
 *   POST     zip-create    ids (CSV), [name], [notify=1]    → {ok:true, …state}
 *   GET|POST zip-state     id                               → {ok:true, …state}
 *   GET|POST zip-list                                       → {ok:true, bundles:[…state]}
 *   POST     zip-delete    id                               → {ok:true}
 *   GET|HEAD zip-download  id  the archive, explicit `Range` pieces through
 *                              `documents/chunk-download.js`; each piece passes the gate
 *
 *   Any refusal: {ok:false, status:"error", error:"<message in the reader's language>", data:{message}} with
 *   400 / 403 / 404 / 409 / 422 / 429 / 500 — never a stack trace. POSTs are `#[Fetch]`: they
 *   pass the global CSRF gate (DMS-SEC-001 rule).
 *
 * The using class MUST provide `json()` (AbstractBaseController).
 */
trait ZipBundleTrait
{
    /**
     * Documents one call of {@see ZipBundleService::deleteForDocuments()} takes when a controller
     * purges many: it reads every bundle's head once, so not per document, and a fatal halfway
     * must lose at most this many, whose bundles then wait for their expiry.
     */
    private const ZIP_PURGE_BATCH = 100;

    /** @var list<int> */
    private array $zipPurgedBatch = [];

    /** A document was purged inside a loop; its bundles go with the next full batch. */
    protected function zipNotePurged(int $documentId): void
    {
        $this->zipPurgedBatch[] = $documentId;
        if (count($this->zipPurgedBatch) >= self::ZIP_PURGE_BATCH) {
            $this->zipFlushPurged();
        }
    }

    /** The rest of a batch. A failure here is logged: the documents are purged all the same. */
    protected function zipFlushPurged(): void
    {
        $batch = $this->zipPurgedBatch;
        $this->zipPurgedBatch = [];
        if ($batch === []) {
            return;
        }
        try {
            ZipBundleService::create()->deleteForDocuments($batch);
        } catch (\Throwable $e) {
            error_log('[dms] zip bundles of purged documents: ' . $e->getMessage());
        }
    }

    #[Fetch, HttpMethod('POST')]
    protected function zipCreateAction(): JsonResponse
    {
        return $this->zipAnswer(function (): array {
            $ids = explode(',', (string) $this->zipInput('ids'));

            return ['ok' => true] + ZipBundleService::create()->open(
                array_map('intval', $ids),
                (string) $this->zipInput('name'),
                (bool) $this->zipInput('notify'),
                $this->zipMailProfile(),
            );
        });
    }

    #[Fetch]
    protected function zipListAction(): JsonResponse
    {
        return $this->zipAnswer(static fn (): array => ['ok' => true, 'bundles' => ZipBundleService::create()->list()]);
    }

    /**
     * The key of `dmsConfig['zipMail']` for a bundle opened here: target URL, subject and
     * template of its mails. '' = the installation's default. The host overrides it.
     */
    protected function zipMailProfile(): string
    {
        return '';
    }

    #[Fetch]
    protected function zipStateAction(): JsonResponse
    {
        return $this->zipAnswer(fn (): array => ['ok' => true] + ZipBundleService::create()->state((string) $this->zipInput('id')));
    }

    #[Fetch, HttpMethod('POST')]
    protected function zipDeleteAction(): JsonResponse
    {
        return $this->zipAnswer(function (): array {
            ZipBundleService::create()->delete((string) $this->zipInput('id'));

            return ['ok' => true];
        });
    }

    /**
     * The archive. A refused piece answers its status (the client names it), so a download
     * that loses a right stops at the next piece and not at the end.
     */
    #[HttpMethod('GET', 'HEAD')]
    protected function zipDownloadAction(): ByteSourceResponse|JsonResponse
    {
        $request = DI::getRequest();

        try {
            return ZipBundleService::create()->serve(
                (string) $request->getGetParameter('id'),
                $request->getRange(),
                $request->getIfNoneMatch(),
                $request->isHead(),
            );
        } catch (ZipBundleException $e) {
            return $this->zipRefusal($e);
        } catch (\Throwable $e) {
            error_log('[dms] zip download: ' . $e);
            return $this->json(['ok' => false, 'status' => 'error', 'error' => t('dms.zip.notDelivered')], 500);
        }
    }

    /** A value from the fetch envelope (JSON), the form body or, for a GET, the query. */
    private function zipInput(string $key): mixed
    {
        $request = DI::getRequest();
        $body    = $request->isPost() ? $request->getJsonBody() : [];

        return $body[$key] ?? $request->getPostParameter($key) ?? $request->getGetParameter($key);
    }

    /** Runs one action and turns every failure into the JSON refusal of the contract. */
    private function zipAnswer(\Closure $work): JsonResponse
    {
        try {
            return $this->json($work());
        } catch (ZipBundleException $e) {
            return $this->zipRefusal($e);
        } catch (\Throwable $e) {
            error_log('[dms] zip bundle: ' . $e);
            return $this->json(['ok' => false, 'status' => 'error', 'error' => t('dms.zip.fail.generic')], 500);
        }
    }

    private function zipRefusal(ZipBundleException $e): JsonResponse
    {
        $code = $e->getCode();

        return $this->json(
            ['ok' => false, 'status' => 'error', 'error' => $e->getMessage(), 'data' => ['message' => $e->getMessage()]],
            is_int($code) && $code >= 400 && $code < 600 ? $code : 400
        );
    }
}
