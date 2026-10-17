<?php

namespace E9li\Newsletter;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * The sentence a person actually agreed to, and the hash stored beside it.
 *
 * The hash is sha256 of that exact sentence. The sentence itself stays on the
 * list page so it can be translated; the row only needs to prove which
 * wording was current when they said yes.
 */
final class Consent
{
    public const SOURCE_FORM        = 'form';
    public const SOURCE_PREFERENCES = 'preferences';
    public const SOURCE_IMPORT      = 'import';
    public const SOURCE_PANEL       = 'panel';
    public const SOURCE_RECONSENT   = 'reconsent';

    /**
     * The sentence this list contributes to a signup.
     *
     * A list with its own wording uses that. Several lists at once each
     * contribute their own sentence, so the hash on each new subscription
     * identifies that list and not the shared checkbox intro.
     */
    public static function sentence(Page $list, bool $multiple = false): string
    {
        $custom = trim($list->newsletterConsent()->value());

        if ($custom !== '') {
            return $custom;
        }

        if ($multiple === true) {
            return tt(
                'e9li.newsletter.form.consentItem',
                'I agree to receive “{title}” and can unsubscribe at any time.',
                ['title' => $list->title()->value()]
            );
        }

        return t(
            'e9li.newsletter.form.consent',
            'I agree to receive this newsletter and can unsubscribe at any time.'
        );
    }

    /**
     * Wording shown in the preference centre when a list has none of its own.
     */
    public static function preferencesSentence(Page $list): string
    {
        $custom = trim($list->newsletterConsent()->value());

        if ($custom !== '') {
            return $custom;
        }

        return tt(
            'e9li.newsletter.preferences.consent',
            'I want to receive “{list}” and can unsubscribe at any time.',
            ['list' => $list->title()->value()]
        );
    }

    public static function hash(string $sentence): string
    {
        return hash('sha256', $sentence);
    }

    /**
     * Privacy URL shown beside the checkbox.
     *
     * The snippet argument wins. Otherwise the first page an editor would
     * recognise as a privacy policy.
     */
    public static function url(string|null $given = null): string|null
    {
        if (is_string($given) === true && trim($given) !== '') {
            return trim($given);
        }

        $kirby = App::instance();

        foreach (['privacy', 'datenschutz', 'datenschutzbestimmungen'] as $id) {
            $page = $kirby->page($id);

            if ($page !== null) {
                return $page->url();
            }
        }

        return null;
    }

    /**
     * @return array{hash: string, url: string|null, source: string}
     */
    public static function record(Page $list, string $source, bool $multiple = false, string|null $url = null): array
    {
        $sentence = $source === self::SOURCE_PREFERENCES
            ? static::preferencesSentence($list)
            : static::sentence($list, $multiple);

        return [
            'hash'   => static::hash($sentence),
            'url'    => static::url($url),
            'source' => $source,
        ];
    }
}
