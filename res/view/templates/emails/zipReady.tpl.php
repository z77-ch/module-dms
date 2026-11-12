<?php
/**
 * «The ZIP is ready» (DMS-ZIP-001): deliberately WITHOUT a document name, and without an id or
 * a token in the link. It leads to a page, the login decides. Neutral German, neither «du» nor
 * «Sie»: a host with its own tone replaces it through `dmsConfig['zipMail']`. Rendered inside
 * the shared emails/layout (which carries the logo); plain text derived per HtmlToText.
 *
 * Texts: `dms.mail.*` in the module dictionary (res/i18n), rendered in the language the bundle
 * was asked for in (ADR-056).
 *
 * @var int    $documents
 * @var string $size      formatted
 * @var string $expires   date, d.m.Y
 * @var string $link      absolute
 */
?>
<p><?= e(t('dms.mail.greeting')) ?></p>

<p>
    <?= e(t('dms.mail.zipReady.lead', [
        'documents'    => (string) $documents,
        'documentWord' => t($documents === 1 ? 'dms.mail.document.one' : 'dms.mail.document.many'),
        'size'         => $size,
        'expires'      => $expires,
    ])) ?>
</p>

<p>
    <a href="<?= e($link) ?>"><?= e(t('dms.mail.zipReady.link')) ?></a>
</p>

<p>
    <?= e(t('dms.mail.zipReady.login')) ?>
</p>
