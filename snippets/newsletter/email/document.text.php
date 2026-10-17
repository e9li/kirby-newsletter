<?php

/**
 * Plain-text part. Same content as the HTML, in reading order, with URLs written out.
 *
 * @var \E9li\Newsletter\Theme $theme
 * @var string $subject
 * @var string $preheader
 * @var string $body
 * @var string $reasons
 * @var bool $trackOpens
 */

$lines = [];

if ($subject !== '') {
    $lines[] = $subject;
}

if ($preheader !== '') {
    $lines[] = $preheader;
}

if ($theme->headerHtml !== '') {
    $lines[] = trim(html_entity_decode(strip_tags($theme->headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

if ($body !== '') {
    $lines[] = $body;
}

if ($theme->footerHtml !== '') {
    $lines[] = trim(html_entity_decode(strip_tags($theme->footerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

foreach ($theme->social as $item) {
    $lines[] = $item['label'] . ': ' . $item['url'];
}

$lines[] = '{{list_reason}}';

if ($theme->legalName !== '') {
    $lines[] = $theme->legalName;
}

if ($theme->address !== '') {
    $lines[] = $theme->address;
}

if ($theme->privacyUrl !== null) {
    $lines[] = t('e9li.newsletter.form.privacy', 'Privacy policy') . ': ' . $theme->privacyUrl;
}

if ($theme->imprintUrl !== null) {
    $lines[] = t('e9li.newsletter.email.imprint', 'Imprint') . ': ' . $theme->imprintUrl;
}

$lines[] = t('e9li.newsletter.email.unsubscribe', 'Unsubscribe') . ': {{unsubscribe_url}}';
$lines[] = t('e9li.newsletter.email.preferences', 'Email preferences') . ': {{preferences_url}}';

if ($trackOpens === true) {
    $lines[] = t('e9li.newsletter.email.tracking', 'This message records that it was opened.');
}

echo implode("\n\n", array_filter($lines, static fn ($line): bool => $line !== ''));
echo "\n";
