<?php

/**
 * Minimal standalone page wrapper for the confirm / unsubscribe / preferences
 * routes.
 *
 * These routes must work the moment the plugin is installed, before any
 * newsletter pages exist — so they render their own document rather than
 * relying on the site's templates. Override by placing your own
 * `site/snippets/newsletter/layout.php`, which Kirby resolves first.
 *
 * @var string $title
 * @var string $content
 */

$kirby = kirby();

?><!DOCTYPE html>
<html lang="<?= esc($kirby->language()?->code() ?? 'en', 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* These pages are reached from an email link and carry a token in
             the URL. Keep them out of search indexes, and out of the Referer
             header so the token never leaks to a third-party site. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= esc($title) ?> – <?= esc($kirby->site()->title()) ?></title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; padding: 2rem 1.25rem;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #18181b; background: #f4f4f5;
        }
        .nl-page { max-width: 34rem; margin: 0 auto; background: #fdfdfd;
                   padding: 2rem; border-radius: .5rem; }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; line-height: 1.25; }
        p { margin: 0 0 1rem; }
        button, .nl-button {
            display: inline-block; padding: .75rem 1.25rem; border: 0;
            border-radius: .375rem; background: #18181b; color: #fdfdfd;
            font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        button:hover, .nl-button:hover { background: #3f3f46; }
        .nl-note { font-size: .875rem; color: #71717a; }
        .nl-error { color: #b91c1c; }
        label { display: block; margin: 0 0 .75rem; }
        @media (prefers-color-scheme: dark) {
            body { color: #e4e4e7; background: #0b0b0d; }
            .nl-page { background: #18181b; }
            button, .nl-button { background: #e4e4e7; color: #18181b; }
            .nl-note { color: #a1a1aa; }
        }
    </style>
</head>
<body>
    <main class="nl-page">
        <h1><?= esc($title) ?></h1>
        <?= $content ?>
    </main>
</body>
</html>
