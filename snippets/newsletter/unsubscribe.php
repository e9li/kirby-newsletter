<?php

/**
 * Unsubscribe.
 *
 * Serves two states:
 *  - GET  → a confirmation form. Deliberately does NOT unsubscribe, because
 *           mail-client link scanners, security gateways and prefetchers all
 *           issue GETs and would otherwise unsubscribe people silently.
 *  - POST → the result, rendered after the action has already happened.
 *
 * @var string|null $token
 * @var bool|null $valid
 * @var \Kirby\Cms\Page|null $list
 * @var string|null $action
 * @var string|null $preferencesUrl
 * @var \E9li\Newsletter\Result|null $result
 */

$result = $result ?? null;

ob_start();

if ($result !== null) {
    // ── Already acted on ────────────────────────────────────────────────────
    ?>
    <p<?= $result->ok === false ? ' class="nl-error"' : '' ?>>
        <?= esc(t($result->message(), 'You have been unsubscribed.')) ?>
    </p>

    <?php if ($result->ok === true): ?>
    <p class="nl-note">
        <?= esc(t(
            'e9li.newsletter.unsubscribe.sorry',
            'Sorry to see you go. You can subscribe again at any time.'
        )) ?>
    </p>
    <?php endif ?>

    <p><a class="nl-button" href="<?= esc(kirby()->site()->url(), 'attr') ?>">
        <?= esc(t('e9li.newsletter.back', 'Back to the website')) ?>
    </a></p>
    <?php
} elseif (($valid ?? false) === false) {
    // ── Bad or tampered token ───────────────────────────────────────────────
    ?>
    <p class="nl-error">
        <?= esc(t(
            'e9li.newsletter.unsubscribe.invalid',
            'This unsubscribe link is not valid.'
        )) ?>
    </p>
    <?php
} else {
    // ── Ask first ───────────────────────────────────────────────────────────
    ?>
    <p>
        <?php if ($list !== null): ?>
            <?= esc(tt(
                'e9li.newsletter.unsubscribe.introList',
                'Would you like to stop receiving “{list}”?',
                ['list' => $list->title()->value()]
            )) ?>
        <?php else: ?>
            <?= esc(t(
                'e9li.newsletter.unsubscribe.intro',
                'Would you like to stop receiving this newsletter?'
            )) ?>
        <?php endif ?>
    </p>

    <form method="post" action="<?= esc($action, 'attr') ?>">
        <button type="submit">
            <?= esc(t('e9li.newsletter.unsubscribe.submit', 'Yes, unsubscribe me')) ?>
        </button>
    </form>

    <?php if (($preferencesUrl ?? null) !== null): ?>
    <p class="nl-note" style="margin-top:1.5rem">
        <a href="<?= esc($preferencesUrl, 'attr') ?>">
            <?= esc(t(
                'e9li.newsletter.unsubscribe.preferences',
                'Or change which newsletters you receive'
            )) ?>
        </a>
    </p>
    <?php endif ?>
    <?php
}

snippet('newsletter/layout', [
    'title'   => t('e9li.newsletter.unsubscribe.title', 'Unsubscribe'),
    'content' => ob_get_clean(),
]);
