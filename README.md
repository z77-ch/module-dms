# z77/module-dms

The document management module of z77: one storage for every file of an installation —
uploads, website images, generated documents — organised in a folder tree, gated by
ownership + ACL, and delivered through PHP (range-capable) or, for public content, as a lazily
materialized static copy under `public/media`.

Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not
commit here — issues and pull requests go to the monorepo.

## What it does

- **Two worlds:** metadata (folders, documents, ACL entries) in the entity layer; bytes in a
  separate, id-addressed `BlobStorage` (`data/blobs/<shard>/<id>/<variant>.<ext>`, outside
  `public/`). Moving or renaming is metadata only.
- **One tree:** a single drive root, its children are the *partitions* — created by hand
  (super-user) or by a module through its scoped handle `DocumentService::forModule()` →
  `ModuleDrive`.
- **Access:** ownership + ACL (`user|role` subjects, rights `read < write < manage`), inherited
  down the tree; only `superUser` bypasses it. Every mutation and every per-id read is gated in
  the domain (`Authz`), not in the controller.
- **Delivery modes** (inherited): `protected` (login + read right), `public` (served openly
  under `/media/<partition>/<folder…>/<file>`, copied lazily to `public/media` on the first
  hit), `sealed` (never public, caps everything below).
- **Uploads:** allowlist on a server-side `finfo` sniff (`DocumentKind`, plus per-installation
  `extraUploadTypes`), duplicate skip, name-conflict prompt with guarded overwrite, chunked
  upload for files bigger than one request (`ChunkedUploadService`), image derivatives per
  folder-assigned, project-defined image profile (GD).
- **Downloads:** range-capable `FileResponse`; large files come down in explicit pieces
  (`documents/chunk-download.js` + service worker); a ZIP of many documents can be prepared by a
  background job and served piecewise (`ZipBundleService`, for project pages).
- **Soft delete + trash** with restore, purge and an activity hook (`Services\ActivityLog`) for
  every change. A legal **retention deadline** (`retentionUntil`, a date that covers its whole
  last day — `Document::isRetained()`) blocks the purge and an overwrite; a module sets it on its
  own documents through `ModuleDrive::retainUntil()` (only extends — e.g. `module-receipt`: a
  posted receipt's photo, 10 years after the end of its fiscal year).
- **Template helpers:** `mediaUrl()` / `mediaImage()` for public images by slug path.

## Screens and routes

| Where | What |
|---|---|
| Backend «Drive › Dokumente» — `/backend/documents/drive/list` | The Drive: folder tree, file list, preview; upload, folders, edit dialog (name, key, image profile, alt/caption, delivery mode, ACL), move, bulk select, trash. Mounted by `module-backend` (`DriveController` uses `Ui\DriveControllerTrait`). Help: «Hilfe» in the toolbar. |
| Backend media field + picker — `/backend/documents/drive/pick` | `partials/mediaField` for any backend form that stores a DMS document id; the picker window with upload (`Ui\MediaPickerTrait`). |
| `/media/…` (public, reserved route) | `Ui\Controllers\Media\OutputController::serveAction` — resolves the structural path, gates by delivery mode, 404 on any miss or denial. |
| Project pages (opt-in) | `Ui\ChunkedUploadTrait`, `Ui\ZipBundleTrait`, `Ui\DocumentDeliveryTrait` to mount upload / ZIP / delivery in a project's own controller (e.g. a member area). |

Background jobs (`dmsConfig['jobs']`): `dms-zip-prepare`, `dms-zip-cleanup` (scheduled),
`dms-chunk-cleanup` (offered, switched on by the operator).

## Requirements

- PHP ≥ 8.2 with `ext-gd`; `z77/kernel` ^1.0.
- The PHP upload limits (`upload_max_filesize`, `post_max_size`) are the upload ceiling — there
  is no second app setting. Larger files go up in chunks.

## Enabling it in a project

Start a project from the [z77-skeleton](https://github.com/z77-ch/z77-skeleton) template, which
already requires this package. In an existing z77 project: `composer require z77/module-dms`;
`composer install` registers the module (`config/vendor/moduleManager.inc.php`), the drive root
is seeded from `data/documents/folders.default.json`, and the navigation seed adds
«Drive › Dokumente». Project settings go into the override
`override/z77/module/dms/src/App/Config/dmsConfig.inc.php` (and `imageProfilesConfig.inc.php`
for image sizes per partition) — never edit the package.

## Not built yet

- ZIP download of several documents **in the Drive** (the service exists and is used by project
  pages; the Drive surface is pending).
- Share links for people without a login (share materialization, ADR-017 R7) and supplier
  upload.
- Sending a stored document by e-mail from the Drive (`DocumentService::send()` has no UI).
- A second Drive host (frontend / member area) with the full Drive.
- Re-generating image derivatives after a profile change (variants are made at upload time).

## Documentation

- Topic: [`docs/topics/documents.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/documents.md),
  styles: [`docs/topics/css-dms.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/css-dms.md)
- Decisions: ADR-016 (storage and delivery), ADR-017 (ownership, ACL, delivery modes), ADR-020
  (scope is the tree), ADR-021 (drive root, super-user governance) in
  [`docs/02-decisions/`](https://github.com/z77-ch/z77-framework/tree/main/docs/02-decisions)

## License

MIT — see the monorepo.
