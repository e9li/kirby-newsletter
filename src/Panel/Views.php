<?php

namespace E9li\Newsletter\Panel;

use E9li\Newsletter\Database;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Mailer;
use E9li\Newsletter\Migrations;
use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use E9li\Newsletter\Secret;
use E9li\Newsletter\Send\Preflight;
use E9li\Newsletter\Send\Seeder;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Toolkit\I18n;

/**
 * The area's four views.
 *
 * Only `component`, `props`, `title` and `breadcrumb` survive to the client —
 * Kirby's view state module copies a fixed key list and silently drops the
 * rest — so everything the Vue side needs goes inside `props`.
 *
 * Props may be closures; Kirby resolves them lazily via A::apply(), which keeps
 * the cost off any view that does not read them.
 *
 * NOTE: this file uses the explicit `Views::` rather than the plugin's usual
 * `static::`. Kirby invokes route, dialog and view handlers with
 * Closure::call($route), which rebinds their scope — so `static::` inside one
 * of them resolves to Kirby\Http\Route, whose __call() magic method returns
 * null for any unknown method instead of erroring. That turns a typo'd or
 * rebound call into a silent null rather than a fatal.
 *
 * For the same reason every helper reachable from a handler is public: a
 * protected method is not callable from the rebound Route scope.
 */
final class Views
{
    public static function all(): array
    {
        return [
            'newsletter'           => Views::campaigns(),
            'newsletter.lists'     => Views::lists(),
            'newsletter.templates' => Views::templates(),
            'newsletter.settings'  => Views::settings(),
        ];
    }

    // ── Newsletters ─────────────────────────────────────────────────────────

    public static function campaigns(): array
    {
        return [
            'pattern' => 'newsletter',
            'action'  => function (): array {
                return [
                    'component' => 'k-newsletter-campaigns-view',
                    'title'     => I18n::translate('e9li.newsletter.label', 'Newsletter'),
                    'props'     => [
                        ...Area::shared('campaigns'),
                        'campaigns' => fn (): array => Views::campaignRows(),
                        'parent'    => fn (): string|null => Views::parentLink('campaignsPage'),
                    ],
                ];
            },
        ];
    }

    /**
     * Campaign pages joined with the latest send, summed across languages.
     */
    public static function campaignRows(): array
    {
        $parent = App::instance()->page(
            (string)Newsletter::option('campaignsPage', 'newsletter/campaigns')
        );

        if ($parent === null) {
            return [];
        }

        $rows = [];
        $notRecorded = I18n::translate('e9li.newsletter.report.notRecorded', 'not recorded');

        foreach ($parent->children()->template('newsletter-campaign') as $page) {
            $uuid   = $page->uuid()?->toString() ?? '';
            $latest = $uuid === '' ? null : Seeder::latest($uuid);
            $check  = Preflight::check($page);

            $rows[] = [
                'id'         => $page->id(),
                'uuid'       => $uuid,
                'title'      => $page->title()->value(),
                'subject'    => $page->newsletterSubject()->value(),
                'status'     => $page->status(),
                'lists'      => $page->newsletterLists()->toPages()->count(),
                'languages'  => Views::translatedLanguages($page),
                'modified'   => $page->modified('Y-m-d H:i'),
                'link'       => 'pages/' . $page->panel()->id(),
                'canSend'    => $check['failures'] === [],
                'sent'       => $latest === null ? '—' : $latest['sent'] . ' / ' . $latest['total'],
                'failed'     => $latest === null ? '—' : (string)$latest['failed'],
                'opened'     => $latest !== null && $latest['tracked'] === true
                    ? (string)$latest['opened']
                    : $notRecorded,
            ];
        }

        return $rows;
    }

    /**
     * Language codes that actually have content for this page.
     *
     * `$page->content($code)` cannot answer this — it silently falls back to
     * the default version — so the translation's own existence is checked.
     *
     * @return array<int, string>
     */
    public static function translatedLanguages(Page $page): array
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return [];
        }

        $codes = [];

        foreach ($kirby->languages() as $language) {
            if ($page->translation($language->code())->exists() === true) {
                $codes[] = $language->code();
            }
        }

        return $codes;
    }

    // ── Lists ───────────────────────────────────────────────────────────────

    public static function lists(): array
    {
        return [
            'pattern' => 'newsletter/lists',
            'action'  => function (): array {
                return [
                    'component'  => 'k-newsletter-lists-view',
                    'title'      => I18n::translate('e9li.newsletter.tab.lists', 'Lists'),
                    'breadcrumb' => [[
                        'label' => I18n::translate('e9li.newsletter.tab.lists', 'Lists'),
                        'link'  => Area::link('lists'),
                    ]],
                    'props' => [
                        ...Area::shared('lists'),
                        'lists'     => fn (): array => Views::listRows(),
                        'parent'    => fn (): string|null => Views::parentLink('listsPage'),
                        'languages' => fn (): array => Views::languageOptions(),
                        'statuses'  => fn (): array => Views::statusOptions(),
                        'totals'    => fn (): array => [
                            'active'     => Subscribers::count(Subscriber::STATUS_ACTIVE),
                            'pending'    => Subscribers::count(Subscriber::STATUS_PENDING),
                            'all'        => Subscribers::count(),
                            'suppressed' => Suppressions::count(),
                        ],
                    ],
                ];
            },
        ];
    }

    public static function listRows(): array
    {
        $rows = [];

        foreach (Lists::all() as $page) {
            $stats = Lists::stats($page);

            $rows[] = [
                'id'           => $page->id(),
                'uuid'         => Lists::uuid($page),
                'title'        => $page->title()->value(),
                'description'  => $page->newsletterDescription()->value(),
                'public'       => $page->newsletterPublic()->toBool(false),
                'optin'        => Lists::requiresOptin($page),
                'active'       => $stats['active'],
                'pending'      => $stats['pending'],
                'unsubscribed' => $stats['unsubscribed'],
                'languages'    => $stats['languages'],
                'status'       => $page->status(),
                'link'         => 'pages/' . $page->panel()->id(),
            ];
        }

        return $rows;
    }

    // ── Templates ───────────────────────────────────────────────────────────

    public static function templates(): array
    {
        return [
            'pattern' => 'newsletter/templates',
            'action'  => function (): array {
                return [
                    'component'  => 'k-newsletter-templates-view',
                    'title'      => I18n::translate('e9li.newsletter.tab.templates', 'Templates'),
                    'breadcrumb' => [[
                        'label' => I18n::translate('e9li.newsletter.tab.templates', 'Templates'),
                        'link'  => Area::link('templates'),
                    ]],
                    'props' => [
                        ...Area::shared('templates'),
                        'templates' => fn (): array => Views::templateRows(),
                        'parent'    => fn (): string|null => Views::parentLink('templatesPage'),
                    ],
                ];
            },
        ];
    }

    public static function templateRows(): array
    {
        $parent = App::instance()->page(
            (string)Newsletter::option('templatesPage', 'newsletter/templates')
        );

        if ($parent === null) {
            return [];
        }

        $rows = [];

        foreach ($parent->children()->template('newsletter-template') as $page) {
            $rows[] = [
                'id'      => $page->id(),
                'uuid'    => $page->uuid()?->toString(),
                'title'   => $page->title()->value(),
                'brand'   => $page->newsletterBrand()->value(),
                'link'    => 'pages/' . $page->panel()->id(),
                'status'  => $page->status(),
                'legal'   => $page->newsletterLegalName()->isNotEmpty()
                    && $page->newsletterAddress()->isNotEmpty(),
            ];
        }

        return $rows;
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public static function settings(): array
    {
        return [
            'pattern' => 'newsletter/settings',
            'action'  => function (): array {
                return [
                    'component'  => 'k-newsletter-settings-view',
                    'title'      => I18n::translate('e9li.newsletter.tab.settings', 'Settings'),
                    'breadcrumb' => [[
                        'label' => I18n::translate('e9li.newsletter.tab.settings', 'Settings'),
                        'link'  => Area::link('settings'),
                    ]],
                    'props' => [
                        ...Area::shared('settings'),
                        'checks'  => fn (): array => Views::checks(),
                        'storage' => fn (): array => Views::storage(),
                        'sending' => fn (): array => Views::sending(),
                    ],
                ];
            },
        ];
    }

    /**
     * Configuration checks, shown as a readiness list.
     *
     * Read-only on purpose: every one of these is a `config.php` value, and a
     * Panel form that wrote them would have to write to a PHP file — which
     * breaks on any deploy that ships an immutable config.
     *
     * @return array<int, array{status: string, label: string, value: string, help: string|null}>
     */
    public static function checks(): array
    {
        $kirby = App::instance();
        $checks = [];

        $from = Mailer::from();
        $checks[] = Views::check(
            $from !== null,
            I18n::translate('e9li.newsletter.settings.from', 'Sender address'),
            $from ?? I18n::translate('e9li.newsletter.settings.notSet', 'not configured'),
            $from === null
                ? 'Set `e9li.kirby-newsletter.from` in config.php. Nothing can be sent without it.'
                : null
        );

        $domain = Mailer::sendingDomain();
        $checks[] = Views::check(
            $domain !== null,
            I18n::translate('e9li.newsletter.settings.domain', 'Sending domain'),
            $domain ?? '—',
            'Use a dedicated subdomain so a reputation problem never affects your transactional mail.'
        );

        $address = Newsletter::option('address');
        $checks[] = Views::check(
            is_string($address) === true && trim($address) !== '',
            I18n::translate('e9li.newsletter.settings.address', 'Postal address'),
            is_string($address) === true && $address !== ''
                ? str_replace("\n", ', ', trim($address))
                : I18n::translate('e9li.newsletter.settings.notSet', 'not configured'),
            'Required in every footer by CAN-SPAM, and part of correct sender identification under Swiss UWG Art. 3 lit. o.'
        );

        $url = $kirby->url();
        $checks[] = Views::check(
            str_contains($url, 'localhost') === false && str_contains($url, '.test') === false,
            I18n::translate('e9li.newsletter.settings.url', 'Site URL'),
            $url,
            'Must be the public URL. Cron has no HTTP host, so a wrong value breaks every unsubscribe link in sent mail.'
        );

        $checks[] = Views::check(
            Secret::exists(),
            I18n::translate('e9li.newsletter.settings.secret', 'Signing secret'),
            Secret::exists()
                ? I18n::translate('e9li.newsletter.settings.present', 'present')
                : I18n::translate('e9li.newsletter.settings.missing', 'missing'),
            'Signs unsubscribe links. Generated automatically on first use.'
        );

        return $checks;
    }

    public static function check(bool $ok, string $label, string $value, string|null $help = null): array
    {
        return [
            'status' => $ok === true ? 'positive' : 'negative',
            'label'  => $label,
            'value'  => $value,
            'help'   => $help,
        ];
    }

    public static function storage(): array
    {
        $journal = 'unknown';

        try {
            $journal = Database::journalMode();
        } catch (\Throwable) {
            // Reported as unknown rather than fataling the whole settings view.
        }

        return [
            'root'    => Newsletter::root(),
            'size'    => Database::size(),
            'schema'  => Migrations::marker(),
            'journal' => $journal,
            'version' => Newsletter::VERSION,
        ];
    }

    public static function sending(): array
    {
        return [
            'transport'   => Newsletter::option('transport') ?? 'auto (log in debug, else smtp)',
            'ratePerHour' => (int)Newsletter::option('rate.perHour', 600),
            'batchSize'   => (int)Newsletter::option('rate.batchSize', 12),
            'warmup'      => Newsletter::option('warmup.enabled', true) === true,
            'trackOpens'  => Newsletter::option('track.opens', false) === true,
            'trackClicks' => Newsletter::option('track.clicks', false) === true,
            'optin'       => Newsletter::option('optin.required', true) === true,
            'storeIp'     => (string)Newsletter::option('privacy.storeIp', 'anonymize'),
        ];
    }

    // ── Shared helpers ──────────────────────────────────────────────────────

    /**
     * Panel link to the container page for a section, or null when it does not
     * exist yet — the views use this to offer a "create it" hint instead of a
     * dead link.
     */
    public static function parentLink(string $option): string|null
    {
        $page = App::instance()->page((string)Newsletter::option($option));

        return $page === null ? null : 'pages/' . $page->panel()->id();
    }

    /**
     * @return array<int, array{value: string, text: string}>
     */
    public static function languageOptions(): array
    {
        $kirby = App::instance();

        if ($kirby->multilang() === false) {
            return [];
        }

        $options = [];

        foreach ($kirby->languages() as $language) {
            $options[] = ['value' => $language->code(), 'text' => $language->name()];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, text: string}>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (Subscriber::STATUSES as $status) {
            $options[] = [
                'value' => $status,
                'text'  => I18n::translate('e9li.newsletter.status.' . $status, ucfirst($status)),
            ];
        }

        return $options;
    }
}
