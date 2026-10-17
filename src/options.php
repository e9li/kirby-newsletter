<?php

/**
 * Plugin option defaults.
 *
 * Keys are flat and dot-separated so a project can override a single leaf
 * (`'e9li.kirby-newsletter.smtp.host' => '…'`) without restating the group.
 */
return [

    // ── Storage & security ──────────────────────────────────────────────────

    // Directory holding the SQLite database, secret, locks and artifacts.
    // null = site/newsletter. On a stock Kirby layout `site/` sits inside the
    // docroot, so move this somewhere unreachable on anything serious.
    'root' => null,

    // HMAC key for unsubscribe/preferences/archive tokens.
    // null = generate once and persist to <root>/secret.key.
    'secret' => null,

    // Keys retired by a rotation. Old unsubscribe links still verify against
    // these, because a link that stops working turns into a spam complaint.
    'secretLegacy' => [],

    // Apply pending migrations automatically instead of refusing to write.
    'migrate.auto' => false,

    // ── Logging ─────────────────────────────────────────────────────────────

    'log.level' => 'info',       // debug | info | warning | error
    'log.file'  => null,         // null = <kirby logs root>/newsletter.log

    // ── Content structure ───────────────────────────────────────────────────

    'page'          => 'newsletter',
    'campaignsPage' => 'newsletter/campaigns',
    'listsPage'     => 'newsletter/lists',
    'templatesPage' => 'newsletter/templates',
    'templatesRoot' => null,     // null = site/newsletter-templates

    // ── Sender identity ─────────────────────────────────────────────────────

    'from'      => null,         // 'news@news.example.com' — required, no sane default
    'fromName'  => null,
    'replyTo'   => null,         // null = from
    'returnPath' => null,        // envelope sender; null = 'bounce@' . sendingDomain

    // Dedicated sending subdomain, so a reputation problem never touches the
    // corporate domain's transactional mail. null = host part of `from`.
    'sendingDomain' => null,
    'listId'        => null,     // List-Id header; null = sendingDomain

    // Physical postal address printed in every footer.
    // Required by CAN-SPAM, and part of correct sender identification under
    // Swiss UWG Art. 3 lit. o.
    'address' => null,

    // ── Transport ───────────────────────────────────────────────────────────

    // postal | smtp | sendmail | kirby | log. null = log when debug is on, else smtp.
    'transport' => null,

    'postal.url'           => null,
    'postal.key'           => null,
    'postal.tag'           => 'newsletter',
    'postal.webhookSecret' => null,
    'postal.timeout'       => 20,

    'smtp.host'     => null,
    'smtp.port'     => 587,
    'smtp.security' => 'tls',    // tls | ssl | false
    'smtp.auth'     => true,
    'smtp.username' => null,
    'smtp.password' => null,
    'smtp.timeout'  => 30,

    // One connection per tick instead of one per message. Do not disable for
    // bulk sending: 5000 connections will get you rate-limited or blocked.
    'smtp.keepAlive' => true,

    // HELO name. Set it — the OS hostname is wrong on most shared hosts, and a
    // HELO that does not resolve is a strong spam signal.
    'smtp.localDomain'      => null,
    'smtp.maxPerConnection' => 200,

    'sendmail.path' => '/usr/sbin/sendmail -bs',

    'log.outbox' => null,        // null = <root>/outbox

    // ── Throttling & queue ──────────────────────────────────────────────────

    // The main tuning knob. 600/h sends 5000 recipients in ~8.5 h and keeps
    // the volume to any single ISP under the 5000/day bulk-sender threshold.
    'rate.perHour'   => 600,
    'rate.batchSize' => 12,      // messages per cron tick, ±20% jitter
    'rate.perDomainPerHour' => [],

    'tick.maxSeconds'  => 50,    // wall-clock budget for a 1-minute cron
    'tick.delayMinMs'  => 250,   // inter-message jitter
    'tick.delayMaxMs'  => 1500,
    'tick.claimTimeout' => 900,  // seconds before a stuck claim is reaped
    'tick.httpSecret'  => null,  // enables POST /newsletter/tick where there is no cron

    'queue.maxAttempts' => 3,
    'queue.backoff'     => [300, 1800, 7200],

    'seed.order'     => 'engagement',   // engagement | random | id
    'seed.chunkSize' => 1000,

    // ── Warmup ──────────────────────────────────────────────────────────────

    'warmup.enabled'   => true,
    'warmup.startedAt' => null,
    'warmup.ramp'      => null,  // null = Send\Warmup::RAMP

    // ── Opt-in & consent ────────────────────────────────────────────────────

    'optin.required'   => true,  // double opt-in for public forms
    'optin.ttl'        => 604800,
    'optin.maxResends' => 3,
    'optin.validateMx' => true,
    'optin.blockDisposable' => true,

    // Anti-listbombing. None of these is the real gate — the confirmation mail
    // is — but they keep obvious bot signups out of the database entirely.
    'optin.honeypot'         => 'website',
    'optin.minFillSeconds'   => 3,
    'optin.rateLimitPerHour' => 10,

    // ── Tracking ────────────────────────────────────────────────────────────

    // Both off by default: an open pixel is personal-data processing that needs
    // disclosure, and a click redirector on the wrong domain costs deliverability.
    'track.opens'       => false,
    'track.clicks'      => false,
    'track.clickDomain' => null,

    // ── Bounces & hygiene ───────────────────────────────────────────────────

    'bounce.softStrikes'  => 3,
    'bounce.strikeWindow' => 2592000,
    'domain.deferBackoff' => [300, 900, 1800, 3600, 7200],

    'hygiene.inactiveDays'       => 365,
    'hygiene.reengageGraceDays'  => 60,
    'hygiene.pruneExpiredOptins' => true,

    'events.retentionDays' => 400,   // 13 months, so year-on-year still compares
    'events.storePayload'  => false,

    // ── Alerts ──────────────────────────────────────────────────────────────

    'alerts.email'             => null,
    'alerts.complaintRate'     => 0.001,   // 0.1%, well under Google's 0.3% ceiling
    'alerts.bounceRate'        => 0.05,
    'alerts.pauseOnComplaints' => true,
    'alerts.pauseOnBlock'      => true,

    // Own addresses at the big providers, added to every send so inbox
    // placement is observable from the inside.
    'seedRecipients' => [],

    // ── Privacy ─────────────────────────────────────────────────────────────

    'privacy.storeIp' => 'anonymize',   // full | anonymize | none

    // Keep sha256(email) after an erasure so a stale CSV cannot resurrect
    // someone who asked to be forgotten.
    'gdpr.keepSuppressionHash' => true,

    // ── Rendering ───────────────────────────────────────────────────────────

    'render.maxWidth'          => 600,
    'render.warnBytes'         => 102400,   // Gmail clips above ~102 KB
    'render.retina'            => false,
    'render.allowCustomColors' => false,
    'render.assetDir'          => null,
    'render.defaultTemplate'   => null,

    // ── Frontend ────────────────────────────────────────────────────────────

    'routes.prefix'  => 'newsletter',
    'routes.enabled' => true,

    // Per-language string overrides, deep-merged over the plugin defaults.
    'translations' => [],
];
