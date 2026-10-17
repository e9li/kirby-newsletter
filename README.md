# kirby-newsletter

A self-hosted newsletter system for Kirby 5 — lists, double opt-in, and (from
v0.4) throttled bulk sending of layout-field campaigns.

Built for a realistic small-sender workload: **3,000–5,000 recipients, 2–4 sends
a month.**

> **Status: v0.4.0.** Lists, double opt-in, the Panel, CSV import/export, layout
> rendering, a throttled queue and optional open tracking work. Postal,
> sendmail, warmup, click tracking and bounce webhooks are not in this version.

## What works today

- **Panel area** in the sidebar, with Newsletters / Lists / Templates / Settings
- **CSV import** with column auto-mapping, a dry-run diff and a consent attestation
- **CSV export** including the consent record
- Subscriber browser over thousands of rows, paginated and filtered server-side
- Subscriber lists as ordinary Kirby pages, with translatable titles and descriptions
- Double opt-in with single-use, expiring, hashed confirmation tokens
- Unsubscribe and preference-centre links that work forever, with no token table
- **RFC 8058 one-click unsubscribe**, including the GET/POST distinction most implementations get wrong
- SQLite storage sized for tens of thousands of rows
- Consent evidence (timestamp, anonymised IP, user agent, source) per person *and* per list
- Honeypot, signed time-trap and per-IP rate limiting on the public form
- Suppression list keyed on a hash, so it survives a GDPR erasure
- Layout-field campaigns render to table-based HTML and plain text
- Send preflight, a queue, `newsletter:tick`, SMTP keep-alive and a log outbox
- Consent text hash per subscription, suppression browser, self-service export and deletion
- Optional open pixel (off by default); one event per recipient

## Requirements

Kirby 5.2+ · PHP 8.2–8.5 · `pdo_sqlite`, `dom`, `mbstring` ·
[getkirby/cli](https://github.com/getkirby/cli) for the commands

## Install

Drop the folder into `site/plugins/`, then:

```bash
kirby newsletter:migrate
kirby newsletter:status
```

`newsletter:migrate` creates `site/newsletter/` containing the SQLite database,
the signing secret and deny-all guards. Nothing is written outside that folder.

## Setup

### 1. Configure the sender

```php
// site/config/config.php
return [
    // Required. Cron has no HTTP host to infer a URL from, so without this
    // every unsubscribe link in a sent email comes out wrong. This is the most
    // common cause of broken links in cron-sent mail.
    'url' => 'https://www.example.com',

    'e9li.kirby-newsletter' => [
        'from'          => 'news@news.example.com',
        'fromName'      => 'Example Newsletter',
        'sendingDomain' => 'news.example.com',
        'address'       => "Example GmbH\nMusterstrasse 1\n8000 Zürich",
    ],
];
```

### 2. Create the page structure

```
content/newsletter/newsletter.txt              (template: newsletter)
content/newsletter/lists/newsletter-lists.txt  (template: newsletter-lists)
content/newsletter/lists/main/newsletter-list.txt
```

Or build it in the Panel: add a `newsletter` page, a `newsletter-lists` child,
then lists inside it. Tick **“Offer on public subscribe forms”** on any list a
visitor may choose for themselves.

### 3. Add the form

```php
<?= snippet('newsletter/form') ?>
```

Options: `lists`, `heading`, `privacyUrl`, `showName`, `requireConsent`.
Override any markup by placing your own file at
`site/snippets/newsletter/form.php` — Kirby resolves site snippets first.

## How it works

| Concern | Where it lives | Why |
|---|---|---|
| Lists, campaigns, templates | Kirby pages | Few of them, human-edited, translatable — versioning, drafts and permissions come free |
| Subscribers, subscriptions, queue, events | SQLite (`site/newsletter/newsletter.sqlite`) | Thousands of rows, indexed queries, concurrent writes; content files are the wrong tool |

Subscriptions reference lists by **UUID**, so renaming or moving a list page
never orphans its subscribers.

### Two kinds of token, on purpose

**Unsubscribe / preferences** are HMAC tokens: `v1.<id>.<lang>.<scope>.<sig>`.
Stateless, unguessable (128-bit), verified with one HMAC and resolved by primary
key. They never expire, because an unsubscribe link that stops working turns
into a spam complaint. Rotating the secret keeps old links alive via
`secretLegacy`.

**Double opt-in** tokens are the mirror image: 32 random bytes, stored only as
`sha256()`, single-use, expiring. A database leak therefore cannot be used to
confirm subscriptions on someone else's behalf.

The purpose is part of the signed payload, so an unsubscribe token can never be
replayed against the preferences route.

### One-click unsubscribe

The same URL behaves differently by method, and this matters:

- **GET** → shows a confirmation page. It must **not** unsubscribe: mail-client link scanners, corporate security gateways and browser prefetchers all issue GETs, and any of them would otherwise unsubscribe people silently.
- **POST** → acts immediately and returns a bare `200`, as RFC 8058 requires. This route is deliberately exempt from CSRF checks — it is an unauthenticated cross-origin POST from the mail provider by design.

## Deliverability

Not yet enforced by code (that arrives with sending in v0.4), but it decides
whether your mail is delivered at all. Set it up before the first send.

Send from a **dedicated subdomain** (`news.example.com`), so a reputation
problem never touches your corporate transactional mail.

| Record | Requirement |
|---|---|
| **SPF** | `v=spf1 include:<relay> -all`, ≤10 lookups, covering the *envelope* sender |
| **DKIM** | 2048-bit, own selector, signing `From, To, Subject, Date, Message-ID, MIME-Version, Content-Type, List-Unsubscribe, List-Unsubscribe-Post` |
| **DMARC** | Roll out `p=none; rua=…` → `p=quarantine; pct=…` → `p=reject`. Required for bulk senders. `From` must align with SPF and/or DKIM `d=`. |
| **rDNS/PTR** | Matching the HELO name, with a matching forward A record |
| **MX** | On the sending domain — bounces, FBL reports and `mailto:` unsubscribes have to land somewhere |
| **TLS** | Port 587 with a valid certificate |

**Google/Yahoo bulk-sender rules (2024)** apply above 5,000/day to a single
provider: aligned SPF+DKIM+DMARC, one-click unsubscribe honoured within two
days, and a spam-complaint rate below 0.3% (aim for under 0.1%).

The default rate of **600 messages/hour** sends 5,000 recipients in about 8.5
hours and keeps you under 5,000/day to any one ISP.

### Never import a purchased list

This is the single biggest blacklisting risk. Bought, scraped and rented lists
contain recycled spam traps, and one trap hit can null-route your domain. There
is no recovery that does not involve changing domains. Only mail people who
asked you to.

## Commands

| Command | What it does |
|---|---|
| `kirby newsletter:migrate [--pretend]` | Create or update the schema |
| `kirby newsletter:status` | Database, configuration and secret status |
| `kirby newsletter:smoke` | Run the storage test suite against a throwaway database |
| `kirby newsletter:render <page-id> [--language=de]` | Write HTML and plain text under `site/newsletter/artifacts` |
| `kirby newsletter:tick` | Send the next batch. Call this from cron every minute |
| `kirby newsletter:reconsent <list-id>` | Queue opt-in mail for people marked pending on that list |

## Options

All under `e9li.kirby-newsletter.*`. See [`src/options.php`](src/options.php)
for the full annotated list. The ones you will actually touch:

| Option | Default | Purpose |
|---|---|---|
| `from` / `fromName` | `null` | Sender identity. Required. |
| `sendingDomain` | host of `from` | Used for Message-ID and List-Id |
| `address` | `null` | Physical postal address for the footer (CAN-SPAM, UWG Art. 3 lit. o) |
| `root` | `site/newsletter` | Move outside the docroot on a stock Kirby layout |
| `rate.perHour` | `600` | The main throttle knob |
| `optin.required` | `true` | Double opt-in. Always on for public lists. |
| `optin.validateMx` | `true` | Reject typo'd domains at signup, before they can bounce |
| `privacy.storeIp` | `anonymize` | `full` \| `anonymize` \| `none` |
| `track.opens` / `track.clicks` | `false` | Off by default — both are personal-data processing that needs disclosure |

## Privacy and law

Written for a Swiss sender, so it targets the strictest of the applicable rules:

- **UWG Art. 3 lit. o** — mass advertising email requires prior consent, correct sender identification and a free, easy refusal option in *every* message. Breach is a criminal offence under Art. 23.
- **revDSG** — a reachable privacy notice; tracking needs transparency.
- **GDPR Art. 7(1)** — you must be able to *demonstrate* consent. The plugin records timestamp, anonymised IP, user agent and source, per person and per list.
- **CAN-SPAM** — a valid physical postal address and an opt-out honoured within 10 business days.

Deleting a person from the Panel (with the suppression toggle on) or from the
preference centre removes the address and keeps a hash-only suppression row,
so a stale CSV cannot resurrect someone who asked to be forgotten.

## Local development

[Mailpit](https://mailpit.axllent.org) is the recommended mail sink — real SMTP,
real MIME, web UI on `:8025`:

```php
'email' => ['transport' => [
    'type' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025,
    'security' => false, 'auth' => false,
]],
```

Set `'e9li.kirby-newsletter.optin.validateMx' => false` locally, or every
address at a `.test` domain is rejected.

## Roadmap

| Version | Scope |
|---|---|
| **0.1** | Lists, double opt-in, unsubscribe, preferences, storage |
| **0.5** | Panel area, subscriber browser, CSV import/export (brought forward) |
| **0.2** | Layout-field → email HTML + plain text, theme tokens, Panel preview |
| **0.3** | SMTP keep-alive and a log outbox. Postal and sendmail are not drivers yet |
| **0.4** | Preflight, queue, cron tick, hourly throttle, domain defer. No warmup ramp |
| **0.7 (part)** | Consent sentence hash, suppression browser, self-service export and deletion, reconsent |
| **0.8 (part)** | Delivered count and an optional open pixel. No click wrapping |
| later | Webhooks, complaint auto-pause, inactive-subscriber housekeeping, warmup |

## Notes for contributors

Things that cost real debugging time, recorded so they cost it only once:

- Classes load through Kirby's `load()` map in `index.php`; there is no Composer autoloader.
- **Route actions and `beforeSend` must not be `static` closures.** Kirby binds `$this` into both with `Closure::bindTo()` / `Closure::call()`, which is an error for static closures on PHP 8.5+.
- **Never use `static::` inside a route, view or dialog handler.** Kirby rebinds those closures to `Kirby\Http\Route`, so `static::foo()` resolves to `Route::foo()` — and `Route::__call()` returns **null** for any unknown method rather than erroring. A typo becomes a silent null. `Panel\Views` and `Panel\Dialogs` therefore use explicit class names, and every helper a handler reaches is `public` (a `protected` method is not callable from the rebound scope).
- **There is no PHP `icons` extension in Kirby 5.** Icons register through JS: `panel.plugin(..., { icons: { name: '<path …>' } })`, on a hard-coded `0 0 24 24` viewBox, as bare SVG children. The Panel already ships 225 RemixIcon glyphs (`kirby/panel/dist/img/icons.svg`), so check there before adding one.
- **There is no `views` key in `panel.plugin()`.** A view is an ordinary global component referenced by name from the PHP area route.
- `k-table`'s `columns` must be a keyed **object**, its `empty` a plain **string**, and it has no built-in column sorting — `sortable`/`@sort` are drag reordering, so click-to-sort has to be wired from `@header` (whose `columnIndex` is the column *key*). It also renders every row it is given, so pagination must be server-side.
- `k-item` takes **`text`**, not `title`. Passing `title` renders "undefined".
- **Never call `$block->toHtml()` or echo a block in email context.** `Kirby\Cms\Block::toHtml()` hardcodes `snippet('blocks/' . $type)`, which would render your *website* markup into an email.
- `Kirby\Database\Database` swallows SQL errors unless `->fail(true)` is set. This plugin always sets it: the queue's compare-and-swap claim depends on a failed UPDATE being distinguishable from a no-op.

Run `kirby newsletter:smoke` after touching anything in `src/Repository/`.

## License

MIT © [Rafael Egli](https://www.e9li.com)
