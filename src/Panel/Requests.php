<?php

namespace E9li\Newsletter\Panel;

use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Model\Subscriber;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use Kirby\Cms\App;

/**
 * JSON endpoints for the area's Vue views.
 *
 * `requests` routes get no automatic URL prefix — the pattern *is* the panel
 * path — so they are namespaced under `requests/` to stay clear of the view
 * routes. They are firewalled by the same `access.newsletter` permission as the
 * views, and their return value is sent verbatim (unlike dialogs, which Kirby
 * wraps in a `$dialog` key).
 *
 * Every one must return a NON-EMPTY array: Kirby turns `[]` into a 404 and a
 * bare string into a 500.
 */
final class Requests
{
    public static function all(): array
    {
        return [
            'newsletter.subscribers' => [
                'pattern' => 'requests/newsletter/subscribers',
                'action'  => fn (): array => Requests::subscribers(),
            ],
            'newsletter.subscriber' => [
                'pattern' => 'requests/newsletter/subscriber/(:num)',
                'action'  => fn (int $id): array => Requests::subscriber($id),
            ],
            'newsletter.suppressions' => [
                'pattern' => 'requests/newsletter/suppressions',
                'action'  => fn (): array => Requests::suppressions(),
            ],
        ];
    }

    /**
     * Paginated, filtered subscriber rows.
     *
     * Filtering and paging happen in SQL. The table holds thousands of rows and
     * `k-table` renders every row it is given — it has no virtualisation and no
     * internal slicing — so handing it the full set would lock up the browser.
     */
    public static function subscribers(): array
    {
        $query = App::instance()->request()->query();

        $page  = max(1, (int)$query->get('page', 1));
        $limit = max(1, min(200, (int)$query->get('limit', 50)));

        $result = Subscribers::paginate([
            'query'     => trim((string)$query->get('searchterm', '')),
            'status'    => static::nullable($query->get('status')),
            'language'  => static::nullable($query->get('language')),
            'list'      => static::nullable($query->get('list')),
            'sort'      => (string)$query->get('sort', 'created_at'),
            'direction' => (string)$query->get('direction', 'desc'),
        ], $page, $limit);

        $offset = ($result['page'] - 1) * $limit;

        return [
            'data' => array_map(
                static fn (Subscriber $s): array => static::row($s),
                $result['data']
            ),
            'pagination' => [
                'page'   => $result['page'],
                'limit'  => $limit,
                'total'  => $result['total'],
                'offset' => $offset,
            ],
        ];
    }

    /**
     * One subscriber with their full subscription and consent record.
     */
    public static function subscriber(int $id): array
    {
        $subscriber = Subscribers::find($id);

        if ($subscriber === null) {
            return ['code' => 404, 'error' => 'Subscriber not found'];
        }

        $subscriptions = [];

        foreach (Subscriptions::forSubscriber($id) as $subscription) {
            $list = Lists::find($subscription->listUuid);

            $subscriptions[] = [
                ...$subscription->toArray(),
                'listTitle' => $list === null
                    ? $subscription->listUuid
                    : $list->title()->value(),
            ];
        }

        return [
            'subscriber'    => [
                ...$subscriber->toArray(),
                'suppressed' => Suppressions::isSuppressed($subscriber->emailNorm),
            ],
            'subscriptions' => $subscriptions,
        ];
    }

    /**
     * Paginated suppression rows. The address is masked; an erasure leaves
     * only the hash, which the table labels as such.
     */
    public static function suppressions(): array
    {
        $query = App::instance()->request()->query();
        $page  = max(1, (int)$query->get('page', 1));
        $limit = max(1, min(200, (int)$query->get('limit', 25)));
        $result = Suppressions::paginate($page, $limit);
        $rows = [];

        foreach ($result['data'] as $row) {
            $email = $row['email_norm'] ?? null;
            $rows[] = [
                'id'      => (int)$row['id'],
                'address' => is_string($email) && $email !== ''
                    ? EmailAddress::mask($email)
                    : \Kirby\Toolkit\I18n::translate('e9li.newsletter.suppressions.hashOnly', 'Hash only'),
                'reason'  => (string)$row['reason'],
                'date'    => date('Y-m-d', (int)$row['created_at']),
                'expires' => $row['expires_at'] === null
                    ? '—'
                    : date('Y-m-d', (int)$row['expires_at']),
                'link'    => 'newsletter/suppressions/' . (int)$row['id'] . '/remove',
            ];
        }

        return [
            'data' => $rows,
            'pagination' => [
                'page'   => $result['page'],
                'limit'  => $limit,
                'total'  => $result['total'],
                'offset' => ($result['page'] - 1) * $limit,
            ],
        ];
    }

    /**
     * Row shape consumed by `k-table`.
     *
     * Column keys must match the `columns` object keys on the Vue side — the
     * table looks up `row[key]` by the column's own key.
     */
    protected static function row(Subscriber $subscriber): array
    {
        return [
            'id'       => $subscriber->id,
            'email'    => $subscriber->email,
            'name'     => $subscriber->name,
            'status'   => $subscriber->status,
            'language' => $subscriber->language,
            'lists'    => count(Subscriptions::forSubscriber(
                $subscriber->id,
                \E9li\Newsletter\Model\Subscription::STATUS_ACTIVE
            )),
            'created'  => $subscriber->createdAt > 0
                ? date('Y-m-d', $subscriber->createdAt)
                : null,
            'confirmed' => $subscriber->confirmedAt !== null
                ? date('Y-m-d', $subscriber->confirmedAt)
                : null,
        ];
    }

    protected static function nullable(mixed $value): string|null
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
