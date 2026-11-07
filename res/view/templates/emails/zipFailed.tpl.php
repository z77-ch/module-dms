<?php
/**
 * «The ZIP could not be made» (DMS-ZIP-001): only when a mail was asked for. Whoever waits for
 * one has no screen open. No document name, no id, no token, as in emails/zipReady; `$reason` is
 * the bundle's reason, which names none either. Neutral German; a host with its own tone
 * replaces it through `dmsConfig['zipMail']`.
 *
 * Texts: `dms.mail.*` in the module dictionary (res/i18n), rendered in the language the bundle
 * was asked for in (ADR-056). `$reason` is still the bundle's German sentence (ADR-056, part 5).
 *
 * @var int    $documents
 * @var string $reason
 * @var string $link      absolute
 */
?>
<p data-z77-inline="logo" style="margin:0 0 24px;"></p>
<p><?= e(t('dms.mail.greeting')) ?></p>

<p>
    <?= e(t('dms.mail.zipFailed.lead', [
        'documents'    => (string) $documents,
        'documentWord' => t($documents === 1 ? 'dms.mail.document.one' : 'dms.mail.document.many'),
    ])) ?>
</p>

<?php if ($reason !== ''): ?>
<p><?= e($reason) ?></p>
<?php endif; ?>

<p>
    <a href="<?= e($link) ?>"><?= e(t('dms.mail.zipFailed.link')) ?></a>
</p>

<p>
    <?= e(t('dms.mail.zipFailed.login')) ?>
</p>
