<?php
/**
 * Help of the Drive's combined edit dialog — «Ordner bearbeiten» / «Dokument bearbeiten»
 * (ADR-048). A general part and a section per field (`name`, `display_name`, `key`, `profile`,
 * `mode`, and the ACL capture `subject`).
 *
 * NOT attached to the dialog: the Drive opens its dialogs through the popup channel
 * (`data-fetch-get`), which has no help trigger — only a window (ADR-047) shows one. Until the
 * Drive dialogs become windows this file is knowledge of the KI-Assistent (ADR-055 — every
 * `*.help.tpl.php` is indexed) and its sections are ready for `combinedEdit()` to attach.
 */
?>
<h3>Ordner oder Datei bearbeiten</h3>
<p>Ordner oder Datei umbenennen, öffentlich, geschützt oder versiegelt ausliefern, Alt-Text und Bildunterschrift eines Bildes, <strong>Zugriffsrechte</strong> vergeben (wer lesen, schreiben, verwalten darf).</p>
<p>Den Dialog öffnet nur, wer das Recht <strong>Verwalten</strong> hat.</p>
<p><strong>Speichern</strong> übernimmt nur, was du geändert hast. Rechte werden sofort mit <strong>Recht hinzufügen</strong> bzw. <strong>Entfernen</strong> gespeichert — dafür braucht es kein Speichern.</p>

<section data-help-field="name">
<h3>Ordnername</h3>
<p>Der Name im Ordnerbaum. Er ist auch Teil der Web-Adresse (<code>/media/…</code>) der öffentlichen Dateien darin: ein umbenannter Ordner ändert diese Adressen. Gesperrt ist der Name beim Drive selbst, bei Bereichen, die ein Modul angelegt hat, und bei einem Bereich, wenn du nicht Super-User bist.</p>
</section>

<section data-help-field="display_name">
<h3>Anzeigename</h3>
<p>Der Name der Datei in der Ablage. Darunter steht der Name der hochgeladenen <strong>Originaldatei</strong>; er bleibt unverändert. Die Web-Adresse einer öffentlichen Datei bleibt beim Umbenennen gleich.</p>
</section>

<section data-help-field="key">
<h3>Key</h3>
<p>Nur für den Super-User, nur bei einem von Hand angelegten Bereich: eine feste Adresse (Kleinbuchstaben, Ziffern, Bindestrich, eindeutig), über die ein Navigationseintrag den Drive direkt in diesem Bereich öffnet. Leer = kein Key. Bereiche eines Moduls haben einen festen Key.</p>
</section>

<section data-help-field="profile">
<h3>Bildprofil</h3>
<p>Welche Bildgrössen beim Hochladen in diesen Ordner erstellt werden (z.B. für Slider, Galerie, Vorschaubild). Gilt auch für die Unterordner, solange sie kein eigenes Profil haben. «Geerbt / keines» nimmt das Profil des Ordners darüber, sonst das Profil «default» des Bereichs. Die Profile legt der Entwickler pro Bereich fest; das Feld erscheint nur, wenn es welche gibt. Ein geändertes Profil gilt nur für neue Uploads — schon vorhandene Bilder bleiben, wie sie sind.</p>
</section>

<h3>Bildtexte</h3>
<p>Nur bei Bildern, je Sprache: <strong>Alt-Text</strong> beschreibt das Bild für Screenreader und Suchmaschinen, die <strong>Bildunterschrift</strong> steht auf der Website unter dem Bild, wo die Seite eine vorsieht. Leer = in dieser Sprache gilt der Text der Standardsprache.</p>

<section data-help-field="mode">
<h3>Auslieferungs-Modus</h3>
<ul>
    <li><strong>Geerbt</strong> — es gilt der Modus des Ordners darüber («Effektiv aktuell» zeigt, welcher).</li>
    <li><strong>Geschützt</strong> — nur mit Login und Leserecht abrufbar.</li>
    <li><strong>Öffentlich</strong> — für alle ohne Login unter <code>/media/…</code> abrufbar, auch alles darunter, was erbt. Nur für Inhalte der Website wählen.</li>
    <li><strong>Versiegelt</strong> — wird nie öffentlich ausgeliefert, auch kein Unterordner. Ist ein Ordner darüber versiegelt, sind die offeneren Modi gesperrt.</li>
</ul>
<p>Der Drive selbst hat keinen eigenen Modus; ohne Einstellung ist alles geschützt.</p>
</section>

<section data-help-field="subject">
<h3>Zugriffsrechte</h3>
<p>Ein Recht gilt für diesen Ordner und alles darin. Eine Regel nennt eine <strong>Rolle</strong> (z.B. <code>editor</code>, <code>member</code>) oder eine <strong>Benutzer-ID</strong> und ein Recht:</p>
<ul>
    <li><strong>Lesen</strong> — sehen, öffnen, herunterladen.</li>
    <li><strong>Schreiben</strong> — dazu hochladen, Unterordner anlegen, Dateien hineinverschieben und die Reihenfolge ändern.</li>
    <li><strong>Verwalten</strong> — dazu umbenennen, verschieben, löschen, aktiv schalten, Modus ändern und Rechte vergeben.</li>
</ul>
<p>Es gilt das höchste Recht aus allen passenden Regeln auf dem Ordner und den Ordnern darüber. Wer eine Datei hochgeladen hat, darf sie immer verwalten; der Super-User darf alles. Ein Admin hat ohne Regel <strong>kein</strong> Recht in der Ablage. Rechte auf dem Drive selbst und auf einem Bereich vergibt nur der Super-User. In einem öffentlichen Ordner darf jeder lesen; die Regeln bestimmen dort nur, wer schreiben und verwalten darf.</p>
</section>
