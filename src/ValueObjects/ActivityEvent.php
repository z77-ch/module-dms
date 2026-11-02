<?php

namespace Z77\Module\Dms\ValueObjects;

/**
 * One file action, as the DMS tells it to a project's `dmsConfig['activityHook']`
 * (DMS-ACTIVITY-001, {@see \Z77\Module\Dms\Services\ActivityLog}). Ids only: no name, no path,
 * no content, so a record cannot leak what a document is called or holds. The project looks up
 * what it wants to show, and may keep the record of something that is gone.
 *
 *   action         one of the constants below
 *   documentId     the document; null for a folder action
 *   folderId       a document action: the folder the document is in (a move: the target).
 *                  A folder action: the folder itself
 *   fromFolderId   a move only: the document's source folder, or the folder's former parent
 *   parentFolderId a folder action: the folder's parent, after the action (a delete: the one it
 *                  had); null for a document action
 *   actorKey       `realm:id` as {@see \Z77\Module\Dms\Services\ZipBundleService::ownerKey()};
 *                  '' without a session: a job, an import, a call from code
 */
final class ActivityEvent
{
    public const DOCUMENT_UPLOADED    = 'document.uploaded';
    public const DOCUMENT_OVERWRITTEN = 'document.overwritten';
    public const DOCUMENT_GENERATED   = 'document.generated';
    public const DOCUMENT_DOWNLOADED  = 'document.downloaded';
    public const DOCUMENT_OPENED      = 'document.opened';
    public const DOCUMENT_DELETED     = 'document.deleted';
    public const DOCUMENT_RESTORED    = 'document.restored';
    public const DOCUMENT_PURGED      = 'document.purged';
    public const DOCUMENT_MOVED       = 'document.moved';
    public const DOCUMENT_RENAMED     = 'document.renamed';

    public const FOLDER_CREATED = 'folder.created';
    public const FOLDER_RENAMED = 'folder.renamed';
    public const FOLDER_MOVED   = 'folder.moved';
    public const FOLDER_DELETED = 'folder.deleted';

    public function __construct(
        public readonly string $action,
        public readonly ?int $documentId,
        public readonly ?int $folderId,
        public readonly ?int $fromFolderId,
        public readonly ?int $parentFolderId,
        public readonly string $actorKey,
    ) {}
}
