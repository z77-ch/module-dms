<?php
/**
 * «The ZIP could not be made» (DMS-ZIP-001): only when a mail was asked for. Whoever waits for
 * one has no screen open. No document name, no id, no token, as in emails/zipReady; `$reason` is
 * the bundle's German reason, which names none either. Neutral German; a host with its own tone
 * replaces it through `dmsConfig['zipMail']`.
 *
 * @var int    $documents
 * @var string $reason
 * @var string $link      absolute
 */
?>
<p data-z77-inline="logo" style="margin:0 0 24px;"></p>
<p>Guten Tag</p>

<p>
    Der ZIP-Download mit <?= e((string) $documents) ?> <?= $documents === 1 ? 'Dokument' : 'Dokumenten' ?>
    konnte nicht erstellt werden.
</p>

<?php if ($reason !== ''): ?>
<p><?= e($reason) ?></p>
<?php endif; ?>

<p>
    <a href="<?= e($link) ?>">Zu den Dokumenten</a>
</p>

<p>
    Dafür ist eine Anmeldung nötig.
</p>
