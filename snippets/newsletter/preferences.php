<?php

/**
 * Preference centre.
 *
 * A place to change which lists and which language, rather than only "all or
 * nothing" — someone who can turn down the frequency usually does that instead
 * of unsubscribing entirely.
 *
 * @var string $token
 * @var \E9li\Newsletter\Result $result
 * @var \E9li\Newsletter\Model\Subscriber|null $subscriber
 * @var array<string, \E9li\Newsletter\Model\Subscription> $subscriptions
 * @var \Kirby\Cms\Pages $lists
 * @var bool $updated
 * @var bool $confirmDelete
 * @var bool $deleted
 * @var string $action
 */

use E9li\Newsletter\Consent;
use E9li\Newsletter\Lists;

ob_start();

if (($deleted ?? false) === true) {
    ?>
    <p><?= esc(t('e9li.newsletter.preferences.deleted', 'Your data has been deleted.')) ?></p>
    <?php
} elseif ($result->ok === false) {
    ?>
    <p class="nl-error"><?= esc(t($result->message(), 'This link is not valid.')) ?></p>
    <?php
} else {
    $kirby = kirby();

    if (($updated ?? false) === true): ?>
    <p><?= esc(t('e9li.newsletter.message.updated', 'Your preferences have been saved.')) ?></p>
    <?php endif ?>

    <p><?= esc(t(
        'e9li.newsletter.preferences.intro',
        'Choose which newsletters you would like to receive.'
    )) ?></p>

    <?php if ($subscriber !== null): ?>
    <p class="nl-note"><?= esc($subscriber->email) ?></p>
    <?php endif ?>

    <form method="post" action="<?= esc($action, 'attr') ?>">
        <?php foreach ($lists as $list): ?>
        <?php
            $uuid    = Lists::uuid($list);
            $current = $subscriptions[$uuid] ?? null;
        ?>
        <label>
            <input type="checkbox" name="lists[]" value="<?= esc($uuid, 'attr') ?>"
                   <?= $current?->isActive() === true ? 'checked' : '' ?>>
            <?= esc($list->title()) ?>
            <?php if ($list->newsletterDescription()->isNotEmpty() === true): ?>
            <br><small class="nl-note"><?= esc($list->newsletterDescription()) ?></small>
            <?php endif ?>
            <br><small class="nl-note"><?= esc(Consent::preferencesSentence($list)) ?></small>
        </label>
        <?php endforeach ?>

        <?php if ($kirby->multilang() === true): ?>
        <label style="margin-top:1rem">
            <?= esc(t('e9li.newsletter.preferences.language', 'Language')) ?>
            <select name="language">
                <?php foreach ($kirby->languages() as $language): ?>
                <option value="<?= esc($language->code(), 'attr') ?>"
                        <?= $subscriber?->language === $language->code() ? 'selected' : '' ?>>
                    <?= esc($language->name()) ?>
                </option>
                <?php endforeach ?>
            </select>
        </label>
        <?php endif ?>

        <p class="nl-note"><?= esc(t(
            'e9li.newsletter.preferences.none',
            'Unchecking everything unsubscribes you completely.'
        )) ?></p>

        <button type="submit">
            <?= esc(t('e9li.newsletter.preferences.submit', 'Save preferences')) ?>
        </button>
    </form>

    <form method="post" action="<?= esc($action, 'attr') ?>" style="margin-top:1.5rem">
        <input type="hidden" name="nl_intent" value="export">
        <button type="submit">
            <?= esc(t('e9li.newsletter.preferences.download', 'Download my data')) ?>
        </button>
    </form>

    <?php if (($confirmDelete ?? false) === true): ?>
    <form method="post" action="<?= esc($action, 'attr') ?>" style="margin-top:1rem">
        <input type="hidden" name="nl_intent" value="delete">
        <p><?= esc(t('e9li.newsletter.preferences.deleteConfirm', 'This deletes your address and subscriptions. Submit again to confirm.')) ?></p>
        <button type="submit">
            <?= esc(t('e9li.newsletter.preferences.delete', 'Delete my data')) ?>
        </button>
    </form>
    <?php else: ?>
    <p style="margin-top:1rem">
        <a href="<?= esc($action . '?delete=1', 'attr') ?>">
            <?= esc(t('e9li.newsletter.preferences.delete', 'Delete my data')) ?>
        </a>
    </p>
    <?php endif ?>
    <?php
}

snippet('newsletter/layout', [
    'title'   => t('e9li.newsletter.preferences.title', 'Your newsletter preferences'),
    'content' => ob_get_clean(),
]);
