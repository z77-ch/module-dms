<?php
/**
 * «The ZIP is ready» (DMS-ZIP-001): deliberately WITHOUT a document name, and without an id or
 * a token in the link. It leads to a page, the login decides. Neutral German, neither «du» nor
 * «Sie»: a host with its own tone replaces it through `dmsConfig['zipMail']`. Rendered inside
 * the shared emails/layout; plain text derived per HtmlToText.
 *
 * @var int    $documents
 * @var string $size      formatted
 * @var string $expires   date, d.m.Y
 * @var string $link      absolute
 */
?>
<p>Guten Tag</p>

<p>
    Der ZIP-Download mit <?= e((string) $documents) ?> <?= $documents === 1 ? 'Dokument' : 'Dokumenten' ?>
    (<?= e($size) ?>) ist bereit. Er steht bis zum <?= e($expires) ?> zur Verfügung und wird danach entfernt.
</p>

<p>
    <a href="<?= e($link) ?>">Zum ZIP-Download</a>
</p>

<p>
    Für den Download ist eine Anmeldung nötig.
</p>
