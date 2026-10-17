<?php

/**
 * Double opt-in confirmation result.
 *
 * @var \E9li\Newsletter\Result $result
 */

ob_start();

?>
<p<?= $result->ok === false ? ' class="nl-error"' : '' ?>>
    <?= esc(t($result->message(), 'Thank you.')) ?>
</p>

<?php if ($result->ok === true && ($list = $result->get('list')) !== null): ?>
<p class="nl-note">
    <?= esc($list->title()) ?>
</p>
<?php endif ?>

<p><a class="nl-button" href="<?= esc(kirby()->site()->url(), 'attr') ?>">
    <?= esc(t('e9li.newsletter.back', 'Back to the website')) ?>
</a></p>

<?php

snippet('newsletter/layout', [
    // A short heading, not the message itself — otherwise the page says the
    // same sentence twice.
    'title'   => t('e9li.newsletter.confirm.title', 'Newsletter subscription'),
    'content' => ob_get_clean(),
]);
