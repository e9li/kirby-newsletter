<?php

namespace E9li\Newsletter;

use E9li\Newsletter\Repository\Subscriptions;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Bridges Kirby list pages and the list UUIDs stored in SQLite.
 *
 * Lists are pages because there are few of them and they carry translatable,
 * human-edited content (title, description, sender overrides). Subscriptions
 * reference them by UUID rather than by page id, so renaming or moving a list
 * page never orphans thousands of subscription rows.
 */
final class Lists
{
    public const TEMPLATE = 'newsletter-list';

    /**
     * All list pages, listed ones first.
     */
    public static function all(): Pages
    {
        $parent = static::parent();

        if ($parent === null) {
            return new Pages([]);
        }

        return $parent->children()->template(static::TEMPLATE);
    }

    /**
     * Lists a public subscribe form may offer.
     *
     * A list that is not public can still be subscribed to programmatically,
     * but must not be selectable by an anonymous visitor — otherwise an
     * internal segment becomes an open mailing list.
     */
    public static function public(): Pages
    {
        return static::all()->filter(
            static fn (Page $page): bool => $page->newsletterPublic()->toBool(false) === true
        );
    }

    public static function parent(): Page|null
    {
        return App::instance()->page((string)Newsletter::option('listsPage', 'newsletter/lists'));
    }

    /**
     * Resolves a UUID (or a page id) to a list page.
     *
     * Accepts both so that config, imports and hand-written calls can use the
     * readable form while stored data uses the stable one.
     */
    public static function find(string $identifier): Page|null
    {
        $page = App::instance()->page($identifier);

        if ($page === null) {
            return null;
        }

        return static::isList($page) === true ? $page : null;
    }

    /**
     * @param array<int, string> $identifiers
     * @return array<int, Page>
     */
    public static function findMany(array $identifiers): array
    {
        $pages = [];

        foreach ($identifiers as $identifier) {
            $page = static::find($identifier);

            if ($page !== null) {
                $pages[static::uuid($page)] = $page;
            }
        }

        return array_values($pages);
    }

    public static function isList(Page|null $page): bool
    {
        return $page !== null && $page->intendedTemplate()->name() === static::TEMPLATE;
    }

    /**
     * The stable identifier stored in `nl_subscriptions.list_uuid`.
     */
    public static function uuid(Page $page): string
    {
        return $page->uuid()?->toString() ?? 'page://' . $page->id();
    }

    /**
     * Whether this list requires double opt-in.
     *
     * A public list can never opt out of confirmation: an unconfirmed address
     * on a form anyone can reach is how a sender ends up mailing people who
     * never asked, which is exactly what UWG Art. 3 lit. o prohibits.
     */
    public static function requiresOptin(Page $page): bool
    {
        if ($page->newsletterPublic()->toBool(false) === true) {
            return true;
        }

        if ($page->newsletterOptin()->isNotEmpty() === true) {
            return $page->newsletterOptin()->toBool(true);
        }

        return Newsletter::option('optin.required', true) === true;
    }

    public static function title(Page $page, string|null $language = null): string
    {
        return Newsletter::withLanguage(
            $language,
            static fn (): string => $page->title()->or($page->slug())->value()
        );
    }

    /**
     * Counts, for the Panel and the list blueprint's info section.
     *
     * @return array{active: int, pending: int, unsubscribed: int, languages: array<string, int>}
     */
    public static function stats(Page $page): array
    {
        $uuid   = static::uuid($page);
        $counts = Subscriptions::statusCounts($uuid);

        return [
            'active'       => (int)($counts['active'] ?? 0),
            'pending'      => (int)($counts['pending'] ?? 0),
            'unsubscribed' => (int)($counts['unsubscribed'] ?? 0),
            'languages'    => Subscriptions::countByLanguage($uuid),
        ];
    }

    /**
     * Blocks deleting a list that still has subscriptions.
     *
     * Without this, deleting a list page silently orphans its subscription
     * rows: the people stay in the database, their consent record still points
     * at a list nobody can name, and the Panel shows counts for a list that no
     * longer exists.
     */
    public static function guardDeletion(Page $page): void
    {
        if (static::isList($page) === false) {
            return;
        }

        $uuid  = static::uuid($page);
        $total = (int)\E9li\Newsletter\Database::value(
            'SELECT COUNT(*) FROM nl_subscriptions WHERE list_uuid = :uuid AND status != "unsubscribed"',
            ['uuid' => $uuid]
        );

        if ($total === 0) {
            return;
        }

        throw new \Kirby\Exception\LogicException(
            message: 'This list still has ' . $total . ' active or pending subscription(s). '
                . 'Export or move them before deleting the list.'
        );
    }
}
