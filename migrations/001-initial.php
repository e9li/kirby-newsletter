<?php

use Kirby\Database\Database;

/**
 * Initial schema.
 *
 * Conventions used throughout:
 *  - timestamps are unix integers, not SQLite datetimes, so comparisons and
 *    arithmetic need no date functions
 *  - booleans are 0/1 integers
 *  - a subscriber's identity is `email_norm`; `email_hash` is what survives a
 *    GDPR erasure so a stale CSV cannot resurrect someone
 */
return function (Database $db): void {
    $exec = static function (string $sql) use ($db): void {
        $db->execute($sql);
    };

    // ── Subscribers ─────────────────────────────────────────────────────────
    $exec('CREATE TABLE nl_subscribers (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        email           TEXT    NOT NULL,
        email_norm      TEXT    NOT NULL,
        email_hash      TEXT    NOT NULL,
        name            TEXT    NULL,
        language        TEXT    NULL,
        status          TEXT    NOT NULL DEFAULT "pending",
        source          TEXT    NULL,
        source_detail   TEXT    NULL,
        signup_at       INTEGER NULL,
        signup_ip       TEXT    NULL,
        signup_ua       TEXT    NULL,
        confirmed_at    INTEGER NULL,
        confirm_ip      TEXT    NULL,
        confirm_ua      TEXT    NULL,
        unsubscribed_at INTEGER NULL,
        bounce_score    INTEGER NOT NULL DEFAULT 0,
        last_sent_at    INTEGER NULL,
        last_open_at    INTEGER NULL,
        last_click_at   INTEGER NULL,
        meta            TEXT    NULL,
        created_at      INTEGER NOT NULL,
        updated_at      INTEGER NOT NULL
    )');

    $exec('CREATE UNIQUE INDEX nl_subscribers_norm ON nl_subscribers (email_norm)');
    $exec('CREATE INDEX nl_subscribers_hash        ON nl_subscribers (email_hash)');
    $exec('CREATE INDEX nl_subscribers_status_lang ON nl_subscribers (status, language)');
    $exec('CREATE INDEX nl_subscribers_engagement  ON nl_subscribers (last_open_at)');

    // ── Subscriptions ───────────────────────────────────────────────────────
    // Many-to-many with a per-list status AND a per-list language override, so
    // someone can take the German main list and the French events list.
    $exec('CREATE TABLE nl_subscriptions (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        subscriber_id     INTEGER NOT NULL REFERENCES nl_subscribers(id) ON DELETE CASCADE,
        list_uuid         TEXT    NOT NULL,
        status            TEXT    NOT NULL DEFAULT "pending",
        language          TEXT    NULL,
        optin_token_hash  TEXT    NULL,
        optin_expires_at  INTEGER NULL,
        optin_sent_at     INTEGER NULL,
        optin_resends     INTEGER NOT NULL DEFAULT 0,
        confirmed_at      INTEGER NULL,
        confirm_ip        TEXT    NULL,
        unsubscribed_at   INTEGER NULL,
        unsub_reason      TEXT    NULL,
        unsub_campaign_id INTEGER NULL,
        created_at        INTEGER NOT NULL,
        updated_at        INTEGER NOT NULL
    )');

    $exec('CREATE UNIQUE INDEX nl_subs_pair  ON nl_subscriptions (subscriber_id, list_uuid)');
    $exec('CREATE INDEX nl_subs_list_status  ON nl_subscriptions (list_uuid, status, language)');
    $exec('CREATE INDEX nl_subs_optin        ON nl_subscriptions (optin_token_hash)');
    $exec('CREATE INDEX nl_subs_optin_expiry ON nl_subscriptions (optin_expires_at)');

    // ── Suppressions ────────────────────────────────────────────────────────
    // The single gate every send passes through. Keyed on the hash so a row
    // survives erasure of the address itself.
    $exec('CREATE TABLE nl_suppressions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        email_hash  TEXT    NOT NULL,
        email_norm  TEXT    NULL,
        reason      TEXT    NOT NULL,
        detail      TEXT    NULL,
        source      TEXT    NULL,
        campaign_id INTEGER NULL,
        created_at  INTEGER NOT NULL,
        expires_at  INTEGER NULL
    )');

    $exec('CREATE UNIQUE INDEX nl_suppressions_hash ON nl_suppressions (email_hash)');
    $exec('CREATE INDEX nl_suppressions_reason      ON nl_suppressions (reason, created_at)');

    // ── Campaigns ───────────────────────────────────────────────────────────
    // One row per (campaign page × language). `send_uuid` groups the language
    // rows of a single send; `artifact_key` points at the frozen render.
    $exec('CREATE TABLE nl_campaigns (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        send_uuid     TEXT    NOT NULL,
        page_uuid     TEXT    NOT NULL,
        page_id       TEXT    NOT NULL,
        language      TEXT    NULL,
        subject       TEXT    NOT NULL,
        preheader     TEXT    NULL,
        from_email    TEXT    NOT NULL,
        from_name     TEXT    NULL,
        reply_to      TEXT    NULL,
        theme_uuid    TEXT    NULL,
        artifact_key  TEXT    NULL,
        status        TEXT    NOT NULL DEFAULT "draft",
        scheduled_at  INTEGER NULL,
        started_at    INTEGER NULL,
        finished_at   INTEGER NULL,
        total         INTEGER NOT NULL DEFAULT 0,
        sent_count    INTEGER NOT NULL DEFAULT 0,
        failed_count  INTEGER NOT NULL DEFAULT 0,
        transport     TEXT    NULL,
        rate_per_hour INTEGER NULL,
        track_opens   INTEGER NOT NULL DEFAULT 0,
        track_clicks  INTEGER NOT NULL DEFAULT 0,
        is_test       INTEGER NOT NULL DEFAULT 0,
        created_by    TEXT    NULL,
        created_at    INTEGER NOT NULL,
        updated_at    INTEGER NOT NULL
    )');

    $exec('CREATE UNIQUE INDEX nl_campaigns_send ON nl_campaigns (send_uuid, language)');
    $exec('CREATE INDEX nl_campaigns_page        ON nl_campaigns (page_uuid)');
    $exec('CREATE INDEX nl_campaigns_status      ON nl_campaigns (status, scheduled_at)');

    // Frozen list membership: the authoring reference lives on the page, but a
    // running send must not change because someone edited the page.
    $exec('CREATE TABLE nl_campaign_lists (
        campaign_id INTEGER NOT NULL REFERENCES nl_campaigns(id) ON DELETE CASCADE,
        list_uuid   TEXT    NOT NULL,
        PRIMARY KEY (campaign_id, list_uuid)
    )');

    // ── Queue ───────────────────────────────────────────────────────────────
    // One row per message. `message_id` is minted here, at seed time, and
    // reused on every retry: that is what lets a receiving MTA collapse a
    // duplicate caused by a crash between handoff and status write.
    $exec('CREATE TABLE nl_queue (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id   INTEGER NOT NULL REFERENCES nl_campaigns(id) ON DELETE CASCADE,
        subscriber_id INTEGER NOT NULL,
        email         TEXT    NOT NULL,
        name          TEXT    NULL,
        language      TEXT    NULL,
        domain        TEXT    NOT NULL,
        list_uuid     TEXT    NULL,
        artifact_key  TEXT    NOT NULL,
        message_id    TEXT    NOT NULL,
        status        TEXT    NOT NULL DEFAULT "queued",
        attempts      INTEGER NOT NULL DEFAULT 0,
        worker        TEXT    NULL,
        claimed_at    INTEGER NULL,
        available_at  INTEGER NOT NULL,
        priority      INTEGER NOT NULL DEFAULT 0,
        sent_at       INTEGER NULL,
        provider_id   TEXT    NULL,
        error_code    TEXT    NULL,
        error         TEXT    NULL,
        created_at    INTEGER NOT NULL,
        updated_at    INTEGER NOT NULL
    )');

    // Dedupes a subscriber who sits on two lists of the same campaign, for free,
    // via INSERT OR IGNORE at seed time.
    $exec('CREATE UNIQUE INDEX nl_queue_pair  ON nl_queue (campaign_id, subscriber_id)');
    $exec('CREATE UNIQUE INDEX nl_queue_msgid ON nl_queue (message_id)');
    $exec('CREATE INDEX nl_queue_pick     ON nl_queue (campaign_id, status, available_at, priority)');
    $exec('CREATE INDEX nl_queue_claim    ON nl_queue (status, worker, claimed_at)');
    $exec('CREATE INDEX nl_queue_domain   ON nl_queue (domain, status)');
    $exec('CREATE INDEX nl_queue_provider ON nl_queue (provider_id)');

    // ── Events (append-only) ────────────────────────────────────────────────
    $exec('CREATE TABLE nl_events (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id   INTEGER NULL,
        queue_id      INTEGER NULL,
        subscriber_id INTEGER NULL,
        email_hash    TEXT    NULL,
        type          TEXT    NOT NULL,
        severity      TEXT    NULL,
        code          TEXT    NULL,
        detail        TEXT    NULL,
        url           TEXT    NULL,
        ip            TEXT    NULL,
        user_agent    TEXT    NULL,
        transport     TEXT    NULL,
        provider_id   TEXT    NULL,
        payload       TEXT    NULL,
        created_at    INTEGER NOT NULL
    )');

    $exec('CREATE INDEX nl_events_campaign   ON nl_events (campaign_id, type, created_at)');
    $exec('CREATE INDEX nl_events_subscriber ON nl_events (subscriber_id, created_at)');
    $exec('CREATE INDEX nl_events_queue      ON nl_events (queue_id)');
    $exec('CREATE INDEX nl_events_provider   ON nl_events (provider_id)');
    $exec('CREATE INDEX nl_events_created    ON nl_events (created_at)');

    // ── Webhook idempotency ─────────────────────────────────────────────────
    // Providers retry; without this, one bounce could suppress twice and skew
    // every rate on the dashboard.
    $exec('CREATE TABLE nl_webhook_events (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        transport   TEXT    NOT NULL,
        external_id TEXT    NOT NULL,
        received_at INTEGER NOT NULL
    )');

    $exec('CREATE UNIQUE INDEX nl_webhook_unique ON nl_webhook_events (transport, external_id)');

    // ── Rate limiting ───────────────────────────────────────────────────────
    // Buckets are "global:YmdH", "day:Ymd" and "<domain>:YmdH", incremented
    // with an atomic upsert so concurrent ticks cannot lose a count.
    $exec('CREATE TABLE nl_throttle (
        bucket     TEXT PRIMARY KEY,
        count      INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL
    )');

    $exec('CREATE TABLE nl_domain_state (
        domain      TEXT PRIMARY KEY,
        defer_until INTEGER NULL,
        defer_count INTEGER NOT NULL DEFAULT 0,
        last_code   TEXT    NULL,
        last_error  TEXT    NULL,
        updated_at  INTEGER NOT NULL
    )');

    // ── Key/value state ─────────────────────────────────────────────────────
    $exec('CREATE TABLE nl_state (
        key        TEXT PRIMARY KEY,
        value      TEXT NULL,
        updated_at INTEGER NOT NULL
    )');
};
