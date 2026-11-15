<?php
/**
 * Help of the Drive — Drive › Dokumente (ADR-048, HELP-003: attached by
 * `DriveControllerTrait::listAction()` with `toolbar: true`). What the three panes are for,
 * the toolbar, the row menu (⋮), upload, bulk select, delete and trash, delivery modes.
 * The combined edit window has its own help (`edit.help`). Also prompt material of the
 * KI-Assistent (ADR-055) — the first paragraph routes the question.
 */
?>
<h3>Dokumentablage (Drive)</h3>
<p>Alle Dateien der Installation in Ordnern (Bilder der Website, PDFs, Videos): hochladen, Ordner anlegen, umbenennen, verschieben, löschen, aus dem Papierkorb zurückholen, öffentlich oder nur mit Login ausliefern.</p>
<p>Links steht der <strong>Ordnerbaum</strong>, in der Mitte die <strong>Dateien</strong> des gewählten Ordners, rechts die <strong>Vorschau</strong> der gewählten Datei (Typ, Grösse, Bildmasse, Modus, Aktiv, mit Öffnen, Download, Bearbeiten, Verschieben, Löschen). Die Trennlinien zwischen den Spalten lassen sich ziehen. Auf dem Handy öffnet <strong>Ordner</strong> den Baum, die Vorschau legt sich über die Liste.</p>

<h3>Ordner und Bereiche</h3>
<p>Ganz oben steht der <strong>Drive</strong> selbst: er speichert keine Dateien und lässt sich weder verschieben noch löschen. Seine Unterordner sind die <strong>Bereiche</strong> (z.B. der Bereich der Website oder der Bereich eines Moduls). Einen Bereich anlegen, umbenennen, verschieben oder löschen und Rechte auf einem Bereich vergeben darf nur der <strong>Super-User</strong>; Bereiche, die ein Modul angelegt hat, sind gesperrt. Ein durchgestrichener Ordner ist <strong>inaktiv</strong>: was darin liegt, wird nicht ausgeliefert.</p>
<p>Du siehst nur, was dir freigegeben ist: auch ein Admin braucht ein Recht (Lesen, Schreiben, Verwalten) auf dem Ordner — nur der Super-User sieht alles. Ein Ordner, in dem du nichts lesen darfst, erscheint nur als Weg zu einem freigegebenen Unterordner.</p>

<h3>Werkzeuge über der Liste</h3>
<ul>
    <li><strong>Hochladen</strong> öffnet «Dateien hochladen» (siehe unten).</li>
    <li><strong>Neuer Ordner</strong> legt einen Ordner im geöffneten Ordner an.</li>
    <li><strong>Bearbeiten</strong>, <strong>Verschieben</strong> und <strong>Löschen …</strong> wirken auf den geöffneten Ordner. Ein Ordner lässt sich nur löschen, wenn er leer ist.</li>
    <li><strong>Papierkorb</strong> (Reiter rechts) zeigt die gelöschten Dateien.</li>
</ul>

<h3>Menü einer Zeile (⋮)</h3>
<p>Jeder Ordner und jede Datei hat ein <strong>⋮</strong>: der Schalter <strong>Aktiv</strong> (ausgeliefert / sichtbar), die geltende Auslieferung, <strong>Bearbeiten</strong>, <strong>Verschieben</strong>, <strong>Löschen</strong>. Bei einem Ordner zusätzlich <strong>Neuer Unterordner</strong> (beim Drive selbst: <strong>Neuer Bereich</strong>, nur Super-User) und <strong>Datei hochladen</strong>, bei einer Datei <strong>Öffnen</strong>, <strong>Download</strong> und — nur wenn sie öffentlich ist — <strong>Pfad kopieren</strong> (die Web-Adresse unter <code>/media/…</code>, z.B. für einen Link auf der Website).</p>
<p>Die Reihenfolge der Dateien in einem Ordner ist von Hand: eine Zeile an die gewünschte Stelle ziehen. Diese Reihenfolge gilt auch auf der Website (z.B. für einen Slider oder eine Galerie).</p>

<h3>Dateien hochladen</h3>
<p>Im Fenster «Dateien hochladen» den <strong>Zielordner</strong> wählen und eine oder mehrere Dateien hineinziehen oder auswählen. Grosse Dateien werden automatisch in Stücken übertragen. Erlaubt sind Bilder, Videos, Audio, PDF, Word, Excel, Text und ZIP-Archive (dazu, was die Installation zusätzlich freigegeben hat); was der Server am Inhalt nicht als erlaubte Art erkennt, wird abgewiesen. Liefert der Zielordner öffentlich aus, steht das als Warnung im Fenster — die Datei ist dann sofort im Web abrufbar.</p>
<ul>
    <li><strong>Gleiche Datei nochmals</strong> (gleicher Name, gleicher Inhalt): wird übersprungen, nichts doppelt.</li>
    <li><strong>Gleicher Name, anderer Inhalt</strong>: das Fenster fragt, ob überschrieben werden soll. Überschreiben ersetzt den Inhalt, Adresse, Rechte und Modus bleiben. Eine versiegelte oder noch aufzubewahrende Datei lässt sich nicht überschreiben.</li>
    <li><strong>Originalbild unverändert ausliefern</strong>: für Bilder, die genau so gezeigt werden müssen (z.B. ein Logo); es werden keine verkleinerten Fassungen erstellt, nur das Vorschaubild.</li>
</ul>
<p>Von einem Bild erstellt die Ablage beim Hochladen die verkleinerten Fassungen des <strong>Bildprofils</strong> des Ordners. Ändert sich das Profil später, gilt es nur für neue Uploads.</p>

<h3>Mehrere Dateien auf einmal</h3>
<p>Das Kästchen an einer Zeile wählt die Datei aus; darauf erscheint eine Leiste mit Anzahl, <strong>Alle</strong>, <strong>Keine</strong>, <strong>Verschieben</strong> und <strong>Löschen</strong> (höchstens 500 Dateien, nur Dateien, keine Ordner). Dateien, für die dir das Recht fehlt, werden übersprungen und in der Meldung genannt.</p>

<h3>Löschen und Papierkorb</h3>
<p><strong>Löschen</strong> legt eine Datei in den <strong>Papierkorb</strong> — sie wird ab sofort nicht mehr ausgeliefert, ist aber noch da. Im Papierkorb: <strong>Wiederherstellen</strong> legt sie in ihren Ordner zurück, <strong>Endgültig löschen</strong> entfernt Datei und Eintrag unwiderruflich, <strong>Papierkorb leeren …</strong> löscht nach einer Bestätigung alles endgültig, was du löschen darfst. Eine Datei mit laufender <strong>Aufbewahrungsfrist</strong> bleibt im Papierkorb, bis die Frist abgelaufen ist (die Frist gilt bis und mit ihrem letzten Tag; die Meldung nennt das Datum). So eine Frist setzt ein Modul, zum Beispiel für das Foto eines verbuchten Belegs: 10 Jahre ab Ende des Geschäftsjahrs. Löschen in den Papierkorb und Wiederherstellen gehen auch während der Frist.</p>

<h3>Öffentlich, geschützt, versiegelt</h3>
<ul>
    <li><strong>Öffentlich</strong> — für alle ohne Login abrufbar unter <code>/media/…</code> (Bilder der Website). Gilt für alles darunter, solange ein Unterordner nichts anderes sagt.</li>
    <li><strong>Geschützt</strong> — nur mit Login und Leserecht. Das ist der Normalfall.</li>
    <li><strong>Versiegelt</strong> — nie öffentlich; auch kein Unterordner darunter kann öffentlich werden.</li>
</ul>
<p>Ohne eigene Einstellung erbt ein Ordner oder eine Datei den Modus vom Ordner darüber; das Menü ⋮ zeigt, was tatsächlich gilt. Den Modus und die Rechte änderst du mit <strong>Bearbeiten</strong>.</p>

<h3>Grosse Videos und Downloads</h3>
<p><strong>Öffnen</strong> spielt Video und Audio im Browser ab. Formate, die ein Browser nicht abspielen kann (z.B. WMV, AVI, MKV), zeigen einen Hinweis und einen Download. Grosse Dateien kommen beim <strong>Download</strong> in Stücken herunter; der Fortschritt steht im Meldungsbereich.</p>

<h3>Noch nicht verfügbar</h3>
<p>Mehrere Dateien als <strong>ZIP herunterladen</strong> gibt es in der Ablage noch nicht, ebenso <strong>Freigabe-Links</strong> für Personen ohne Login und das Senden einer Datei <strong>per E-Mail</strong> aus der Ablage — folgt.</p>
