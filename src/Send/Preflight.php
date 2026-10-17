<?php

namespace E9li\Newsletter\Send;

use E9li\Newsletter\Database;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Mailer;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Render\Document;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Toolkit\I18n;

/**
 * The list the send dialog shows before anything is queued.
 *
 * A failure disables send. A warning does not: debug on kirby.test must
 * still be able to reach Mailpit.
 */
final class Preflight
{
    /**
     * @return array{failures: array<int, array<string, string|null>>, warnings: array<int, array<string, string|null>>, recipients: int}
     */
    public static function check(Page $page): array
    {
        $failures = [];
        $warnings = [];

        $from = Mailer::from();
        $failures[] = static::item(
            $from !== null,
            I18n::translate('e9li.newsletter.settings.from', 'Sender address'),
            $from ?? I18n::translate('e9li.newsletter.settings.notSet', 'not configured'),
            null
        );

        $pluginAddress = Newsletter::option('address');
        $hasPluginAddress = is_string($pluginAddress) === true && trim($pluginAddress) !== '';
        $failures[] = static::item(
            $hasPluginAddress,
            I18n::translate('e9li.newsletter.settings.address', 'Postal address'),
            $hasPluginAddress
                ? str_replace("\n", ', ', trim((string)$pluginAddress))
                : I18n::translate('e9li.newsletter.settings.notSet', 'not configured'),
            null
        );

        $subject = trim($page->newsletterSubject()->value());
        $failures[] = static::item(
            $subject !== '',
            I18n::translate('e9li.newsletter.campaign.subject', 'Subject'),
            $subject === ''
                ? I18n::translate('e9li.newsletter.settings.missing', 'missing')
                : $subject,
            null
        );

        $lists = [];

        foreach ($page->newsletterLists()->toPages() as $list) {
            if (Lists::isList($list) === true) {
                $lists[] = $list;
            }
        }

        $failures[] = static::item(
            $lists !== [],
            I18n::translate('e9li.newsletter.form.lists', 'Lists'),
            $lists === []
                ? I18n::translate('e9li.newsletter.settings.missing', 'missing')
                : (string)count($lists),
            null
        );

        $template = $page->newsletterTemplate()->toPage();
        $legal = $template !== null
            && trim($template->newsletterLegalName()->value()) !== ''
            && trim($template->newsletterAddress()->value()) !== '';

        $failures[] = static::item(
            $legal,
            I18n::translate('e9li.newsletter.template.legalName', 'Legal name'),
            $legal
                ? trim((string)$template?->newsletterLegalName()->value())
                : I18n::translate('e9li.newsletter.templates.legalMissing', 'Legal name or address missing'),
            null
        );

        $recipients = static::countRecipients($lists);
        $failures[] = static::item(
            $recipients > 0,
            I18n::translate('e9li.newsletter.report.recipients', 'Recipients'),
            (string)$recipients,
            $recipients === 0
                ? I18n::translate('e9li.newsletter.send.noRecipients', 'No active subscribers left after the suppression list.')
                : null
        );

        $url = (string)App::instance()->url();
        $failures[] = static::item(
            trim($url) !== '',
            I18n::translate('e9li.newsletter.settings.url', 'Site URL'),
            $url === '' ? I18n::translate('e9li.newsletter.settings.notSet', 'not configured') : $url,
            null
        );

        $debug = App::instance()->option('debug') === true;
        $local = str_contains($url, '.test') === true || str_contains($url, 'localhost') === true;

        if ($local === true && $debug === false) {
            $warnings[] = static::row(
                'notice',
                I18n::translate('e9li.newsletter.settings.url', 'Site URL'),
                $url,
                I18n::translate(
                    'e9li.newsletter.send.localUrl',
                    'This URL looks local. Unsubscribe links in sent mail will point at it.'
                )
            );
        }

        $transport = Transport::name();

        if (in_array($transport, ['log', 'smtp'], true) === false) {
            $failures[] = static::row(
                'negative',
                I18n::translate('e9li.newsletter.settings.transport', 'Transport'),
                $transport,
                I18n::translate('e9li.newsletter.send.transport', 'This transport is not available yet.')
            );
        }

        try {
            $rendered = Document::render($page);
            $limit = (int)Newsletter::option('render.warnBytes', 102400);

            if ($rendered['bytes'] > $limit) {
                $warnings[] = static::row(
                    'notice',
                    I18n::translate('e9li.newsletter.preview.bytes', 'Size'),
                    $rendered['bytes'] . ' B',
                    I18n::translate(
                        'e9li.newsletter.send.tooBig',
                        'Gmail clips messages above about 102 KB.'
                    )
                );
            }
        } catch (\Throwable $e) {
            $failures[] = static::row(
                'negative',
                I18n::translate('e9li.newsletter.action.preview', 'Preview'),
                I18n::translate('e9li.newsletter.settings.missing', 'missing'),
                $e->getMessage()
            );
        }

        if (Newsletter::option('track.opens', false) === true) {
            $warnings[] = static::row(
                'notice',
                I18n::translate('e9li.newsletter.settings.trackOpens', 'Open tracking'),
                I18n::translate('e9li.newsletter.settings.present', 'on'),
                I18n::translate(
                    'e9li.newsletter.send.opensApproximate',
                    'Opens are approximate, because Apple Mail prefetches images.'
                )
            );
        }

        foreach ($lists as $list) {
            if (trim($list->newsletterReason()->value()) === '') {
                $warnings[] = static::row(
                    'notice',
                    $list->title()->value(),
                    I18n::translate('e9li.newsletter.list.reason', 'Reason for receiving'),
                    I18n::translate(
                        'e9li.newsletter.send.noReason',
                        'This list has no reason sentence for the footer.'
                    )
                );
            }
        }

        $failures = array_values(array_filter(
            $failures,
            static fn (array $item): bool => $item['status'] === 'negative'
        ));

        return [
            'failures'   => $failures,
            'warnings'   => $warnings,
            'recipients' => $recipients,
        ];
    }

    /**
     * @param array<int, Page> $lists
     */
    public static function countRecipients(array $lists): int
    {
        if ($lists === []) {
            return 0;
        }

        $uuids = [];
        $bindings = ['now' => time()];

        foreach ($lists as $index => $list) {
            $key = 'l' . $index;
            $uuids[] = ':' . $key;
            $bindings[$key] = Lists::uuid($list);
        }

        try {
            return (int)Database::value(
                'SELECT COUNT(DISTINCT s.id)
                   FROM nl_subscribers s
                   JOIN nl_subscriptions sub ON sub.subscriber_id = s.id
                  WHERE sub.list_uuid IN (' . implode(', ', $uuids) . ')
                    AND sub.status = "active"
                    AND s.status = "active"
                    AND NOT EXISTS (
                        SELECT 1 FROM nl_suppressions sup
                         WHERE sup.email_hash = s.email_hash
                           AND (sup.expires_at IS NULL OR sup.expires_at > :now)
                    )',
                $bindings
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{status: string, label: string, value: string, help: string|null}
     */
    protected static function item(bool $ok, string $label, string $value, string|null $help): array
    {
        return static::row($ok === true ? 'positive' : 'negative', $label, $value, $help);
    }

    /**
     * @return array{status: string, label: string, value: string, help: string|null}
     */
    protected static function row(string $status, string $label, string $value, string|null $help): array
    {
        return [
            'status' => $status,
            'label'  => $label,
            'value'  => $value,
            'help'   => $help,
        ];
    }
}
