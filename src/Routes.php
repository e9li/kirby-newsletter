<?php

namespace E9li\Newsletter;

use E9li\Newsletter\Model\Subscription;
use Kirby\Cms\App;
use Kirby\Http\Response;

/**
 * Public frontend routes.
 *
 * All of them are language-scoped (`'language' => '*'`) so a French subscriber
 * lands on /fr/… and sees French copy.
 */
final class Routes
{
    /**
     * Path segments, per action. Kept short because they appear in every
     * email footer and long URLs wrap in mail clients — a wrapped unsubscribe
     * link is a broken unsubscribe link.
     */
    public const PATHS = [
        'subscribe'   => 'subscribe',
        'confirm'     => 'confirm',
        'unsubscribe' => 'u',
        'preferences' => 'preferences',
        'open'        => 'o',
    ];

    public static function all(): array
    {
        if (Newsletter::option('routes.enabled', true) !== true) {
            return [];
        }

        $prefix = trim((string)Newsletter::option('routes.prefix', 'newsletter'), '/');

        // Note: none of these actions may be a `static` closure. Kirby's
        // Router binds the Route object into the action with Closure::bindTo(),
        // which is an error for static closures on PHP 8.5+.
        return [
            // ── Subscribe (POST from the form snippet) ──────────────────────
            [
                'pattern'  => $prefix . '/' . static::PATHS['subscribe'],
                'method'   => 'POST',
                'language' => '*',
                'action'   => fn () => Routes::subscribe(),
            ],

            // ── Double opt-in confirmation ──────────────────────────────────
            [
                'pattern'  => $prefix . '/' . static::PATHS['confirm'] . '/(:any)',
                'method'   => 'GET',
                'language' => '*',
                'action'   => fn (string $token) => Routes::confirm($token),
            ],

            // ── Unsubscribe: GET shows a confirmation page ──────────────────
            //
            // RFC 8058 requires the one-click POST below to act immediately.
            // GET must NOT, because mail-client link scanners, corporate
            // security gateways and browser prefetchers all issue GETs — and
            // any of them would silently unsubscribe the recipient.
            [
                'pattern'  => $prefix . '/' . static::PATHS['unsubscribe'] . '/(:any)',
                'method'   => 'GET',
                'language' => '*',
                'action'   => fn (string $token) => Routes::unsubscribeConfirm($token),
            ],

            // ── Unsubscribe: POST acts immediately ──────────────────────────
            //
            // Serves two callers with one URL: the `List-Unsubscribe-Post`
            // one-click header (an unauthenticated cross-origin POST from the
            // mail provider, which is why CSRF is not and cannot be checked
            // here) and the confirmation form above.
            [
                'pattern'  => $prefix . '/' . static::PATHS['unsubscribe'] . '/(:any)',
                'method'   => 'POST',
                'language' => '*',
                'action'   => fn (string $token) => Routes::unsubscribe($token),
            ],

            // ── Preference centre ───────────────────────────────────────────
            [
                'pattern'  => $prefix . '/' . static::PATHS['preferences'] . '/(:any)',
                'method'   => 'GET|POST',
                'language' => '*',
                'action'   => fn (string $token) => Routes::preferences($token),
            ],

            // ── Open pixel ──────────────────────────────────────────────────
            //
            // Always a GIF, including when tracking is off or the token is
            // bad. A 404 would tell a client the address was unknown.
            [
                'pattern'  => $prefix . '/' . static::PATHS['open'] . '/(:any)',
                'method'   => 'GET',
                'language' => '*',
                'action'   => fn (string $token) => Routes::open($token),
            ],
        ];
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    public static function subscribe(): Response
    {
        $kirby   = App::instance();
        $request = $kirby->request();
        $body    = $request->body();

        $lists = $body->get('lists', []);
        $lists = is_array($lists) === true ? $lists : [$lists];

        $consent = $body->get('consent');

        $result = Signup::subscribe((string)$body->get('email', ''), $lists, [
            'name'         => $body->get('name'),
            'language'     => $kirby->language()?->code(),
            'source'       => 'form',
            'sourceDetail' => (string)$request->url()->path(),
            'consent'      => $consent === true || $consent === 1 || $consent === '1' || $consent === 'on',
            'consentUrl'   => $body->get('privacyUrl'),
            'ip'           => $kirby->visitor()->ip(),
            'ua'           => $kirby->visitor()->userAgent(),
            'startedAt'    => $body->get('startedAt'),
            'honeypot'     => $body->get((string)Newsletter::option('optin.honeypot', 'website')),
        ]);

        return static::respond($result, 'subscribe');
    }

    public static function confirm(string $token): Response
    {
        $kirby = App::instance();

        $result = Signup::confirm(
            $token,
            $kirby->visitor()->ip(),
            $kirby->visitor()->userAgent()
        );

        return static::respond($result, 'confirm');
    }

    public static function unsubscribeConfirm(string $token): Response
    {
        // Verify without acting, so the page can show who is about to be
        // unsubscribed from what — and so an invalid link says so plainly
        // instead of pretending to succeed.
        $payload = Token::verify('unsub', $token);

        return static::page('unsubscribe', [
            'token'   => $token,
            'valid'   => $payload !== null,
            'scope'   => $payload['scope'] ?? null,
            'list'    => ($payload['scope'] ?? 'all') !== 'all'
                ? Lists::find($payload['scope'])
                : null,
            'action'  => static::url('unsubscribe', $token, $payload['language'] ?? null),

            // Purposes are part of the signed payload, so an unsub token can
            // never be replayed against the preferences route. Mint a matching
            // prefs token here instead — we have just verified the holder owns
            // a valid unsub token for this subscriber, so they are entitled to
            // one.
            'preferencesUrl' => $payload === null ? null : static::url(
                'preferences',
                Token::create('prefs', $payload['id'], $payload['language'], 'all'),
                $payload['language']
            ),
        ]);
    }

    public static function unsubscribe(string $token): Response
    {
        $isOneClick = static::isOneClickRequest();

        $result = Signup::unsubscribe(
            $token,
            $isOneClick === true ? Subscription::REASON_ONECLICK : Subscription::REASON_LINK
        );

        // A one-click caller is a mail provider's robot, not a browser. It
        // wants a bare 200 and ignores any body; rendering a page for it would
        // waste a template render on every unsubscribe.
        if ($isOneClick === true) {
            return new Response(
                $result->ok === true ? 'OK' : 'Invalid token',
                'text/plain',
                $result->ok === true ? 200 : 400
            );
        }

        return static::respond($result, 'unsubscribe');
    }

    public static function preferences(string $token): Response
    {
        $kirby   = App::instance();
        $request = $kirby->request();

        if ($request->method() === 'POST') {
            $intent = (string)$request->body()->get('nl_intent', '');

            if ($intent === 'export') {
                return static::exportData($token);
            }

            if ($intent === 'delete') {
                return static::eraseData($token);
            }

            $lists = $request->body()->get('lists', []);
            $lists = is_array($lists) === true ? $lists : [$lists];

            $result = Signup::updatePreferences(
                $token,
                $lists,
                $request->body()->get('language')
            );

            if ($result->ok === true) {
                // Redirect after POST so a reload does not re-submit.
                return Response::redirect(static::url('preferences', $token) . '?updated=1');
            }

            return static::respond($result, 'preferences');
        }

        $state = Signup::preferences($token);

        return static::page('preferences', [
            'token'         => $token,
            'result'        => $state,
            'subscriber'    => $state->get('subscriber'),
            'subscriptions' => $state->get('subscriptions', []),
            'lists'         => Lists::public(),
            'updated'       => $kirby->request()->query()->get('updated') === '1',
            'confirmDelete' => $kirby->request()->query()->get('delete') === '1',
            'action'        => static::url('preferences', $token),
        ]);
    }

    /**
     * Records one open per queue row, then answers with a 1×1 GIF.
     *
     * A campaign seeded with tracking off still returns the image and writes
     * nothing, so turning the option on later cannot rewrite that send.
     */
    public static function open(string $token): Response
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?: '';
        $response = new Response($gif, 'image/gif', 200, [
            'Cache-Control' => 'no-store, private',
        ], null);

        $payload = Token::verify('open', $token);

        if ($payload === null) {
            return $response;
        }

        $queue = Database::first(
            'SELECT q.id, q.campaign_id, q.subscriber_id, q.email, c.track_opens
               FROM nl_queue q
               JOIN nl_campaigns c ON c.id = q.campaign_id
              WHERE q.id = :id',
            ['id' => $payload['id']]
        );

        if ($queue === null || (int)$queue['track_opens'] !== 1) {
            return $response;
        }

        $existing = Database::first(
            'SELECT id FROM nl_events WHERE queue_id = :id AND type = "open" LIMIT 1',
            ['id' => (int)$queue['id']]
        );

        if ($existing !== null) {
            return $response;
        }

        $now = time();

        Database::insert('nl_events', [
            'campaign_id'   => (int)$queue['campaign_id'],
            'queue_id'      => (int)$queue['id'],
            'subscriber_id' => (int)$queue['subscriber_id'],
            'email_hash'    => EmailAddress::hash((string)$queue['email']),
            'type'          => 'open',
            'ip'            => null,
            'user_agent'    => null,
            'created_at'    => $now,
        ]);

        Database::execute(
            'UPDATE nl_subscribers SET last_open_at = :now, updated_at = :now WHERE id = :id',
            ['now' => $now, 'id' => (int)$queue['subscriber_id']]
        );

        return $response;
    }

    public static function exportData(string $token): Response
    {
        $state = Signup::preferences($token);

        if ($state->ok === false) {
            return static::respond($state, 'preferences');
        }

        /** @var \E9li\Newsletter\Model\Subscriber $subscriber */
        $subscriber = $state->data['subscriber'];

        return new Response(Csv::personal($subscriber), 'text/csv', 200, [
            'Content-Disposition' => 'attachment; filename="newsletter-data.csv"',
        ]);
    }

    public static function eraseData(string $token): Response
    {
        $state = Signup::preferences($token);

        if ($state->ok === false) {
            return static::respond($state, 'preferences');
        }

        /** @var \E9li\Newsletter\Model\Subscriber $subscriber */
        $subscriber = $state->data['subscriber'];
        Repository\Subscribers::erase($subscriber->id, true);

        return static::page('preferences', [
            'token'   => $token,
            'result'  => Result::ok('deleted'),
            'deleted' => true,
            'lists'   => Lists::public(),
            'action'  => static::url('preferences', $token),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Whether this POST came from the RFC 8058 one-click header rather than a
     * browser form.
     *
     * Mail providers send `List-Unsubscribe=One-Click` as the body.
     */
    protected static function isOneClickRequest(): bool
    {
        $body = App::instance()->request()->body();

        if ($body->get('List-Unsubscribe') === 'One-Click') {
            return true;
        }

        // Some providers send it as a raw body rather than a parsed field.
        $raw = (string)file_get_contents('php://input');

        return str_contains($raw, 'List-Unsubscribe=One-Click');
    }

    /**
     * Renders one of the plugin's frontend snippets inside a minimal page.
     *
     * Uses a snippet rather than a Kirby page so the routes work on a site
     * that has not created any newsletter pages yet — the whole flow has to
     * function the moment the plugin is installed.
     */
    protected static function page(string $snippet, array $data = []): Response
    {
        $html = snippet('newsletter/' . $snippet, $data, true);

        return new Response($html, 'text/html', 200);
    }

    protected static function respond(Result $result, string $snippet): Response
    {
        return static::page($snippet, [
            'result'  => $result,
            'ok'      => $result->ok,
            'code'    => $result->code,
            'message' => $result->message(),
            'data'    => $result->data,
        ]);
    }

    /**
     * Builds an absolute URL for one of the routes.
     *
     * Always absolute, and always built from the site URL rather than the
     * current request: these URLs go into emails, where a relative or
     * host-less link is simply dead. This is also why the `url` option must be
     * set for cron-driven sends — the CLI has no HTTP host to infer one from.
     */
    public static function url(string $action, string $token = '', string|null $language = null): string
    {
        $prefix = trim((string)Newsletter::option('routes.prefix', 'newsletter'), '/');
        $path   = $prefix . '/' . (static::PATHS[$action] ?? $action);

        if ($token !== '') {
            $path .= '/' . $token;
        }

        $kirby = App::instance();

        if ($kirby->multilang() === true) {
            $lang = $kirby->language($language ?? Newsletter::defaultLanguage());

            if ($lang !== null) {
                return rtrim($lang->url(), '/') . '/' . $path;
            }
        }

        return $kirby->url() . '/' . $path;
    }
}
