<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Http\Response\ByteRange;
use Z77\Core\Http\Response\ByteSourceResponse;
use Z77\Core\Http\Response\Etag;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Blob\LocalBlobStorage;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Zip\ZipLayout;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Entities\JobRun;
use Z77\Shared\Jobs\JobQueue;
use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Upload\UploadPolicy;

/**
 * Several documents as one ZIP, without storing a byte twice (DMS-ZIP-001, plan
 * `docs/03-development/dms-zip-download-bauplan.md`). A bundle is a personal manifest, not a
 * {@see Document}: a job computes the CRC-32 of every original, then {@see ZipLayout} answers
 * each byte range from the blobs. HTTP-agnostic — the endpoints are
 * {@see \Z77\Module\Dms\Ui\ZipBundleTrait}, the browser side is `documents/chunk-download.js`.
 *
 *   open(ids, name, notify, profile) → state  gate: `read` per document (session principal)
 *   state(id)                  → state   owner only
 *   list()                     → states  the session identity's own bundles
 *   hasMailAddress()           → bool    may a dialog offer the mail?
 *   serve(id, range, …)        → answer  the gate per piece, below
 *   delete(id)                           owner only
 *   prepare(hasTime)           → counts  {@see \Z77\Module\Dms\Jobs\ZipPrepareJob}: CRC slices, mails
 *   sweep()                    → count   {@see \Z77\Module\Dms\Jobs\ZipCleanupJob}, every new bundle too
 *   deleteForDocuments(ids)    → count   a purge: every bundle holding one of them, no gate
 *   deleteForOwner(key)        → count   a purge: every bundle of an identity, no gate
 *   ownerKey(AuthUser)         → key     what an identity's bundles are bound to
 *
 * **Where it lives.** `data/dms/zips/<id>/manifest.json`, outside the web root and outside every
 * backup (`BackupService::DATA_EXCLUDES`: a restore must not bring back a half-counted bundle).
 * `id` is 32 random hex chars, the ONLY thing a client names, validated before it touches a path.
 * A manifest is two JSON lines: a short head (owner, state, times, the claim, the mail's state,
 * document ids; never a name) and the rest. A pass, a sweep, a list and a purge read heads one at
 * a time and decode a full manifest only for what they then work on, so a pile costs them neither
 * memory nor much time. A manifest is only ever replaced whole (temp file, rename) under the
 * bundle's `lock` file, which also survives that rename; readers take it shared.
 *
 * **How many.** `zipMaxBundlesPerPerson` live bundles per person, of any state, and `zipMaxBundles`
 * in the installation; at most {@see MAX_UNFINISHED} of a person's wait for bytes. Checked and
 * created under one lock (`open.lock`), so that neither can be raced. What the caps do not bound
 * is a person who deletes and opens again and again: each instant opening reads its bytes once.
 *
 * **Who may.** Only the identity that created it, stored as a hash of realm + id. Anyone else
 * gets «not found», a super-user too: the bundle is a person's selection, and its existence is
 * none of anybody else's business. Rights on the DOCUMENTS are asked separately, every time.
 *
 * **The gate per piece** ({@see serve()}): owner, expiry; then every document the range touches:
 * `read` for the session principal, not deleted, checksum and size as they were when counted. A
 * range starting at 0 and an answer of the whole check ALL documents, so a doomed download stops
 * on its first piece and not in its last gigabyte. A piece that only clips a header or reaches
 * the central directory counts as touching: those bytes carry the names
 * ({@see ZipLayout::touched()}). The stored `size` comes from the manifest, never the disk, so a
 * replaced document cannot shift what the other pieces answer.
 *
 * **The expiry is the gate's**, not the job's: a job that is switched off cannot keep a bundle
 * alive. The clock starts at creation and starts again when it is ready. What expired is removed
 * by `dms-zip-cleanup`, which ships a daily schedule, the exception in jobs.md: a manifest holds
 * file names and possibly an address, and removing them on time is a duty (JOBS-SCHED-001, owner
 * 2026-11-02). Every new bundle sweeps as well, a bounded number. A job pass that sees an expired
 * bundle drops a pending address at once, the rest goes with the clean-up.
 *
 * **The job reads blobs without the owner's rights and outputs nothing but CRCs** (plan,
 * decisions).
 *
 * **One owner for a bundle's preparation:** the `lease` in the manifest (a token and a time). A
 * request preparing an instant bundle holds one from its creation, a job pass takes one before it
 * works and gives it back at the end of its slice; whoever holds it renews it with every saved
 * step. Everyone else leaves the bundle alone, and every write of the holder re-reads the manifest
 * under the lock and gives up unless it is still `preparing`, still under that token, with the
 * progress the holder last wrote. A lease that ran out means its holder died: the job adopts the
 * bundle. So does a lease further ahead than a lease can be: a clock that was ahead wrote it. A
 * bundle in preparation without a live holder is stranded, lease or not, and may have no queue
 * entry (a dead request queued none, an entry can be removed by hand or fail three times), so the
 * owner's next look at it (`state`, `list`) or any next `open`, a refused one too, queues the job;
 * the queue keeps one waiting entry however often that happens. The lock is held per read and
 * write, never while hashing, so a client asking for the state does not wait for a slice.
 *
 * **Resuming mid-file:** `serialize(HashContext)` works for `crc32b`, so the cursor in the manifest
 * (file, offset, hash state) lets any later process go on. The hash state is only ever
 * unserialized as a `HashContext` ({@see restoreContext()}); whatever does not read as one
 * restarts that file. Nothing is lost but its bytes read again.
 *
 * **A host page** (a project's member page, DMS-ZIP-001 phase B) uses all this through its own
 * controller and {@see \Z77\Module\Dms\Ui\ZipBundleTrait}. Four things are the project's, all
 * opt-in in `dmsConfig`, nothing set = as above (invokable classes with no constructor argument,
 * the style of `principalRolesHook`):
 *   zipMailAddressHook      `__invoke(AuthUser $user): string`, '' = no mail; replaces the
 *                           backend user's address
 *   zipDocumentGateHook     `__invoke(int $documentId): bool`; asked after `read`, at opening and
 *                           on every piece, a no ends exactly like a missing right. Fails closed:
 *                           a class that is missing or throws, an answer that is not `true`, is a no
 *   zipDownloadStartedHook  `__invoke(string $bundleId, array $documentIds): void`; once per
 *                           bundle, at the first piece, ids only. A failure is logged, nothing more.
 *                           Where the claim cannot be saved it does not fire: once could not be
 *                           promised then, and a later piece tries again
 *   zipMail                 target URL, subject and template per mail, for all or per host
 * The job has no session, so `zipDocumentGateHook` is not asked there: it outputs nothing but CRCs.
 *
 * Seams for the offline harness (`tests/dms-zip-bundle.php`): owner, read gate, document lookup,
 * address, job queue, mail and both hooks are closures — {@see create()} binds them to the
 * session, {@see Authz}, the repository, {@see JobQueue}, `EmailService` and the config. `Authz`
 * is final and reads the session, so it cannot stand in a CLI test; the closures keep the wiring
 * in ONE place.
 */
final class ZipBundleService
{
    /** The job's key in the module config `jobs`: enqueued here, executed by the runner only. */
    public const JOB_KEY = 'dms-zip-prepare';

    /** Same value as the Drive's `BULK_MAX`, the bulk rule's cap (the harness compares them). */
    public const MAX_DOCUMENTS = 500;

    /** Each unfinished bundle reads every byte once; a person may have this many at a time. */
    public const MAX_UNFINISHED = 3;

    public const DEFAULT_KEEP_DAYS     = 7;
    public const DEFAULT_INSTANT_BYTES = 256 * 1024 * 1024;

    /** Live bundles per person, of any state, and in the whole installation (config: `zipMaxBundlesPerPerson`, `zipMaxBundles`). */
    public const DEFAULT_MAX_PER_PERSON = 20;
    public const DEFAULT_MAX_BUNDLES    = 2000;

    /**
     * Where the mail sends its reader, unless `zipMail` says otherwise: one place, so Z5 fills in
     * the real view. Until the «ZIP-Downloads» view exists this is the Drive itself.
     */
    public const DRIVE_VIEW_ROUTE = '/backend/documents/drive/list';

    public const MAIL_KIND_READY  = 'ready';
    public const MAIL_KIND_FAILED = 'failed';

    public const STATE_PREPARING = 'preparing';
    public const STATE_READY     = 'ready';
    public const STATE_FAILED    = 'failed';

    public const MAIL_NONE    = 'none';
    public const MAIL_PENDING = 'pending';
    public const MAIL_SENDING = 'sending';
    public const MAIL_SENT    = 'sent';
    public const MAIL_FAILED  = 'failed';

    /** What {@see advance()} answers when the bundle was no longer its to work on. */
    private const STATE_LOST = 'lost';

    private const ID        = '/^[a-f0-9]{32}$/D';
    private const MANIFEST  = 'manifest.json';
    private const TEMP      = 'manifest.tmp';
    private const LOCK      = 'lock';
    private const OPEN_LOCK = 'open.lock';
    private const ALGO      = 'crc32b';

    /** What the head line of a manifest holds besides derived values; see {@see encode()}. */
    private const HEAD_FIELDS = ['owner', 'state', 'created', 'expires', 'lease'];

    /** A lease untouched for this long means its holder died. Far above one step on a slow disk. */
    private const LEASE_SECONDS = 300;

    /** Expired bundles and leftovers one `open` removes; the rest wait for the next one or the job. */
    private const SWEEP_PER_OPEN = 100;

    /**
     * A directory without a readable bundle is a leftover from this age on. Younger, it may be one
     * being made or removed right now; nothing else ever reads it.
     */
    private const LEFTOVER_SECONDS = 600;

    private const NAMESPACE = 'Z77\\Module\\Dms';

    /** The framework's own mails. `zipMail` in dmsConfig replaces any field, see {@see mailSettings()}. */
    private const MAIL_DEFAULTS = [
        'url'                  => self::DRIVE_VIEW_ROUTE,
        self::MAIL_KIND_READY  => ['subject' => 'ZIP-Download bereit', 'template' => 'emails/zipReady', 'namespace' => self::NAMESPACE],
        self::MAIL_KIND_FAILED => ['subject' => 'ZIP-Download fehlgeschlagen', 'template' => 'emails/zipFailed', 'namespace' => self::NAMESPACE],
    ];

    /** Bytes hashed between two looks at the clock; the cursor is saved after each. */
    private const STEP_BYTES = 32 * 1024 * 1024;

    /**
     * @param string   $baseDir      absolute directory holding one sub-directory per bundle
     * @param int      $keepDays     how long a bundle stays
     * @param int      $instantBytes below this total the CRC runs inside {@see open()}; 0 = never
     * @param \Closure $owner        fn(): string — stable key of the session identity, '' = nobody
     * @param \Closure $canRead      fn(int $documentId): bool — `read` for the session principal
     * @param \Closure $document     fn(int $documentId): ?Document — the live document, no gate
     * @param \Closure $ownerEmail   fn(): string — the address on the session's account, '' = none
     * @param \Closure $enqueue      fn(): void — queues {@see JOB_KEY} unless an entry already waits
     * @param \Closure $mail         fn(string $to, array{kind: string, documents: int, bytes: int, expires: int, reason: string, profile: string} $info): bool
     * @param int      $stepBytes    harness seam: a smaller step gives more slices on a small file
     * @param \Closure|null $now     harness seam: fn(): int, the clock
     * @param \Closure|null $documentGate    fn(int $documentId): bool — the host's own gate after `read`, null = none
     * @param \Closure|null $downloadStarted fn(string $bundleId, list<int> $documentIds): void, null = none
     * @param int      $maxPerPerson live bundles one person may hold, of any state
     * @param int      $maxBundles   live bundles in the installation
     */
    public function __construct(
        private string $baseDir,
        private BlobStorage $blob,
        private int $keepDays,
        private int $instantBytes,
        private \Closure $owner,
        private \Closure $canRead,
        private \Closure $document,
        private \Closure $ownerEmail,
        private \Closure $enqueue,
        private \Closure $mail,
        private int $stepBytes = self::STEP_BYTES,
        private ?\Closure $now = null,
        private ?\Closure $documentGate = null,
        private ?\Closure $downloadStarted = null,
        private int $maxPerPerson = self::DEFAULT_MAX_PER_PERSON,
        private int $maxBundles = self::DEFAULT_MAX_BUNDLES,
    ) {
        $this->baseDir = rtrim($baseDir, '/\\');
        $this->keepDays = max(1, $keepDays);
        $this->stepBytes = max(1, $stepBytes);
    }

    public static function create(): self
    {
        $config = DI::getConfigManager()->getArrayConfig('App/Config/dmsConfig', self::NAMESPACE);
        $authz  = Authz::create();

        $addressHook = (string) $config->get('zipMailAddressHook', '');
        $gateHook    = (string) $config->get('zipDocumentGateHook', '');
        $startedHook = (string) $config->get('zipDownloadStartedHook', '');
        $mailConfig  = (array) $config->get('zipMail', []);

        return new self(
            ABS_BASE_PATH . '/data/dms/zips',
            new LocalBlobStorage(),
            (int) $config->get('zipKeepDays', self::DEFAULT_KEEP_DAYS),
            (int) $config->get('zipInstantBytes', self::DEFAULT_INSTANT_BYTES),
            static function (): string {
                $user = DI::getAuthService()->getCurrentUser();
                return $user !== null ? self::ownerKey($user) : '';
            },
            static fn (int $id): bool => $authz->allows('document', $id, 'read'),
            static function (int $id): ?Document {
                $doc = DI::getUnifiedEntityManager()->getRepository(Document::class)->find($id);
                return $doc instanceof Document && !$doc->isDeleted() ? $doc : null;
            },
            // A backend account has an address; a member's lives in the project: the hook.
            static function () use ($addressHook): string {
                if ($addressHook === '') {
                    return (string) DI::getCurrentUserService()->getBackendUser()?->getEmail();
                }
                try {
                    return (string) self::callHook($addressHook, [DI::getAuthService()->getCurrentUser()]);
                } catch (\Throwable $e) {
                    error_log('[dms] zipMailAddressHook failed: ' . $e->getMessage());
                    return '';
                }
            },
            static function (): void {
                // Only an entry still WAITING is sure to see this manifest. A running one may
                // have scanned before it existed and finish without it, so `hasOpenEntry()`
                // would strand the bundle until the next one comes along.
                $jobs = new JobQueue(DI::getUnifiedEntityManager());
                foreach ($jobs->all() as $run) {
                    if ($run->getJobKey() === self::JOB_KEY && $run->getState() === JobRun::STATE_QUEUED) {
                        return;
                    }
                }
                $jobs->enqueue(self::JOB_KEY, [], 'dms-zip');
            },
            static fn (string $to, array $info): bool => DI::getEmailService()->send(self::mailMessage(
                $to,
                defined('CANONICAL_BASE_URL') ? (string) CANONICAL_BASE_URL : '',
                $info,
                self::mailSettings($mailConfig, (string) ($info['profile'] ?? ''))
            )),
            documentGate: $gateHook === '' ? null : static fn (int $id): bool => self::callHook($gateHook, [$id]) === true,
            downloadStarted: $startedHook === '' ? null : static function (string $id, array $documentIds) use ($startedHook): void {
                self::callHook($startedHook, [$id, $documentIds]);
            },
            maxPerPerson: (int) $config->get('zipMaxBundlesPerPerson', self::DEFAULT_MAX_PER_PERSON),
            maxBundles: (int) $config->get('zipMaxBundles', self::DEFAULT_MAX_BUNDLES),
        );
    }

    /**
     * What an identity's bundles are bound to: `realm:id`, so a member and a backend user with
     * the same id never meet. '' for nobody. For a purge ({@see deleteForOwner()}).
     */
    public static function ownerKey(AuthUser $user): string
    {
        return $user->isLoggedIn() ? $user->getRealm() . ':' . $user->getId() : '';
    }

    /** One instance per class and process, a hook takes no constructor argument. */
    private static function callHook(string $fqcn, array $arguments): mixed
    {
        static $instances = [];
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("ZipBundleService: hook class '{$fqcn}' not found.");
        }
        $instances[$fqcn] ??= new $fqcn();

        return $instances[$fqcn](...$arguments);
    }

    /**
     * The mail's settings for one profile: the framework's, then `zipMail['default']`, then
     * `zipMail[<profile>]`, field by field, so a host records only what differs (Rule 2).
     *
     * @param array<string, mixed> $zipMail dmsConfig['zipMail']
     * @return array<string, mixed>
     */
    public static function mailSettings(array $zipMail, string $profile = ''): array
    {
        return array_replace_recursive(
            self::MAIL_DEFAULTS,
            (array) ($zipMail['default'] ?? []),
            $profile !== '' ? (array) ($zipMail[$profile] ?? []) : []
        );
    }

    /**
     * The mail «ready» or «failed». No document name and no bundle name (a mail may go through
     * other hands), no id and no token in the link: it leads to a page, the login decides. A
     * relative target gets its origin from `CANONICAL_BASE_URL`, a job has no request (mail.md,
     * SEC-005). A template receives `documents`, `size`, `expires`, `link`, `reason`.
     *
     * @param array{kind?: string, documents: int, bytes: int, expires: int, reason?: string} $info
     * @param array<string, mixed> $settings {@see mailSettings()}
     * @throws \RuntimeException no canonical base URL: the link would point nowhere
     */
    public static function mailMessage(string $to, string $baseUrl, array $info, array $settings = []): EmailMessage
    {
        $settings = array_replace_recursive(self::MAIL_DEFAULTS, $settings);
        $kind     = ($info['kind'] ?? self::MAIL_KIND_READY) === self::MAIL_KIND_FAILED ? self::MAIL_KIND_FAILED : self::MAIL_KIND_READY;
        $url      = (string) $settings['url'];
        if (preg_match('#^https?://#i', $url) !== 1) {
            $base = rtrim(trim($baseUrl), '/');
            if ($base === '') {
                throw new \RuntimeException('ZipBundleService: no canonicalBaseUrl in config/client/systemConfig.inc.php — a link would point nowhere.');
            }
            $url = $base . '/' . ltrim($url, '/');
        }

        return (new EmailMessage())
            ->to($to)
            ->subject((string) $settings[$kind]['subject'])
            ->template((string) $settings[$kind]['template'], (string) $settings[$kind]['namespace'], [
                'documents' => (int) $info['documents'],
                'size'      => UploadPolicy::formatBytes((int) $info['bytes']),
                'expires'   => date('d.m.Y', (int) $info['expires']),
                'link'      => $url,
                'reason'    => (string) ($info['reason'] ?? ''),
            ]);
    }

    /**
     * A new bundle from a selection. Every document is gated with `read`; what is denied or
     * unknown is only counted (its name is none of this person's business), a readable document
     * without its original is skipped and named. Below `instantBytes` the CRC runs right here and
     * the answer says how it ended, otherwise the job takes it.
     *
     * @param list<int> $documentIds
     * @param bool      $notify  a mail when the job has finished, ready or failed. Ignored for an
     *                           instant bundle, whose outcome stands in this answer, and without
     *                           an address on the account
     * @param string    $mailProfile a key of `dmsConfig['zipMail']`: a host's own mail, kept in the
     *                           manifest because the job does not know which page this came from
     * @return array<string, mixed> {@see view()}
     * @throws ZipBundleException readable refusal
     */
    public function open(array $documentIds, string $name = '', bool $notify = false, string $mailProfile = ''): array
    {
        $owner = ($this->owner)();
        if ($owner === '') {
            throw new ZipBundleException('Bitte zuerst anmelden.', 403);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new ZipBundleException('Keine Dokumente gewählt.', 400);
        }
        if (count($ids) > self::MAX_DOCUMENTS) {
            throw new ZipBundleException('Höchstens ' . self::MAX_DOCUMENTS . ' Dokumente pro ZIP.', 400);
        }

        [$entries, $skipped, $denied] = $this->collect($ids);
        if ($entries === []) {
            throw new ZipBundleException('Keines der gewählten Dokumente lässt sich packen.', 422);
        }

        $bytes   = array_sum(array_column($entries, 'size'));
        $instant = $this->instantBytes > 0 && $bytes < $this->instantBytes;

        $names = (new ZipLayout(array_map(
            static fn (array $e): array => ['name' => $e['name'], 'path' => '', 'size' => $e['size'], 'crc' => 0, 'mtime' => $e['mtime']],
            $entries
        )))->names();
        foreach ($entries as $i => $entry) {
            $entries[$i]['name'] = $names[$i];
        }

        $address = $notify && !$instant ? trim(($this->ownerEmail)()) : '';
        $now     = $this->now();
        $id      = bin2hex(random_bytes(16));
        $token   = $instant ? bin2hex(random_bytes(8)) : '';
        $m = [
            'version' => 1,
            'id'      => $id,
            'owner'   => hash('sha256', $owner),
            'name'    => self::bundleName($name, $now),
            'created' => $now,
            'expires' => $now + $this->keepDays * 86400,
            'state'   => self::STATE_PREPARING,
            'lease'   => $instant ? ['token' => $token, 'until' => $now + self::LEASE_SECONDS] : null,
            'entries' => $entries,
            'skipped' => $skipped,
            'denied'  => $denied,
            'cursor'  => ['file' => 0, 'offset' => 0, 'hash' => null],
            'mail'    => $address !== '' ? ['state' => self::MAIL_PENDING, 'to' => $address] : ['state' => self::MAIL_NONE],
            'mailProfile' => preg_match('/^[a-zA-Z0-9._-]{1,40}$/D', $mailProfile) === 1 ? $mailProfile : '',
            'error'   => null,
        ];

        $this->ensureBaseDir();
        $stranded = false;
        try {
            $this->admit($m, $instant, $stranded);
        } catch (ZipBundleException $e) {
            // Told to wait for what lies stranded: the wait must be able to end.
            if ($stranded) {
                $this->requestJob();
            }
            throw $e;
        }

        if ($instant) {
            if ($stranded) {
                $this->requestJob();
            }
            $this->advance($id, $m, static fn (): bool => true, $token);
        } else {
            try {
                ($this->enqueue)();
            } catch (\Throwable $e) {
                // Nobody would ever work on it, and it would hold one of the person's slots.
                $this->removeBundle($id);
                throw $e;
            }
        }

        return $this->view($this->read($id) ?? $m);
    }

    /**
     * Check the limits and create the bundle as one step, so that no second process slips in
     * between, sweeping what expired on the way. A directory without a readable bundle counts for
     * nobody and goes once it is old enough. `$stranded` says whether a bundle lies stranded,
     * refused or not.
     *
     * @param array<string, mixed> $m
     * @throws ZipBundleException 429 a limit
     */
    private function admit(array $m, bool $instant, bool &$stranded): void
    {
        $h = @fopen($this->baseDir . '/' . self::OPEN_LOCK, 'c+b');
        if ($h === false || !flock($h, LOCK_EX)) {
            throw new \RuntimeException('ZipBundleService: cannot take the opening lock.');
        }

        try {
            $now        = $this->now();
            $swept      = 0;
            $total      = 0;
            $own        = 0;
            $unfinished = 0;
            foreach ($this->heads() as $id => $head) {
                if ($head === null) {
                    if ($swept < self::SWEEP_PER_OPEN && $this->isLeftover($id, $now) && $this->sweepOne($id)) {
                        $swept++;
                    }
                    continue;
                }
                if ((int) $head['expires'] < $now) {
                    if ($swept < self::SWEEP_PER_OPEN && $this->sweepOne($id)) {
                        $swept++;
                    }
                    continue;
                }
                $total++;
                $stranded = $stranded || self::isStranded($head, $now);
                if (hash_equals((string) $head['owner'], (string) $m['owner'])) {
                    $own++;
                    if ($head['state'] === self::STATE_PREPARING) {
                        $unfinished++;
                    }
                }
            }

            if ($own >= $this->maxPerPerson) {
                throw new ZipBundleException('Es gibt bereits ' . $own . ' ZIP. Ein weiteres ist möglich, sobald eines gelöscht oder abgelaufen ist.', 429);
            }
            if ($total >= $this->maxBundles) {
                throw new ZipBundleException('Zurzeit sind zu viele ZIP vorhanden. Ein weiteres ist möglich, sobald andere abgelaufen sind.', 429);
            }
            if (!$instant && $unfinished >= self::MAX_UNFINISHED) {
                throw new ZipBundleException('Es werden bereits ' . self::MAX_UNFINISHED . ' ZIP vorbereitet. Ein weiteres ist möglich, sobald eines bereit ist.', 429);
            }

            $dir = $this->baseDir . '/' . $m['id'];
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                throw new \RuntimeException("ZipBundleService: cannot create '{$dir}'.");
            }
            // The lock first: a manifest without one reads as no bundle.
            if (@file_put_contents($dir . '/' . self::LOCK, '') === false || !$this->replace($dir, $m)) {
                $this->removeDir($dir);
                throw new \RuntimeException("ZipBundleService: cannot write the manifest in '{$dir}'.");
            }
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /**
     * What a client may know about its bundle.
     *
     * @return array<string, mixed> {@see view()}
     * @throws ZipBundleException 404: unknown, expired, or another person's
     */
    public function state(string $id): array
    {
        $m = $this->owned($id);
        if (self::isStranded($m, $this->now())) {
            $this->requestJob();
        }

        return $this->view($m);
    }

    /**
     * The session identity's own bundles that have not expired, newest first. Nobody logged in:
     * none, whoever else's exist.
     *
     * @return list<array<string, mixed>> {@see view()}
     */
    public function list(): array
    {
        $owner = ($this->owner)();
        if ($owner === '') {
            return [];
        }

        $hash     = hash('sha256', $owner);
        $now      = $this->now();
        $own      = [];
        $stranded = false;
        foreach ($this->heads() as $id => $head) {
            if ($head !== null && hash_equals((string) $head['owner'], $hash) && (int) $head['expires'] >= $now) {
                $own[$id] = (int) $head['created'];
                $stranded = $stranded || self::isStranded($head, $now);
            }
        }
        if ($stranded) {
            $this->requestJob();
        }

        uksort($own, static fn (string $a, string $b): int => [$own[$b], $b] <=> [$own[$a], $a]);
        $views = [];
        foreach (array_keys($own) as $id) {
            $m = $this->read($id);
            if ($m !== null) {
                $views[] = $this->view($m);
            }
        }

        return $views;
    }

    /** Whether this session can be told by mail: a dialog offers the checkbox only then. */
    public function hasMailAddress(): bool
    {
        return trim(($this->ownerEmail)()) !== '';
    }

    /**
     * The answer to one request for the archive, after the gate per piece (class comment). The
     * request's values come in as arguments (Rule 4): the caller reads them through `Request`,
     * and the gate must judge the very slice that the answer will send.
     *
     * @throws ZipBundleException 404 unknown / expired / another person's, 403 `read` withdrawn,
     *                            409 not ready, a document deleted or changed
     */
    public function serve(string $id, string $rangeHeader = '', ?string $ifNoneMatch = null, bool $head = false): ByteSourceResponse
    {
        $m = $this->owned($id);
        if ($m['state'] !== self::STATE_READY) {
            throw new ZipBundleException(
                $m['state'] === self::STATE_FAILED ? (string) ($m['error'] ?? 'Dieses ZIP konnte nicht erstellt werden.') : 'Dieses ZIP ist noch nicht bereit.',
                409
            );
        }

        $layout = $this->layout($m);
        $range  = $head ? null : ByteRange::parse($rangeHeader, $layout->size());
        foreach ($this->gatedEntries($layout, $range) as $index) {
            $this->gate($m['entries'][$index]);
        }

        // The download begins with the first piece answered, wherever it starts. No HEAD, no 304
        // and no 416: those send no byte of it.
        $etag = self::etag($m);
        if (!$head && $range !== false && !Etag::matches($ifNoneMatch, $etag)) {
            $this->announceDownload($id, $m);
        }

        return new ByteSourceResponse(
            source: $layout,
            filename: (string) $m['name'],
            mimeType: 'application/zip',
            etag: $etag,
            rangeHeader: $rangeHeader,
            ifNoneMatch: $ifNoneMatch,
            head: $head,
        );
    }

    /**
     * The owner removes a bundle; nothing but its manifest goes.
     *
     * @throws ZipBundleException 404: unknown, expired, or another person's
     */
    public function delete(string $id): void
    {
        $this->owned($id);
        $this->removeBundle($id);
    }

    /**
     * Documents are purged: every bundle holding one goes, whoever owns it, skipped entries
     * included, because a manifest holds file names. For the code that purges, so no gate; an
     * expired bundle not yet swept counts too. A manifest nobody can read names nobody and waits
     * for the sweep. It reads every head once, so whoever purges many documents hands them over
     * at once, not in a loop.
     *
     * @param list<int> $documentIds
     * @return int removed bundles
     */
    public function deleteForDocuments(array $documentIds): int
    {
        $wanted = array_flip(array_map('intval', $documentIds));
        if ($wanted === []) {
            return 0;
        }

        return $this->deleteWhere(static function (array $head) use ($wanted): bool {
            foreach ((array) ($head['documents'] ?? []) as $document) {
                if (isset($wanted[(int) $document])) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * An identity is purged: every bundle bound to it goes. No gate, see {@see deleteForDocuments()}.
     *
     * @param string $ownerKey {@see ownerKey()}
     * @return int removed bundles
     */
    public function deleteForOwner(string $ownerKey): int
    {
        if ($ownerKey === '') {
            return 0;
        }
        $hash = hash('sha256', $ownerKey);

        return $this->deleteWhere(static fn (array $head): bool => hash_equals((string) $head['owner'], $hash));
    }

    /**
     * One pass of the job, without anyone's rights. Oldest first, one bundle at a time, until the
     * budget is spent: what waits for bytes and is not somebody else's to prepare, and the mails
     * of finished bundles. A mail still `sending` at this point did not finish: it may or may not
     * have left, and asking twice is worse than not at all, so it is recorded as failed and the
     * bundle stays usable. A failure ends only its own bundle's turn.
     *
     * @param \Closure $hasTime fn(): bool — may another step start? ({@see \Z77\Shared\Jobs\JobContext::hasTimeLeft})
     * @return array{ready: int, open: int, failed: int} after this pass: bundles that got ready
     *         in it, that the job must come back for, that failed in it
     */
    public function prepare(\Closure $hasTime): array
    {
        $counts = ['ready' => 0, 'open' => 0, 'failed' => 0];
        $now    = $this->now();

        $work = [];
        foreach ($this->heads() as $id => $head) {
            if ($head === null) {
                continue;
            }
            if ((int) $head['expires'] < $now) {
                // The gate refuses it already, the sweep removes it; an address need not wait.
                if (($head['mailState'] ?? '') === self::MAIL_PENDING) {
                    $this->forgetAddress($id);
                }
                continue;
            }
            $preparing = $head['state'] === self::STATE_PREPARING;
            if ($preparing || in_array($head['mailState'] ?? '', [self::MAIL_PENDING, self::MAIL_SENDING], true)) {
                $work[] = [(int) $head['created'], $id, $preparing];
            }
        }
        sort($work);

        foreach ($work as [, $id, $preparing]) {
            if (!$hasTime()) {
                $counts['open']++;
                continue;
            }

            try {
                if (!$preparing) {
                    $this->sendPendingMail($id);
                    continue;
                }

                $token = bin2hex(random_bytes(8));
                $busy  = false;
                $m     = $this->claim($id, $token, $busy);
                if ($m === null) {
                    // Somebody prepares it, so come back; otherwise it got ready or went away.
                    $counts['open'] += $busy ? 1 : 0;
                    continue;
                }

                match ($this->advance($id, $m, $hasTime, $token)) {
                    self::STATE_READY     => $counts['ready']++,
                    self::STATE_FAILED    => $counts['failed']++,
                    self::STATE_PREPARING => $counts['open']++,
                    default               => null,
                };
            } catch (\Throwable $e) {
                error_log("[dms] zip bundle {$id}: " . $e->getMessage());
                $counts['open']++;
            }
        }

        return $counts;
    }

    /**
     * Remove what has expired, and what is no readable bundle once it is old enough
     * ({@see LEFTOVER_SECONDS}). A bundle whose manifest is locked right now is left alone.
     *
     * @param \Closure|null $hasTime fn(): bool — may another removal start?
     * @return int removed bundles
     */
    public function sweep(?\Closure $hasTime = null): int
    {
        $now     = $this->now();
        $removed = 0;
        foreach ($this->heads() as $id => $head) {
            if ($hasTime !== null && !$hasTime()) {
                break;
            }
            $due = $head !== null ? (int) $head['expires'] < $now : $this->isLeftover($id, $now);
            if ($due && $this->sweepOne($id)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Remove one bundle unless something holds its lock right now: the next sweep takes it. */
    private function sweepOne(string $id): bool
    {
        $dir  = $this->baseDir . '/' . $id;
        $lock = $dir . '/' . self::LOCK;
        $h    = is_file($lock) ? @fopen($lock, 'r+b') : null;
        if ($h === false || ($h !== null && !flock($h, LOCK_EX | LOCK_NB))) {
            if (is_resource($h)) {
                fclose($h);
            }
            return false;
        }
        if ($h !== null) {
            flock($h, LOCK_UN);
            fclose($h);
        }

        return $this->removeDirPatiently($dir);
    }

    /** No readable bundle, and old enough not to be one in the making. */
    private function isLeftover(string $id, int $now): bool
    {
        $dir  = $this->baseDir . '/' . $id;
        $path = $dir . '/' . self::MANIFEST;
        clearstatcache(true, $path);
        $touched = max((int) @filemtime($dir), is_file($path) ? (int) @filemtime($path) : 0);

        return $touched < $now - self::LEFTOVER_SECONDS;
    }

    /** A pending address on a bundle nobody will mail any more. */
    private function forgetAddress(string $id): void
    {
        try {
            $this->update($id, static function (?array $m): ?array {
                if ($m === null || ($m['mail']['state'] ?? '') !== self::MAIL_PENDING) {
                    return null;
                }
                $m['mail'] = ['state' => self::MAIL_NONE];
                return $m;
            });
        } catch (\Throwable $e) {
            error_log("[dms] zip bundle {$id}: its address could not be dropped — " . $e->getMessage());
        }
    }

    /**
     * What is left of a selection after the gate: entries for the manifest, a skip list with
     * names (readable, no original), and how many ids told nothing (denied or unknown).
     *
     * @param list<int> $ids
     * @return array{0: list<array<string, mixed>>, 1: list<array{document: int, name: string}>, 2: int}
     */
    private function collect(array $ids): array
    {
        $entries = [];
        $skipped = [];
        $denied  = 0;
        foreach ($ids as $id) {
            if (!$this->mayRead($id)) {
                $denied++;
                continue;
            }
            $doc = ($this->document)($id);
            if ($doc === null) {
                $denied++;
                continue;
            }

            $path = $this->blob->path($id, BlobStorage::ORIGINAL, $doc->getExt());
            if ($path === null) {
                $skipped[] = ['document' => $id, 'name' => $doc->getOriginalName()];
                continue;
            }

            clearstatcache(true, $path);
            $entries[] = [
                'document' => $id,
                'ext'      => $doc->getExt(),
                'name'     => $doc->getOriginalName(),
                'size'     => (int) filesize($path),
                'checksum' => $doc->getChecksum(),
                'mtime'    => (int) filemtime($path),
                'crc'      => null,
            ];
        }

        return [$entries, $skipped, $denied];
    }

    /**
     * Take the bundle for this pass, read fresh under the lock: null when it is no longer waiting
     * or somebody's lease still runs (`$busy`). A lease that ran out is adopted, cursor and all.
     *
     * @return array<string, mixed>|null
     */
    private function claim(string $id, string $token, bool &$busy): ?array
    {
        $claimed = null;
        $now     = $this->now();
        $this->update($id, static function (?array $m) use (&$claimed, &$busy, $token, $now): ?array {
            if ($m === null || $m['state'] !== self::STATE_PREPARING) {
                return null;
            }
            if (self::leaseIsLive($m['lease'] ?? null, $now)) {
                $busy = true;
                return null;
            }
            $m['lease'] = ['token' => $token, 'until' => $now + self::LEASE_SECONDS];
            $claimed = $m;
            return $m;
        });

        return $claimed;
    }

    /**
     * Hash on from the cursor until the bundle is done or the budget is spent, as the holder of
     * `$token`. Returns the state reached; on `preparing` the cursor in the manifest says where the
     * next process goes on; {@see STATE_LOST} when the bundle turned out not to be this holder's
     * any more, which is no failure and writes nothing. Any failure of THIS bundle ends it as
     * failed, and the job moves on to the next. The mail comes after and outside: what goes wrong
     * with it has nothing to do with whether the bundle is ready.
     *
     * @param array<string, mixed> $m
     */
    private function advance(string $id, array $m, \Closure $hasTime, string $token): string
    {
        try {
            $state = $this->hashOn($id, $m, $hasTime, $token);
        } catch (\Throwable $e) {
            error_log("[dms] zip bundle {$id}: " . $e->getMessage());
            $state = $this->fail($id, $m, $token, 'Das ZIP konnte nicht erstellt werden.');
        }
        if ($state === self::STATE_READY || $state === self::STATE_FAILED) {
            $this->sendPendingMail($id);
        }

        return $state;
    }

    /** @param array<string, mixed> $m */
    private function hashOn(string $id, array &$m, \Closure $hasTime, string $token): string
    {
        $count  = count($m['entries']);
        $file   = (int) ($m['cursor']['file'] ?? 0);
        $offset = (int) ($m['cursor']['offset'] ?? 0);
        $ctx    = self::restoreContext($m['cursor']['hash'] ?? null);
        if ($file < 0 || $file > $count) {
            $file = 0;
            $ctx  = null;
            foreach (array_keys($m['entries']) as $i) {
                $m['entries'][$i]['crc'] = null;
            }
        }
        if ($ctx === null || $offset < 0 || ($file < $count && $offset > (int) $m['entries'][$file]['size'])) {
            $ctx    = hash_init(self::ALGO); // no readable state: this file starts over
            $offset = 0;
        }

        for (; $file < $count; $file++) {
            $entry = $m['entries'][$file];
            $why   = $this->unchanged($entry);
            if ($why !== null) {
                return $this->fail($id, $m, $token, $why);
            }

            $in = $entry['size'] === 0 ? null : @fopen((string) $this->blob->path((int) $entry['document'], BlobStorage::ORIGINAL, (string) $entry['ext']), 'rb');
            if ($in === false) {
                return $this->fail($id, $m, $token, 'Eine Datei konnte nicht gelesen werden.');
            }

            try {
                if ($in !== null && fseek($in, $offset) !== 0) {
                    return $this->fail($id, $m, $token, 'Eine Datei konnte nicht gelesen werden.');
                }
                while ($offset < (int) $entry['size']) {
                    if (!$hasTime()) {
                        // The slice ends: the lease goes back, the next pass takes over at once.
                        return $this->checkpoint($id, $m, $file, $offset, $ctx, $token, true) ? self::STATE_PREPARING : self::STATE_LOST;
                    }
                    $want = min($this->stepBytes, (int) $entry['size'] - $offset);
                    if (hash_update_stream($ctx, $in, $want) !== $want) {
                        return $this->fail($id, $m, $token, 'Eine Datei konnte nicht gelesen werden.');
                    }
                    $offset += $want;
                    if (!$this->checkpoint($id, $m, $file, $offset, $ctx, $token)) {
                        return self::STATE_LOST;
                    }
                }
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }

            $m['entries'][$file]['crc'] = (int) hexdec(hash_final($ctx));
            $ctx    = hash_init(self::ALGO);
            $offset = 0;
            if (!$this->checkpoint($id, $m, $file + 1, 0, $ctx, $token)) {
                return self::STATE_LOST;
            }
        }

        $now     = $this->now();
        $entries = $m['entries'];
        $expires = $now + $this->keepDays * 86400;
        $done    = $this->asHolder($id, $token, $m['cursor'], static function (array $current) use ($entries, $count, $now, $expires): array {
            $current['entries'] = $entries;
            $current['state']   = self::STATE_READY;
            $current['ready']   = $now;
            $current['expires'] = $expires;
            $current['lease']   = null;
            $current['cursor']  = ['file' => $count, 'offset' => 0, 'hash' => null];
            return $current;
        });

        return $done ? self::STATE_READY : self::STATE_LOST;
    }

    /**
     * Save the progress so far. Only what the holder itself changes is written, into the manifest
     * as it stands now.
     *
     * @param array<string, mixed> $m
     */
    private function checkpoint(string $id, array &$m, int $file, int $offset, \HashContext $ctx, string $token, bool $release = false): bool
    {
        $entries = $m['entries'];
        $cursor  = ['file' => $file, 'offset' => $offset, 'hash' => base64_encode(serialize($ctx))];
        $lease   = $release ? null : ['token' => $token, 'until' => $this->now() + self::LEASE_SECONDS];
        $ok      = $this->asHolder($id, $token, $m['cursor'], static function (array $current) use ($entries, $cursor, $lease): array {
            $current['entries'] = $entries;
            $current['cursor']  = $cursor;
            $current['lease']   = $lease;
            return $current;
        });
        if ($ok) {
            $m['cursor'] = $cursor;
        }

        return $ok;
    }

    /**
     * One write of the bundle's preparer. It re-reads the manifest under the lock and gives up
     * unless the bundle is still `preparing`, still under this token and at the progress this
     * holder last wrote: nothing a pass knows from earlier can overwrite what happened since.
     *
     * @param array<string, mixed> $expected the cursor as this holder last saw it
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private function asHolder(string $id, string $token, array $expected, \Closure $change): bool
    {
        $ok = false;
        $this->update($id, static function (?array $current) use ($token, $expected, $change, &$ok): ?array {
            if ($current === null
                || $current['state'] !== self::STATE_PREPARING
                || !hash_equals($token, (string) ($current['lease']['token'] ?? ''))
                || (int) ($current['cursor']['file'] ?? -1) !== (int) ($expected['file'] ?? -2)
                || (int) ($current['cursor']['offset'] ?? -1) !== (int) ($expected['offset'] ?? -2)) {
                return null;
            }
            $ok = true;
            return $change($current);
        });

        return $ok;
    }

    /**
     * Why a document stopped being what was counted, or null. Before each file: bytes hashed for
     * a document that changed in between would only surface as a refusal at the first piece.
     *
     * @param array<string, mixed> $entry
     */
    private function unchanged(array $entry): ?string
    {
        $doc = ($this->document)((int) $entry['document']);
        if ($doc === null) {
            return 'Ein Dokument wurde inzwischen gelöscht. Das ZIP muss neu erstellt werden.';
        }
        if (!hash_equals((string) $entry['checksum'], $doc->getChecksum())
            || $this->blob->size((int) $entry['document'], BlobStorage::ORIGINAL, (string) $entry['ext']) !== (int) $entry['size']) {
            return 'Ein Dokument wurde inzwischen geändert. Das ZIP muss neu erstellt werden.';
        }

        return null;
    }

    /**
     * The entries a request must clear: all of them for the first piece and for an answer of
     * the whole (the HEAD, a request without `Range`), else what the range touches. An
     * unsatisfiable range answers no byte, so it clears nothing beyond owner and expiry.
     *
     * @param array{0: int, 1: int}|false|null $range
     * @return list<int>
     */
    private function gatedEntries(ZipLayout $layout, array|false|null $range): array
    {
        if ($range === false) {
            return [];
        }
        if ($range === null || $range[0] === 0) {
            return $layout->touched(0, $layout->size() - 1);
        }

        return $layout->touched($range[0], $range[1]);
    }

    /**
     * The gate for one document, per piece. `read` comes first, so that a person who lost it
     * learns nothing about the document; a name is only spoken behind it.
     *
     * @param array<string, mixed> $entry
     */
    private function gate(array $entry): void
    {
        $id = (int) $entry['document'];
        if (!$this->mayRead($id)) {
            throw new ZipBundleException('Kein Zugriff mehr auf ein Dokument in diesem ZIP.', 403);
        }

        $doc = ($this->document)($id);
        if ($doc === null) {
            throw new ZipBundleException('Ein Dokument in diesem ZIP wurde gelöscht. Das ZIP muss neu erstellt werden.', 409);
        }
        if (!hash_equals((string) $entry['checksum'], $doc->getChecksum())
            || $this->blob->size($id, BlobStorage::ORIGINAL, (string) $entry['ext']) !== (int) $entry['size']) {
            throw new ZipBundleException('«' . $entry['name'] . '» wurde geändert. Das ZIP muss neu erstellt werden.', 409);
        }
    }

    /**
     * `read` for the session, then the host's own gate. A gate that throws or does not answer
     * exactly `true` is a no: this decides who gets bytes, so it fails closed.
     */
    private function mayRead(int $id): bool
    {
        if (!($this->canRead)($id)) {
            return false;
        }
        if ($this->documentGate === null) {
            return true;
        }
        try {
            return ($this->documentGate)($id) === true;
        } catch (\Throwable $e) {
            error_log('[dms] zipDocumentGateHook failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tell the host once that this bundle's download began. The claim in the manifest comes
     * first and under the lock, so retries and concurrent first pieces cannot call it twice; a
     * crash between claim and call means it was not called, which is the side a counter
     * tolerates. A failing hook is logged and changes nothing about the download. Neither does a
     * claim that cannot be saved: a read does not fail for a record, and the hook does not fire
     * then, because once-only could not be promised. The next piece tries again.
     *
     * @param array<string, mixed> $m
     */
    private function announceDownload(string $id, array $m): void
    {
        if ($this->downloadStarted === null || isset($m['started'])) {
            return;
        }

        $claimed = false;
        $now     = $this->now();
        try {
            $this->update($id, static function (?array $current) use (&$claimed, $now): ?array {
                if ($current === null || isset($current['started'])) {
                    return null;
                }
                $current['started'] = $now;
                $claimed = true;
                return $current;
            });
        } catch (\Throwable $e) {
            error_log("[dms] zip bundle {$id}: the started claim could not be saved, the hook did not fire — " . $e->getMessage());
            return;
        }
        if (!$claimed) {
            return;
        }

        try {
            ($this->downloadStarted)($id, array_map('intval', array_column($m['entries'], 'document')));
        } catch (\Throwable $e) {
            error_log('[dms] zipDownloadStartedHook failed: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $m a ready manifest: every entry has its CRC */
    private function layout(array $m): ZipLayout
    {
        return new ZipLayout(array_map(fn (array $e): array => [
            'name'  => $e['name'],
            'path'  => (string) $this->blob->path((int) $e['document'], BlobStorage::ORIGINAL, (string) $e['ext']),
            'size'  => (int) $e['size'],
            'crc'   => (int) $e['crc'],
            'mtime' => (int) $e['mtime'],
        ], $m['entries']));
    }

    /**
     * Stable for a bundle, different for another selection or another version of a document:
     * `chunk-download.js` stops a download when the tag moves between pieces.
     *
     * @param array<string, mixed> $m
     */
    private static function etag(array $m): string
    {
        return 'zip-' . substr(hash('sha256', $m['id'] . '|' . implode(',', array_column($m['entries'], 'checksum'))), 0, 32);
    }

    /**
     * Send the mail if one waits: «ready», or «failed» for a bundle that ended that way, whoever
     * asked for a mail is not watching a screen. Claimed under the lock first, so that it goes
     * out once: a crash between send and record leaves `sending`, which is recorded as failed and
     * not repeated. A failure is logged and recorded, the bundle stays as it is. The address leaves
     * the manifest with this step: it was kept only because a job has no session to ask. A state
     * that cannot be written is logged and ends here: it says nothing about the bundle.
     */
    private function sendPendingMail(string $id): void
    {
        try {
            $this->claimAndSendMail($id);
        } catch (\Throwable $e) {
            error_log("[dms] zip bundle {$id}: the mail's state could not be written — " . $e->getMessage());
        }
    }

    private function claimAndSendMail(string $id): void
    {
        $claimed = null;
        $now     = $this->now();
        $this->update($id, static function (?array $m) use (&$claimed, $now): ?array {
            $state = (string) ($m['mail']['state'] ?? self::MAIL_NONE);
            if ($state === self::MAIL_SENDING) {
                $m['mail'] = ['state' => self::MAIL_FAILED, 'at' => $now, 'error' => 'Der Versand wurde unterbrochen.'];
                return $m;
            }
            if ($state !== self::MAIL_PENDING) {
                return null;
            }
            $claimed = $m;
            $m['mail']['state'] = self::MAIL_SENDING;
            return $m;
        });
        if ($claimed === null) {
            return;
        }

        $cause = '';
        try {
            $sent = (bool) ($this->mail)((string) ($claimed['mail']['to'] ?? ''), [
                'kind'      => $claimed['state'] === self::STATE_FAILED ? self::MAIL_KIND_FAILED : self::MAIL_KIND_READY,
                'documents' => count($claimed['entries']),
                'bytes'     => array_sum(array_column($claimed['entries'], 'size')),
                'expires'   => (int) $claimed['expires'],
                'reason'    => (string) ($claimed['error'] ?? ''),
                'profile'   => (string) ($claimed['mailProfile'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $sent  = false;
            $cause = $e->getMessage();
        }
        if (!$sent) {
            error_log("[dms] zip bundle {$id}: the mail failed" . ($cause !== '' ? ' — ' . $cause : ''));
        }

        $at = $this->now();
        $this->update($id, static function (?array $m) use ($sent, $at): ?array {
            if ($m === null) {
                return null;
            }
            $m['mail'] = $sent
                ? ['state' => self::MAIL_SENT, 'at' => $at]
                : ['state' => self::MAIL_FAILED, 'at' => $at, 'error' => 'Die E-Mail konnte nicht gesendet werden.'];
            return $m;
        });
    }

    /**
     * End a bundle as failed, as the holder of its lease. A mail that was asked for stays pending:
     * the caller sends it. A manifest that cannot be written leaves the bundle `preparing`, its
     * lease runs out and the job tries again: this may run inside an error handler and never throws.
     *
     * @param array<string, mixed> $m
     */
    private function fail(string $id, array $m, string $token, string $message): string
    {
        try {
            $failed = $this->asHolder($id, $token, $m['cursor'], static function (array $current) use ($message): array {
                $current['state']  = self::STATE_FAILED;
                $current['error']  = $message;
                $current['lease']  = null;
                $current['cursor'] = ['file' => 0, 'offset' => 0, 'hash' => null];
                return $current;
            });
        } catch (\Throwable $e) {
            error_log("[dms] zip bundle {$id}: could not record its failure — " . $e->getMessage());
            return self::STATE_PREPARING;
        }

        return $failed ? self::STATE_FAILED : self::STATE_LOST;
    }

    /**
     * The hash state back from the manifest: only ever a `HashContext`, whatever else the bytes
     * claim to be, and null for anything that does not read as one.
     */
    private static function restoreContext(mixed $encoded): ?\HashContext
    {
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }
        try {
            $ctx = @unserialize($raw, ['allowed_classes' => [\HashContext::class]]);
        } catch (\Throwable) {
            return null;
        }

        return $ctx instanceof \HashContext ? $ctx : null;
    }

    /**
     * A manifest for the owner of the session: unknown id, nothing readable, expired, another
     * person — all the same «not found».
     *
     * @return array<string, mixed>
     */
    private function owned(string $id): array
    {
        $owner = ($this->owner)();
        $m     = $this->read($id);
        if ($m === null || $owner === '' || !hash_equals((string) $m['owner'], hash('sha256', $owner)) || (int) $m['expires'] < $this->now()) {
            throw new ZipBundleException('ZIP nicht gefunden.', 404);
        }

        return $m;
    }

    /** @return array<string, mixed>|null the manifest under a shared lock, null when there is none */
    private function read(string $id): ?array
    {
        $lines = $this->underSharedLock($id, static fn (string $path): string => (string) @file_get_contents($path));
        if ($lines === null) {
            return null;
        }

        return self::decode($lines);
    }

    /**
     * Only the head line, without reading the rest: enough for every scan.
     *
     * @return array<string, mixed>|null null when there is no readable bundle
     */
    private function readHead(string $id): ?array
    {
        $line = $this->underSharedLock($id, static function (string $path): string {
            $h = @fopen($path, 'rb');
            if ($h === false) {
                return '';
            }
            $first = fgets($h);
            fclose($h);
            return (string) $first;
        });
        $head = $line === null ? null : json_decode($line, true);

        return is_array($head) && isset($head['owner'], $head['state'], $head['expires'], $head['created']) ? $head : null;
    }

    /**
     * The bundles one at a time, by id; null for a directory that holds no readable bundle.
     *
     * @return \Generator<string, array<string, mixed>|null>
     */
    private function heads(): \Generator
    {
        foreach ($this->bundleIds() as $id) {
            yield $id => $this->readHead($id);
        }
    }

    /** `$read(path)` with the bundle's lock held shared; null when there is no lock or no manifest. */
    private function underSharedLock(string $id, \Closure $read): ?string
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }
        $dir = $this->baseDir . '/' . $id;
        $h   = is_file($dir . '/' . self::LOCK) ? @fopen($dir . '/' . self::LOCK, 'rb') : false;
        if ($h === false) {
            return null;
        }

        try {
            if (!flock($h, LOCK_SH)) {
                return null;
            }
            $path = $dir . '/' . self::MANIFEST;
            clearstatcache(true, $path);
            $content = is_file($path) ? $read($path) : null;
            flock($h, LOCK_UN);

            return $content;
        } finally {
            fclose($h);
        }
    }

    /**
     * Read, change and write as one step under the exclusive lock: nothing that changes a bundle
     * may lose another process's change or be claimed twice. `$change` answers the new manifest
     * or null for no change; its argument is null when the file does not read as a bundle.
     *
     * @param \Closure(?array<string, mixed>): ?array<string, mixed> $change
     * @return bool false when the bundle is gone
     * @throws \RuntimeException the manifest could not be replaced; the old one stands
     */
    private function update(string $id, \Closure $change): bool
    {
        $dir = $this->baseDir . '/' . $id;
        $h   = is_file($dir . '/' . self::LOCK) ? @fopen($dir . '/' . self::LOCK, 'r+b') : false;
        if ($h === false) {
            return false;
        }

        try {
            if (!flock($h, LOCK_EX)) {
                throw new \RuntimeException('ZipBundleService: cannot lock the manifest.');
            }
            $path = $dir . '/' . self::MANIFEST;
            clearstatcache(true, $path);
            if (!is_file($path)) {
                return false;
            }
            $new = $change(self::decode((string) @file_get_contents($path)));
            if ($new !== null && !$this->replace($dir, $new)) {
                throw new \RuntimeException('ZipBundleService: cannot write the manifest.');
            }
            flock($h, LOCK_UN);

            return true;
        } finally {
            fclose($h);
        }
    }

    /**
     * Temp file and rename, so that a reader or a crash finds the old manifest or the new one
     * and never half of one. Under the bundle's lock, a separate file: a lock on the manifest
     * itself would stay with the file a rename just replaced.
     *
     * @param array<string, mixed> $m
     */
    private function replace(string $dir, array $m): bool
    {
        $json = self::encode($m);
        $temp = $dir . '/' . self::TEMP;
        if (is_dir($temp) || @file_put_contents($temp, $json) !== strlen($json)) {
            return false;
        }
        if (!@rename($temp, $dir . '/' . self::MANIFEST)) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    /**
     * Remove every bundle whose head the test says yes to. Waits for a writer that holds the
     * lock: a purge must not leave anything behind because something was busy.
     *
     * @param \Closure(array<string, mixed>): bool $matches
     * @return int removed bundles
     */
    private function deleteWhere(\Closure $matches): int
    {
        $removed = 0;
        foreach ($this->heads() as $id => $head) {
            // A manifest whose lock went away is no bundle to anyone else, and still holds names.
            $head ??= $this->headWithoutLock($id);
            if ($head === null || !$matches($head)) {
                continue;
            }
            if ($this->removeBundle($id)) {
                $removed++;
            } else {
                error_log("[dms] zip bundle {$id}: could not be removed by a purge.");
            }
        }

        return $removed;
    }

    /**
     * The head of a manifest that has no lock file, read for the purges alone.
     *
     * @return array<string, mixed>|null
     */
    private function headWithoutLock(string $id): ?array
    {
        $h = @fopen($this->baseDir . '/' . $id . '/' . self::MANIFEST, 'rb');
        if ($h === false) {
            return null;
        }
        $line = fgets($h);
        fclose($h);
        $head = json_decode((string) $line, true);

        return is_array($head) && isset($head['owner']) ? $head : null;
    }

    /** Wait for whoever holds the lock, then remove. */
    private function removeBundle(string $id): bool
    {
        $this->update($id, static fn (): ?array => null);

        return $this->removeDirPatiently($this->baseDir . '/' . $id);
    }

    /** With a moment's retry for a file still open elsewhere. */
    private function removeDirPatiently(string $dir): bool
    {
        for ($try = 0; $try < 5; $try++) {
            $this->removeDir($dir);
            if (!is_dir($dir)) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    /**
     * Two JSON lines: the head, which every scan reads alone, and the rest. The head never holds a
     * name or an address: `documents` are ids, of the skipped ones too, and `mailState` only says
     * whether a job has a mail to send.
     *
     * @param array<string, mixed> $m
     */
    private static function encode(array $m): string
    {
        $head = array_intersect_key($m, array_flip(self::HEAD_FIELDS));
        $head['mailState'] = (string) ($m['mail']['state'] ?? self::MAIL_NONE);
        $head['documents'] = array_map(
            static fn (array $entry): int => (int) ($entry['document'] ?? 0),
            array_merge((array) ($m['entries'] ?? []), (array) ($m['skipped'] ?? []))
        );
        $body = array_diff_key($m, array_flip(self::HEAD_FIELDS));
        $json = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        return json_encode($head, $json) . "\n" . json_encode($body, $json);
    }

    /** @return array<string, mixed>|null null when these bytes are no bundle */
    private static function decode(string $bytes): ?array
    {
        $lines = explode("\n", $bytes, 2);
        $head  = json_decode($lines[0], true);
        $body  = json_decode($lines[1] ?? '', true);
        if (!is_array($head) || !is_array($body)) {
            return null;
        }
        unset($head['mailState'], $head['documents']);
        $m = $body + $head;

        return isset($m['owner'], $m['entries'], $m['state'], $m['expires'], $m['name']) && is_array($m['entries']) ? $m : null;
    }

    /**
     * A bundle in preparation with nobody working on it, and possibly no entry in the queue. It may
     * also just be waiting its turn: {@see requestJob()} does nothing then, the queue keeps one
     * waiting entry.
     */
    private static function isStranded(array $headOrManifest, int $now): bool
    {
        return $headOrManifest['state'] === self::STATE_PREPARING
            && !self::leaseIsLive($headOrManifest['lease'] ?? null, $now);
    }

    /**
     * Somebody holds it right now. A lease reaching further ahead than a lease can was written by
     * a clock that was ahead, and would stop everybody for as long: it counts as run out.
     */
    private static function leaseIsLive(mixed $lease, int $now): bool
    {
        $until = is_array($lease) ? (int) ($lease['until'] ?? 0) : 0;

        return $until >= $now && $until <= $now + self::LEASE_SECONDS;
    }

    /** Queue the job for what lies stranded. Only a nudge: what goes wrong here stops nobody's request. */
    private function requestJob(): void
    {
        try {
            ($this->enqueue)();
        } catch (\Throwable $e) {
            error_log('[dms] zip bundle: the job could not be queued — ' . $e->getMessage());
        }
    }

    /**
     * The manifest as a client may see it: no owner, no address, no hash state. A skipped
     * document's name only while it may be read right now, otherwise it joins the count of what
     * tells nothing: rights may have gone since the bundle was opened.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function view(array $m): array
    {
        $bytes = 0;
        $done  = 0;
        foreach ($m['entries'] as $entry) {
            $bytes += (int) $entry['size'];
            if ($entry['crc'] !== null) {
                $done += (int) $entry['size'];
            }
        }
        if ($m['state'] === self::STATE_PREPARING) {
            $done += (int) ($m['cursor']['offset'] ?? 0);
        }

        $skipped = [];
        $denied  = (int) ($m['denied'] ?? 0);
        foreach ((array) ($m['skipped'] ?? []) as $entry) {
            $document = (int) ($entry['document'] ?? 0);
            if ($this->mayRead($document) && ($this->document)($document) !== null) {
                $skipped[] = $entry;
            } else {
                $denied++;
            }
        }

        return [
            'id'        => (string) $m['id'],
            'name'      => (string) $m['name'],
            'state'     => (string) $m['state'],
            'documents' => count($m['entries']),
            'bytes'     => $bytes,
            'done'      => $done,
            'created'   => (int) $m['created'],
            'expires'   => (int) $m['expires'],
            'skipped'   => $skipped,
            'denied'    => $denied,
            'mail'      => array_diff_key((array) ($m['mail'] ?? ['state' => self::MAIL_NONE]), ['to' => true]),
            'error'     => $m['error'] ?? null,
        ];
    }

    /** The name a browser saves: the person's, cleaned, or today's; always `.zip`. */
    private static function bundleName(string $name, int $now): string
    {
        $name = ZipLayout::cleanName($name);
        if (str_ends_with(strtolower($name), '.zip')) {
            $name = rtrim(substr($name, 0, -4), '. ');
        }

        return ($name !== '' ? $name : 'dokumente-' . date('Y-m-d', $now)) . '.zip';
    }

    /** @return list<string> the bundle directories; anything else in there is not ours */
    private function bundleIds(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($this->baseDir) ?: [] as $entry) {
            if (preg_match(self::ID, $entry) === 1 && is_dir($this->baseDir . '/' . $entry)) {
                $ids[] = $entry;
            }
        }

        return $ids;
    }

    private function now(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }

    private function ensureBaseDir(): void
    {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException("ZipBundleService: cannot create '{$this->baseDir}'.");
        }
    }

    /** A bundle directory holds only flat files (manifest, lock, at most a temp file). */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
