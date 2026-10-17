<?php

/**
 * Double opt-in confirmation — plain text part.
 *
 * The URL is printed bare and on its own line: every mail client linkifies it,
 * and a confirmation that only works in the HTML part strands anyone reading
 * in plain text.
 *
 * @var \E9li\Newsletter\Model\Subscriber $subscriber
 * @var string $listTitle
 * @var string $url
 * @var int $expires
 */

$greeting = $subscriber->firstName() !== null
    ? t('e9li.newsletter.optin.greeting', 'Hello') . ' ' . $subscriber->firstName()
    : t('e9li.newsletter.optin.greeting', 'Hello');

echo $greeting . "\n\n";

echo tt('e9li.newsletter.optin.intro', 'Please confirm that you would like to receive “{list}”.', [
    'list' => $listTitle,
]) . "\n\n";

echo $url . "\n\n";

echo tt('e9li.newsletter.optin.expires', 'This link is valid until {date}.', [
    'date' => date('d.m.Y', $expires),
]) . "\n\n";

echo t(
    'e9li.newsletter.optin.ignore',
    'If you did not request this, simply ignore this email — nothing will be sent to you.'
) . "\n\n";

echo '-- ' . "\n";
echo $site->title() . "\n";
echo $site->url() . "\n";
