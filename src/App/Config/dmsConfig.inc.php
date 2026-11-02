<?php
namespace Z77\Module\Dms\App;

use Z77\Core\Config\AuthRole;

return [
    // The Drive answers a `/media/…` path with its file for server-side readers (kernel LocalFiles, 2026-10-23: the mandator logos).
    'localFileResolvers' => [\Z77\Module\Dms\Files\MediaFileResolver::class],
    'defaultGroup'  => 'media',
    'groupDefaults' => [
        'media' => 'output',
    ],
    'defaultAction' => 'serve',
    'moduleRole'    => AuthRole::GUEST,

    // Byte delivery is never page-cached (FileResponse is not an HtmlResponse).
    'cache' => [
        'enabled' => false,
    ],

    // Optional remapping of a module's write target (ADR-021 rule 4): module key →
    // partition key, consumed by DocumentService::forModule(). No entry = the module
    // writes into the partition of its OWN key (get-or-create). The module key itself
    // stays a code constant (S2) — this only redirects WHERE that module writes, e.g.
    // 'moduleFolders' => ['financial' => 'archive'].
    'moduleFolders' => [],

    // Extra roles a PROJECT grants to a non-backend identity (a member), so a
    // role ACE can open a folder to SOME members instead of all `customer`s
    // (F1, z77-rudaz.ch 2026-10-30). FQCN of an invokable
    //   `__invoke(\Z77\Shared\Auth\AuthUser $user): list<string>`
    // — e.g. ['rudaz-p12-read'] for a member of project 12; that project's folder
    // carries the matching role ACE. Framework role names are dropped, a hook that
    // throws grants nothing ({@see \Z77\Module\Dms\Services\PrincipalRoles}).
    // Null = no extra roles — the DMS behaves exactly as before.
    'principalRolesHook' => null,

    // File types THIS installation accepts by extension although `finfo` cannot name
    // them (F4, z77-rudaz.ch 2026-10-31): point clouds, BIM, … A list of extensions or
    // ext => MIME, e.g. ['laz', 'las', 'e57', 'ifc' => 'application/x-step', 'xml'].
    // A listed extension wins over the sniff and becomes kind `file`: never inline, no
    // variants, no static public copy, always a download; stored MIME = the listed one or
    // application/octet-stream. Server-executable / page types (php, html, svg, js, …)
    // are ignored ({@see \Z77\Module\Dms\Images\UploadTypes}). Empty = as before.
    'extraUploadTypes' => [],

    // Chunked upload (F3, z77-rudaz.ch 2026-10-31): a file bigger than one request may be
    // (host cap — cyon 500 MB — and PHP's upload_max_filesize / post_max_size) goes up in
    // pieces of this size ({@see \Z77\Module\Dms\Services\ChunkedUploadService}). Clamped
    // below the PHP transport cap at runtime, so a too-large value cannot break uploads;
    // the browser takes the effective value from the server, never a default of its own.
    'chunkBytes' => 8 * 1024 * 1024,

    // Total size cap per file for the chunked upload, in bytes; null = no cap beyond the
    // free disk space (begin() refuses a file that would not fit). A single-request upload
    // stays bounded by the PHP transport cap regardless.
    'maxUploadBytes' => null,

    // Chunked download (DMS-CHUNK-DL-001, z77-rudaz.ch 2026-10-31): the host refuses an
    // answer above its body cap (cyon 500 MB → 413), so the Drive's «Download» of a
    // document from this size on (bytes) goes through `documents/chunk-download.js` —
    // explicit Range pieces from the same gated endpoint; smaller files keep the plain
    // link, exactly as before. 0 = never chunked.
    'chunkedDownloadFrom' => 256 * 1024 * 1024,

    // Piece size of that download, in bytes — its own key, not the upload's `chunkBytes`
    // (that one is clamped to the PHP upload limit, which says nothing about answers).
    // 32 MB: few requests, far below the host cap; the browser asks each piece with an
    // explicit end, below FileResponse::RANGE_MAX_BYTES (256 MiB), so served as asked.
    'downloadChunkBytes' => 32 * 1024 * 1024,

    // ZIP download of a selection (DMS-ZIP-001): days a bundle stays, counted from when it
    // got ready. A bundle costs no disk (its bytes are the originals); this is about links
    // going stale. The gate enforces it, whether a job runs or not.
    'zipKeepDays' => 7,

    // Below this total (bytes) the checksums run inside the request and the ZIP is ready at
    // once, without the job; 0 = always the job. To be confirmed on the host (plan, Z6).
    'zipInstantBytes' => 256 * 1024 * 1024,

    // Live ZIP bundles of one person, of any state. Above it a new one is refused until others
    // are deleted or expired.
    'zipMaxBundlesPerPerson' => 20,

    // Live ZIP bundles of the whole installation, the same refusal. This protects every other
    // person from a pile: each bundle costs a scan's worth of reading in every opening, every
    // list and every pass of the job, and the per-person cap alone is no bound when many persons
    // come together. Raise it for an installation with that many active users.
    'zipMaxBundles' => 2000,

    // An account is gone, its ZIP bundles go with it (kernel `AccountRemovedListeners`).
    'accountRemovedListeners' => [\Z77\Module\Dms\Services\ZipBundleAccountListener::class],

    // A host page uses the ZIP through its own controller (`Ui\ZipBundleTrait`). Each of these
    // is opt-in, null = as without; FQCN of an invokable with no constructor argument, the style
    // of `principalRolesHook` above:
    //
    // The address for the mail, instead of the backend user's. '' = no mail.
    //   `__invoke(\Z77\Shared\Auth\AuthUser $user): string`
    'zipMailAddressHook' => null,

    // A gate of the project's own, after `read`, at creation and on every piece; the job, which
    // has no session, does not ask it. A no ends like a missing right. It fails closed: a missing
    // class, an exception, anything but `true` is a no.
    //   `__invoke(int $documentId): bool`
    'zipDocumentGateHook' => null,

    // Once per bundle, when its first piece is answered: ids only. Retries and concurrent first
    // pieces do not call it again; a failure is logged and the download goes on.
    //   `__invoke(string $bundleId, array $documentIds): void`
    'zipDownloadStartedHook' => null,

    // The ZIP's mails. `default` for the whole installation, any other key for a host whose
    // controller answers it from `zipMailProfile()`. Name only what differs:
    //   'zipMail' => ['member' => [
    //       'url'    => '/member/main/dokumente',   // a path (CANONICAL_BASE_URL in front) or absolute
    //       'ready'  => ['subject' => '…', 'template' => 'emails/x', 'namespace' => 'Z77\\Module\\Dms'],
    //       'failed' => ['subject' => '…'],
    //   ]]
    // A template receives $documents, $size, $expires, $link, $reason; never a document name.
    'zipMail' => [],

    // Background jobs this module offers to the runner (ADR-031). The key is what a
    // queue entry stores. No 'defaultSchedule': `dms-chunk-cleanup` deletes (abandoned
    // upload leftovers under data/dms/chunks), so an operator switches it on (jobs.md,
    // delete-vs-schedule rule) — every new chunked upload sweeps the same leftovers, so
    // the disk stays in check while it is off.
    // `dms-zip-prepare` is queued by a new bundle, an idle schedule would only print every
    // pass.
    // `dms-zip-cleanup` deletes and DOES ship a schedule, the exception of the jobs.md rule
    // (JOBS-SCHED-001, ADR-031 addendum, owner 2026-11-02). The duty: an expired bundle's manifest
    // holds file names and possibly an address, and removing personal data on time must not wait
    // for an operator's switch or for somebody opening a bundle again. It deletes only the job's
    // own expired manifests, never a document, and the operator can switch the schedule off.
    'jobs' => [
        'dms-chunk-cleanup' => [
            'class'       => \Z77\Module\Dms\Jobs\ChunkCleanupJob::class,
            'label'       => 'DMS-Upload-Reste',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 3,
        ],
        'dms-zip-prepare' => [
            'class'       => \Z77\Module\Dms\Jobs\ZipPrepareJob::class,
            'label'       => 'DMS-ZIP vorbereiten',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 3,
        ],
        'dms-zip-cleanup' => [
            'class'       => \Z77\Module\Dms\Jobs\ZipCleanupJob::class,
            'label'       => 'DMS-ZIP-Reste',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 3,
            'defaultSchedule' => 'daily@03:50',
        ],
    ],

    // Reserved route (ADR-017 §8 / R3+R4c / ADR-020): the structural, mode-independent
    // delivery URL. Highest routing precedence (before alias/nav/convention AND before
    // the Fetch short-circuit — /media is reached via <img src> = Fetch mode). The
    // trailing path becomes content slugs: /media/{root-slug}/{folder-slug…}/{file} —
    // the first segment is the partition root's slug (a real folder, ADR-020).
    'reservedRoutes' => [
        '/media' => [
            'module'     => 'dms',
            'group'      => 'media',
            'controller' => 'output',
            'action'     => 'serve',
        ],
    ],

    // The OutputController is GUEST-reachable; the per-document gate (deliveryMode +
    // AclService::canRead for protected/sealed) runs inside the action, not via roles.
    'controllers' => [
        'media' => [
            'OutputController' => [
                'defaultAction'  => 'serve',
                'controllerRole' => AuthRole::GUEST,
                'actions'        => [
                    'serveAction' => AuthRole::GUEST,
                ],
            ],
        ],
    ],
];
