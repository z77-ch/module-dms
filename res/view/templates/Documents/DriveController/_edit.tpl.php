<?php
/**
 * DMS Drive — combined edit modal (2026-07-03, replaces the separate rename / delivery-mode /
 * ACL modals). One surface per resource: the form fields (name; folder: + `key` when editable;
 * delivery mode) post `op=save` back to the source URL; the embedded ACL section posts
 * `op=grant`/`op=revoke` and the response re-renders this whole modal in place (popup
 * re-mount), so rules are managed without closing. The lock flags are PRESENTATION — the
 * domain gates (`FolderService`/`DocumentService`, ADR-021) are authoritative.
 *
 * @var 'document'|'folder' $type
 * @var int     $id
 * @var string  $name
 * @var ?string $originalName   document only (uploaded file name)
 * @var ?string $ownMode        own delivery mode (null = inherit)
 * @var string  $effectiveMode  effective mode (inheritance + sealed cap applied)
 * @var bool    $sealedAbove    a sealed ancestor caps this resource
 * @var bool    $nameLocked     drive root / partition lifecycle (SUPER_USER) / system partition
 * @var bool    $modeLocked     drive root (mode fixed null)
 * @var bool    $keyEditable    folder only: SUPER_USER + human partition
 * @var ?string $key            folder only: current key
 * @var ?string $ownProfile        folder only: own image-profile assignment (null = inherit)
 * @var ?string $effectiveProfile  folder only: effective profile (inheritance applied)
 * @var list<string> $profileOptions folder only: partition's project profiles ([] = hide the field)
 * @var list<array{subjectType:string, subjectId:string, rights:string}> $aces
 * @var string  $entityCsrf
 * @var bool         $isImage    document only: kind === image (shows the alt/caption fields)
 * @var list<string> $languages  document only: configured languages for the alt/caption inputs
 * @var array<string,string> $altMap      document only: current localized alt map
 * @var array<string,string> $captionMap  document only: current localized caption map
 */
$rightsList = ['read' => 'Lesen', 'write' => 'Schreiben', 'manage' => 'Verwalten'];
$modes = [
    ''          => 'Geerbt (vom übergeordneten Ordner)',
    'protected' => 'Geschützt — nur mit Login + Berechtigung',
    'public'    => 'Öffentlich — für alle abrufbar',
    'sealed'    => 'Versiegelt — verlässt nie den Tresor',
];
$own       = $ownMode ?? '';
$nameField = $type === 'folder' ? 'name' : 'display_name';
?>
<div class="dms-edit">
    <form data-fetch-post>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="op" value="save">
        <div class="be-modal__header">
            <h2 class="be-modal__title"><?= $type === 'folder' ? 'Ordner' : 'Dokument' ?> bearbeiten — «<?= e($name) ?>»</h2>
        </div>
        <div class="be-modal__body">
            <div class="be-form__grid" style="grid-template-columns:1fr">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label><?= $type === 'folder' ? 'Ordnername' : 'Anzeigename' ?></label>
                    <?php if ($nameLocked): ?>
                    <input type="hidden" name="<?= e($nameField) ?>" value="<?= e($name) ?>">
                    <input type="text" value="<?= e($name) ?>" disabled>
                    <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:.2rem 0 0">
                        Der Name ist gesperrt (System-Ordner oder Bereichs-Verwaltung — nur Super-User).
                    </p>
                    <?php else: ?>
                    <input type="text" name="<?= e($nameField) ?>" value="<?= e($name) ?>" required autocomplete="off" autofocus>
                    <?php endif; ?>
                </div>

                <?php if ($type === 'document' && $originalName !== null): ?>
                <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:0">
                    Originaldatei: <code><?= e($originalName) ?></code>
                </p>
                <?php endif; ?>

                <?php if ($type === 'folder' && $keyEditable): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Key <small>(stabile Adresse für Module / Nav-Einträge; leer = keiner)</small></label>
                    <input type="text" name="key" value="<?= e($key ?? '') ?>" autocomplete="off"
                           pattern="[a-z0-9][a-z0-9-]*" placeholder="z.B. financial">
                    <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:.2rem 0 0">
                        Nur Kleinbuchstaben/Ziffern/Bindestrich, muss eindeutig sein.
                    </p>
                </div>
                <?php elseif ($type === 'folder' && $key !== null): ?>
                <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:0">
                    Key: <code><?= e($key) ?></code> (fest — Modul-Adresse)
                </p>
                <?php endif; ?>

                <?php if ($type === 'folder' && ($profileOptions ?? []) !== []): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Bildprofil <small>(Bildgrössen für Uploads in diesem Ordner, vererbt an Unterordner)</small></label>
                    <select name="profile">
                        <option value="">Geerbt / keines<?= ($ownProfile ?? null) === null && ($effectiveProfile ?? null) !== null ? ' — effektiv: ' . e($effectiveProfile) : '' ?></option>
                        <?php foreach (($profileOptions ?? []) as $p): ?>
                        <option value="<?= e($p) ?>"<?= ($ownProfile ?? null) === $p ? ' selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:.2rem 0 0">
                        Ohne Zuordnung greift das Profil «default» des Bereichs (falls definiert), sonst nur die Standard-Grössen.
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($type === 'document' && ($isImage ?? false) && ($languages ?? [])): ?>
            <hr style="border:none;border-top:1px solid var(--be-border,#334155);margin:.75rem 0">
            <p style="font-size:.85rem;margin:0 0 .4rem"><strong>Bildtexte</strong>
                <span style="color:var(--be-muted,#94a3b8)">— Alt (Barrierefreiheit/SEO) &amp; Bildunterschrift, je Sprache</span></p>
            <div class="be-form__grid" style="grid-template-columns:1fr">
                <?php foreach (($languages ?? []) as $lang): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Alt-Text <small>(<?= e(strtoupper($lang)) ?>)</small></label>
                    <input type="text" name="alt_<?= e($lang) ?>" value="<?= e($altMap[$lang] ?? '') ?>" autocomplete="off">
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label>Bildunterschrift <small>(<?= e(strtoupper($lang)) ?>)</small></label>
                    <input type="text" name="caption_<?= e($lang) ?>" value="<?= e($captionMap[$lang] ?? '') ?>" autocomplete="off">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$modeLocked): ?>
            <hr style="border:none;border-top:1px solid var(--be-border,#334155);margin:.75rem 0">
            <p style="font-size:.85rem;margin:0 0 .4rem"><strong>Auslieferungs-Modus</strong>
                <span style="color:var(--be-muted,#94a3b8)">— effektiv aktuell: <?= e($effectiveMode) ?></span></p>
            <?php if ($sealedAbove): ?>
            <div class="be-modal__alert be-modal__alert--error">
                Ein übergeordneter Ordner ist <strong>versiegelt</strong> — offenere Modi sind gesperrt.
            </div>
            <?php endif; ?>
            <div style="display:flex;flex-direction:column;gap:.35rem">
                <?php foreach ($modes as $val => $label):
                    $disabled = $sealedAbove && ($val === 'protected' || $val === 'public'); ?>
                <label class="be-choice be-choice--filled"<?= $disabled ? ' style="opacity:.5"' : '' ?>>
                    <input type="radio" class="be-choice__input" name="mode" value="<?= e($val) ?>"<?= $own === $val ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
                    <span class="be-choice__label"><?= e($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:.5rem 0 0">
                Auslieferungs-Modus: fest (Vererbungs-Standard des Drive-Root).
            </p>
            <?php endif; ?>
        </div>
        <div class="be-modal__footer">
            <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
            <button type="submit" class="be-btn be-btn--primary">Speichern</button>
        </div>
    </form>

    <div class="be-modal__body">
        <p style="font-size:.85rem;margin:0 0 .4rem"><strong>Zugriffsrechte</strong></p>
        <?php if ($effectiveMode !== 'protected'): ?>
        <div class="be-modal__alert">
            Regeln greifen nur im Modus «protected» (aktuell: <strong><?= e($effectiveMode) ?></strong>).
            Super-User/Besitzer haben immer Zugriff.
        </div>
        <?php endif; ?>

        <div class="dms-acl__list" style="display:flex;flex-direction:column;gap:.35rem;margin:.5rem 0">
            <?php if ($aces === []): ?>
            <p style="font-size:.85rem;color:var(--be-muted,#94a3b8);margin:0">Noch keine Regeln.</p>
            <?php else: foreach ($aces as $a): ?>
            <div class="dms-acl__row" style="display:flex;align-items:center;gap:.5rem">
                <span style="flex:1"><?= $a['subjectType'] === 'role' ? 'Rolle' : 'Benutzer' ?>:
                    <strong><?= e($a['subjectId']) ?></strong> — <?= e($rightsList[$a['rights']] ?? $a['rights']) ?></span>
                <form data-fetch-post style="margin:0">
                    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
                    <input type="hidden" name="op"           value="revoke">
                    <input type="hidden" name="subject_type" value="<?= e($a['subjectType']) ?>">
                    <input type="hidden" name="subject"      value="<?= e($a['subjectId']) ?>">
                    <button type="submit" class="be-btn be-btn--danger">Entfernen</button>
                </form>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <form data-fetch-post style="margin:0">
            <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
            <input type="hidden" name="op"           value="grant">
            <div class="be-form__grid" style="grid-template-columns:auto 1fr auto;gap:.4rem;align-items:center">
                <select name="subject_type">
                    <option value="role">Rolle</option>
                    <option value="user">Benutzer-ID</option>
                </select>
                <input type="text" name="subject" placeholder="member / visitor oder Benutzer-ID" required>
                <select name="rights">
                    <?php foreach ($rightsList as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-top:.5rem"><button type="submit" class="be-btn be-btn--primary">Recht hinzufügen</button></div>
        </form>
    </div>
</div>
