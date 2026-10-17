<?php

/**
 * Per-subscription consent evidence.
 *
 * The readable sentence stays on the list page, where it can be translated.
 * The row stores only a hash of the exact sentence the person was shown,
 * plus the privacy URL and the channel the consent arrived through.
 *
 * Existing rows keep NULL. There is no backfill: a hash invented after the
 * fact would be evidence we do not actually have.
 *
 * @return Closure(Kirby\Database\Database): void
 */
return function (Kirby\Database\Database $db): void {
    $db->execute('ALTER TABLE nl_subscriptions ADD COLUMN consent_text_hash TEXT NULL');
    $db->execute('ALTER TABLE nl_subscriptions ADD COLUMN consent_url TEXT NULL');
    $db->execute('ALTER TABLE nl_subscriptions ADD COLUMN consent_source TEXT NULL');
};
