# z77/module-dms

The document management module (ADR-017). Owns the user-facing DMS surfaces and the
authorized byte delivery. Built incrementally by the DMS rebuild plan
(`docs/03-development/dms-umbauplan.md`).

## Current scope (R4c)

- **`OutputController`** (`Ui/Controllers/Media/OutputController.php`) — the GUEST output
  endpoint behind the `/media` reserved route. It resolves a structural
  `/media/{area}/{folder…}/{file}` path to a document + variant
  (`DocumentService::resolve`), branches on the effective `deliveryMode`
  (`public` = open; `protected`/`sealed` = `AclService::canRead` ACL + active gate **before**
  any byte), and returns a `FileResponse` (portable PHP range-stream) or **404** — existence
  is never leaked.

The `/media` reserved route is registered in `App/Config/dmsConfig.inc.php` and replaces the
former `MediaController` + `/media` NavigationAlias.

## Planned (later phases)

- Logged-in user / admin "drive" management surface (R6).
- Share controller + supplier upload + API auth (R7).
