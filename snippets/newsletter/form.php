<?php

/**
 * Subscribe form.
 *
 * Drop into any template:
 *
 *     <?= snippet('newsletter/form') ?>
 *     <?= snippet('newsletter/form', ['lists' => ['newsletter/lists/main']]) ?>
 *
 * @var \Kirby\Cms\Pages|array|null $lists    lists to offer; defaults to all public ones
 * @var string|null $heading
 * @var string|null $privacyUrl
 * @var bool $showName
 * @var bool $requireConsent  render an explicit consent checkbox
 */

use E9li\Newsletter\Consent;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Routes;
use E9li\Newsletter\Signup;

$offered = $lists ?? Lists::public();

// Lists::public() is a Pages collection. It is iterable, but it is not a
// PHP array, so $offered[0] fatal. Identifiers passed in by the caller
// are resolved to pages first; either way the rest of the snippet
// indexes a plain list.
if ($offered instanceof \Kirby\Cms\Pages) {
    $offered = $offered->values();
} elseif (is_array($offered) === true) {
    $first = reset($offered);

    if ($first instanceof \Kirby\Cms\Page === false) {
        $offered = Lists::findMany(array_map(strval(...), $offered));
    } else {
        $offered = array_values($offered);
    }
} else {
    $offered = [];
}

$honeypot      = (string)Newsletter::option('optin.honeypot', 'website');
$showName      = $showName ?? true;
$requireConsent = $requireConsent ?? true;
$multiple      = count($offered) > 1;
$privacyUrl    = $privacyUrl ?? Consent::url();

?>
<form class="nl-form" method="post" action="<?= esc(Routes::url('subscribe'), 'attr') ?>">

    <?php if (isset($heading) === true && $heading !== null): ?>
    <h2 class="nl-form__heading"><?= esc($heading) ?></h2>
    <?php endif ?>

    <?php if (count($offered) === 0): ?>
    <p class="nl-form__empty">
        <?= esc(t('e9li.newsletter.message.noLists', 'No newsletters are available yet.')) ?>
    </p>
    <?php else: ?>

    <?php if ($multiple === true): ?>
    <fieldset class="nl-form__lists">
        <legend><?= esc(t('e9li.newsletter.form.lists', 'Newsletters')) ?></legend>
        <?php foreach ($offered as $list): ?>
        <label class="nl-form__checkbox">
            <input type="checkbox" name="lists[]" value="<?= esc(Lists::uuid($list), 'attr') ?>" checked>
            <span><?= esc($list->title()) ?></span>
            <?php if ($list->newsletterDescription()->isNotEmpty() === true): ?>
            <small><?= esc($list->newsletterDescription()) ?></small>
            <?php endif ?>
        </label>
        <?php endforeach ?>
    </fieldset>
    <?php else: ?>
    <input type="hidden" name="lists[]" value="<?= esc(Lists::uuid($offered[0]), 'attr') ?>">
    <?php endif ?>

    <?php if ($showName === true): ?>
    <div class="nl-form__field">
        <label for="nl-name"><?= esc(t('e9li.newsletter.form.name', 'Name')) ?></label>
        <input type="text" id="nl-name" name="name" autocomplete="name"
               placeholder="<?= esc(t('e9li.newsletter.form.namePlaceholder', 'Optional'), 'attr') ?>">
    </div>
    <?php endif ?>

    <div class="nl-form__field">
        <label for="nl-email">
            <?= esc(t('e9li.newsletter.form.email', 'Email address')) ?>
            <abbr title="<?= esc(t('e9li.newsletter.form.required', 'Required'), 'attr') ?>">*</abbr>
        </label>
        <input type="email" id="nl-email" name="email" required autocomplete="email" inputmode="email">
    </div>

    <?php if ($requireConsent === true): ?>
    <div class="nl-form__field nl-form__field--consent">
        <label class="nl-form__checkbox">
            <input type="checkbox" name="consent" value="1" required>
            <span>
                <?php if ($multiple === true): ?>
                <?= esc(t('e9li.newsletter.form.consentMulti', 'I agree to receive the newsletters I ticked, and I can unsubscribe at any time.')) ?>
                <ul class="nl-form__consent-lists">
                    <?php foreach ($offered as $list): ?>
                    <li><?= esc(Consent::sentence($list, true)) ?></li>
                    <?php endforeach ?>
                </ul>
                <?php else: ?>
                <?= esc(Consent::sentence($offered[0], false)) ?>
                <?php endif ?>
                <?php if ($privacyUrl !== null): ?>
                <a href="<?= esc($privacyUrl, 'attr') ?>" target="_blank" rel="noopener">
                    <?= esc(t('e9li.newsletter.form.privacy', 'Privacy policy')) ?>
                </a>
                <?php endif ?>
            </span>
        </label>
    </div>
    <?php endif ?>

    <?php /* Honeypot. Hidden from people, irresistible to naive bots. Not
             type="hidden" — bots skip those — and labelled + aria-hidden so a
             screen reader does not read it out and a person does not fill it. */ ?>
    <div class="nl-form__hp" aria-hidden="true"
         style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
        <label for="nl-<?= esc($honeypot, 'attr') ?>">Leave this field empty</label>
        <input type="text" id="nl-<?= esc($honeypot, 'attr') ?>"
               name="<?= esc($honeypot, 'attr') ?>" tabindex="-1" autocomplete="off">
    </div>

    <?php /* Signed render timestamp for the time-trap, so it cannot be back-dated. */ ?>
    <input type="hidden" name="startedAt" value="<?= esc(Signup::encodeStartedAt(), 'attr') ?>">

    <button type="submit" class="nl-form__submit">
        <?= esc(t('e9li.newsletter.form.submit', 'Subscribe')) ?>
    </button>

    <?php endif ?>
</form>
