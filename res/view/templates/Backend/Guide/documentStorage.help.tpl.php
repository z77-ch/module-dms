<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): the document storage (DMS) across its uses —
 * the Drive (Drive › Dokumente), the media field «Auswählen …» in backend forms (the picker),
 * public images of the website under `/media`, the module partitions, rights. Not attached to a
 * screen: the help index finds every `*.help.tpl.php` under the module's templates. Static text,
 * no context. Facts: documents.md (ADR-016/017/020/021).
 */
?>
<h3>Dokumentablage (DMS)</h3>
<p>Wo die Bilder der Website liegen, wie man ein Bild in einem Formular auswählt, warum ein Bild auf der Website nicht erscheint, wer welche Ordner sieht, wie man eine gelöschte Datei zurückholt.</p>

<h4>Eine Ablage für alles</h4>
<p>Alle hochgeladenen Dateien der Installation liegen in <strong>einer</strong> Ablage (Drive › Dokumente). Oben steht der Drive, darunter die <strong>Bereiche</strong>: Bereiche, die der Super-User von Hand anlegt (z.B. für die Website), und Bereiche, die ein Modul für seine Dateien selbst anlegt. Die Dateien liegen auf dem Server ausserhalb des Web-Ordners; ausgeliefert werden sie nur über das System, das jedes Mal die Rechte prüft.</p>

<h4>Bilder der Website</h4>
<p>Ein Bild ist auf der Website nur sichtbar, wenn sein Ordner <strong>öffentlich</strong> ausliefert und Datei und Ordner <strong>aktiv</strong> sind. Seine Adresse ist <code>/media/&lt;bereich&gt;/&lt;ordner&gt;/&lt;datei&gt;</code> — «Pfad kopieren» im Menü ⋮ der Datei gibt sie dir. Erscheint ein Bild nicht: prüfen, ob es im Papierkorb liegt, inaktiv ist oder der Ordner «geschützt» ist. Wird ein Ordner umbenannt oder verschoben, ändern sich die Adressen der Bilder darin; die Seiten, die das Bild über ein Bildfeld gewählt haben, folgen von selbst. Die Reihenfolge der Bilder eines Ordners (z.B. für einen Slider) ist die Reihenfolge in der Ablage — dort per Ziehen ändern.</p>
<p>Beim Hochladen eines Bildes entstehen die verkleinerten Fassungen, die das <strong>Bildprofil</strong> des Ordners verlangt. Alt-Text und Bildunterschrift je Sprache erfasst du in der Ablage unter <strong>Bearbeiten</strong>.</p>

<h4>Bild in einem Formular wählen</h4>
<p>Wo ein Formular im Backend ein Bild oder eine Datei verlangt, steht ein Bildfeld mit <strong>Auswählen …</strong>. Es öffnet die Ablage in einem Fenster, beschränkt auf den passenden Bereich; dort wählst du eine Datei oder lädst eine neue hoch. Eine Datei, die für die Website nicht öffentlich ist, ist dort grau und nennt den Grund. Gespeichert wird die Datei selbst, nicht ihr Name — umbenennen oder verschieben in der Ablage bricht das Formular nicht.</p>

<h4>Wer was sieht</h4>
<p>Jeder sieht in der Ablage nur die Ordner, für die er ein Recht hat (Lesen, Schreiben, Verwalten); Rechte vergibt man im Dialog <strong>Bearbeiten</strong> eines Ordners, für eine Rolle oder einen einzelnen Benutzer. Auch ein Admin braucht ein Recht; nur der Super-User sieht und darf alles. Bereiche anlegen und Rechte auf Bereichen vergeben darf nur der Super-User.</p>

<h4>Löschen, Papierkorb, Aufbewahrung</h4>
<p>Gelöschte Dateien liegen im <strong>Papierkorb</strong> der Ablage und lassen sich dort wiederherstellen. Erst «Endgültig löschen» entfernt sie. Eine Datei mit <strong>Aufbewahrungsfrist</strong> lässt sich bis und mit dem letzten Tag der Frist weder endgültig löschen noch durch einen neuen Upload überschreiben. Die Frist setzt ein Modul — zum Beispiel bekommt das Foto eines Belegs beim Verbuchen 10 Jahre ab Ende des Geschäftsjahrs (gesetzliche Aufbewahrung von Buchungsbelegen).</p>

<h4>Grosse Dateien</h4>
<p>Grosse Dateien (auch mehrere GB) werden beim Hochladen und beim Herunterladen automatisch in Stücken übertragen. Videos spielt «Öffnen» im Browser ab, soweit der Browser das Format kennt.</p>

<h4>Noch nicht verfügbar</h4>
<p>ZIP-Download mehrerer Dateien in der Ablage, Freigabe-Links für Personen ohne Login und das Senden einer Datei per E-Mail aus der Ablage — folgt.</p>
