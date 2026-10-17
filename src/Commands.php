<?php

namespace E9li\Newsletter;

use E9li\Newsletter\Render\Document;
use E9li\Newsletter\Send\Runner;
use E9li\Newsletter\Send\Seeder;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Throwable;

/**
 * CLI commands, registered through Kirby's `commands` plugin extension and run
 * by `getkirby/cli` (`kirby newsletter:<name>`).
 *
 * The cron entry point lives here rather than behind an HTTP route because a
 * send takes hours and must not be bounded by max_execution_time.
 */
final class Commands
{
    public static function all(): array
    {
        return [
            'newsletter:migrate'   => static::migrate(),
            'newsletter:status'    => static::status(),
            'newsletter:smoke'     => static::smoke(),
            'newsletter:render'    => static::render(),
            'newsletter:tick'      => static::tick(),
            'newsletter:reconsent' => static::reconsent(),
        ];
    }

    protected static function render(): array
    {
        return [
            'description' => 'Render a campaign to HTML and plain text under site/newsletter/artifacts',
            'args' => [
                'page' => [
                    'description' => 'Campaign page id',
                ],
                'language' => [
                    'prefix'      => 'l',
                    'longPrefix'  => 'language',
                    'description' => 'Language code',
                ],
            ],
            'command' => static function ($cli): void {
                $id = (string)$cli->arg('page');
                $page = App::instance()->page($id);

                if ($page === null) {
                    $cli->error(' Campaign not found: ' . $id . ' ');
                    exit(1);
                }

                $language = $cli->arg('language');
                $language = is_string($language) && $language !== '' ? $language : null;
                $rendered = Document::render($page, $language);
                $code = $language ?? 'default';
                $dir = Newsletter::root() . '/artifacts';
                Dir::make($dir, true);
                $html = $dir . '/' . $code . '.html';
                $text = $dir . '/' . $code . '.txt';
                F::write($html, $rendered['html']);
                F::write($text, $rendered['text']);
                $cli->success(' Wrote ' . $html);
                $cli->out('  ' . $text . ' (' . $rendered['bytes'] . ' bytes)');
            },
        ];
    }

    protected static function tick(): array
    {
        return [
            'description' => 'Send the next batch from the queue',
            'command' => static function ($cli): void {
                $result = Runner::tick();
                $cli->out('claimed ' . $result['claimed'] . ', sent ' . $result['sent'] . ', failed ' . $result['failed']);
            },
        ];
    }

    protected static function reconsent(): array
    {
        return [
            'description' => 'Queue opt-in mail for subscribers marked pending on a list',
            'args' => [
                'list' => [
                    'description' => 'List page id',
                ],
            ],
            'command' => static function ($cli): void {
                $id = (string)$cli->arg('list');
                $page = App::instance()->page($id);

                if ($page === null) {
                    $cli->error(' List not found: ' . $id . ' ');
                    exit(1);
                }

                $result = Seeder::reconsent($page);

                if ($result['ok'] !== true) {
                    $cli->error(' ' . (string)$result['error'] . ' ');
                    exit(1);
                }

                $cli->success(' Queued ' . $result['queued'] . '. Run kirby newsletter:tick to send. ');
            },
        ];
    }

    /**
     * Runs the storage-layer smoke test against a throwaway database.
     *
     * Points the plugin root at a temp dir first, so the test can never write
     * to — or delete — the project's real subscriber data.
     */
    protected static function smoke(): array
    {
        return [
            'description' => 'Run the newsletter storage smoke test (uses a temporary database)',
            'command' => static function ($cli): void {
                $root = sys_get_temp_dir() . '/kirby-newsletter-smoke-' . bin2hex(random_bytes(4));

                // Rebind the root before anything touches the database.
                Database::flush();
                Secret::flush();
                Newsletter::useRoot($root);

                if (Newsletter::root() !== $root) {
                    $cli->error(' Could not redirect the data root to a temp dir. Aborting. ');
                    exit(1);
                }

                $cli->br();
                $cli->bold('Newsletter — smoke test');
                $cli->out('  ' . $root);
                $cli->br();

                $passed = 0;
                $failed = [];

                $assert = static function (bool $ok, string $message) use (&$passed, &$failed, $cli): void {
                    if ($ok === true) {
                        $passed++;

                        return;
                    }

                    $failed[] = $message;
                    $cli->out('  <red>✗</red> ' . $message);
                };

                $section = static function (string $title) use ($cli): void {
                    $cli->out('  <yellow>' . $title . '</yellow>');
                };

                try {
                    $test = require dirname(__DIR__) . '/tests/smoke.php';
                    $test($assert, $section);
                } catch (Throwable $e) {
                    $cli->br();
                    $cli->error(' ' . $e::class . ': ' . $e->getMessage() . ' ');
                    $cli->out('  ' . $e->getFile() . ':' . $e->getLine());
                    $failed[] = 'uncaught exception';
                } finally {
                    \Kirby\Filesystem\Dir::remove($root);
                }

                $cli->br();

                if ($failed === []) {
                    $cli->success(' ' . $passed . ' assertions passed ');
                    $cli->br();

                    return;
                }

                $cli->error(' ' . count($failed) . ' of ' . ($passed + count($failed)) . ' assertions failed ');
                $cli->br();

                exit(1);
            },
        ];
    }

    protected static function migrate(): array
    {
        return [
            'description' => 'Create or update the newsletter database schema',
            'args' => [
                'pretend' => [
                    'longPrefix'  => 'pretend',
                    'description' => 'List pending migrations without applying them',
                    'noValue'     => true,
                ],
            ],
            'command' => static function ($cli): void {
                $cli->br();
                $cli->bold('Newsletter — migrate');
                $cli->br();

                $pretend = $cli->arg('pretend') === true;

                try {
                    // Bypass the guard: this command exists precisely to fix
                    // the state the guard refuses to write in.
                    Database::flush();
                    Newsletter::bootstrap();

                    $db = new \Kirby\Database\Database([
                        'type'     => 'sqlite',
                        'database' => Database::file(),
                        'id'       => 'e9li-newsletter-migrate',
                    ]);
                    $db->fail(true);
                    $db->connection()->exec('PRAGMA foreign_keys = ON');

                    $pending = Migrations::pending($db);

                    if ($pending === []) {
                        $cli->out('Schema is up to date (version ' . Migrations::SCHEMA . ').');
                        $cli->br();

                        return;
                    }

                    $cli->out('Pending: ' . implode(', ', $pending));
                    $cli->br();

                    $ran = Migrations::run($db, $pretend);
                    Database::secure();

                    foreach ($ran as $name) {
                        $cli->out(($pretend === true ? '  would run  ' : '  applied    ') . $name);
                    }

                    $cli->br();

                    if ($pretend === true) {
                        $cli->out('Nothing was changed.');
                    } else {
                        $cli->success(' Schema is now at version ' . Migrations::SCHEMA . ' ');
                        Log::info('Migrations applied', ['migrations' => $ran]);
                    }

                    $cli->br();
                } catch (Throwable $e) {
                    $cli->br();
                    $cli->error(' ' . $e->getMessage() . ' ');
                    $cli->br();
                    Log::exception($e, 'Migration failed');

                    exit(1);
                }
            },
        ];
    }

    protected static function status(): array
    {
        return [
            'description' => 'Show newsletter database and configuration status',
            'command' => static function ($cli): void {
                $cli->br();
                $cli->bold('Newsletter — status');
                $cli->br();

                $rows = [
                    ['Plugin version', Newsletter::VERSION],
                    ['Data root', Newsletter::root()],
                    ['Database', Database::exists() === true ? 'present' : 'missing'],
                ];

                if (Database::exists() === true) {
                    $rows[] = ['Schema version', (string)Migrations::marker()];
                    $rows[] = ['Size', number_format(Database::size() / 1024, 1) . ' KB'];

                    try {
                        $rows[] = ['Journal mode', Database::journalMode()];
                        $rows[] = ['Subscribers', (string)Database::value(
                            'SELECT COUNT(*) FROM nl_subscribers WHERE status = "active"'
                        )];
                        $rows[] = ['Suppressed', (string)Database::value(
                            'SELECT COUNT(*) FROM nl_suppressions'
                        )];
                    } catch (Throwable $e) {
                        $rows[] = ['Error', $e->getMessage()];
                    }
                }

                $rows[] = ['Secret', Secret::exists() === true ? 'present' : 'not created yet'];
                $rows[] = ['From', (string)(Newsletter::option('from') ?? '— not configured —')];
                $rows[] = ['Transport', (string)(Newsletter::option('transport') ?? 'auto')];
                $rows[] = ['Rate', Newsletter::option('rate.perHour') . '/h, batch '
                    . Newsletter::option('rate.batchSize')];
                $rows[] = ['Languages', implode(', ', array_map(
                    static fn ($code) => $code ?? 'single',
                    Newsletter::languages()
                ))];

                foreach ($rows as [$label, $value]) {
                    $cli->out(sprintf('  %-16s %s', $label, $value));
                }

                $cli->br();
            },
        ];
    }
}
