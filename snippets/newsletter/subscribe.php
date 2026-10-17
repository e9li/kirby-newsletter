<?php

/**
 * Result page after a form submission.
 *
 * @var \E9li\Newsletter\Result $result
 */

ob_start();

?>
<p<?= $result->ok === false ? ' class="nl-error"' : '' ?>>
    <?= esc(t($result->message(), 'Thank you.')) ?>
</p>

<?php if ($result->ok === false): ?>
<p><a class="nl-button" href="<?= esc(kirby()->site()->url(), 'attr') ?>">
    <?= esc(t('e9li.newsletter.back', 'Back to the website')) ?>
</a></p>
<?php endif ?>

<?php

snippet('newsletter/layout', [
    'title'   => t('e9li.newsletter.form.submit', 'Subscribe'),
    'content' => ob_get_clean(),
]);
