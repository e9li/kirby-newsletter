<?php

namespace E9li\Newsletter\Panel;

use E9li\Newsletter\Csv;
use E9li\Newsletter\EmailAddress;
use E9li\Newsletter\Lists;
use E9li\Newsletter\Log;
use E9li\Newsletter\Model\Subscription;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Repository\Subscribers;
use E9li\Newsletter\Repository\Subscriptions;
use E9li\Newsletter\Repository\Suppressions;
use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Response;
use Kirby\Toolkit\I18n;

/**
 * Dialogs for the newsletter area.
 *
 * A dialog's `load` returns only `component` and `props` — `size`,
 * `submitButton` and `fields` all live inside `props`. `submit` returns
 * `message` / `event` / `redirect`, which the client turns into a toast,
 * a Panel event and navigation respectively.
 *
 * The import is split across three dialogs on purpose: upload, then map +
 * dry-run, then confirm. Each step is reversible until the last one, and the
 * last one shows exactly what it is about to do.
 *
 * NOTE: this file uses the explicit `Dialogs::` rather than the plugin's usual
 * `static::`. Kirby invokes route, dialog and view handlers with
 * Closure::call($route), which rebinds their scope — so `static::` inside one
 * of them resolves to Kirby\Http\Route, whose __call() magic method returns
 * null for any unknown method instead of erroring. That turns a typo'd or
 * rebound call into a silent null rather than a fatal.
 *
 * For the same reason every helper reachable from a handler is public: a
 * protected method is not callable from the rebound Route scope.
 */
final class Dialogs
{
    public static function all(): array
    {
        return [
            'newsletter.import'        => Dialogs::import(),
            'newsletter.importMap'     => Dialogs::importMap(),
            'newsletter.importConfirm' => Dialogs::importConfirm(),
            'newsletter.export'        => Dialogs::export(),
            'newsletter.suppress'      => Dialogs::suppress(),
            'newsletter.subscriber.unsubscribe' => Dialogs::unsubscribe(),
            'newsletter.subscriber.delete'      => Dialogs::delete(),
            'newsletter.preview'                 => Dialogs::preview(),
            'newsletter.test'                    => Dialogs::testSend(),
            'newsletter.send'                    => Dialogs::send(),
            'newsletter.report'                  => Dialogs::report(),
            'newsletter.reconsent'               => Dialogs::reconsent(),
            'newsletter.suppressions.import'     => Dialogs::suppressionImport(),
            'newsletter.suppressions.remove'     => Dialogs::suppressionRemove(),
        ];
    }

    // ── Step 1: upload ──────────────────────────────────────────────────────

    public static function import(): array
    {
        return [
            'pattern' => 'newsletter/import',
            'load'    => function (): array {
                $lists = Dialogs::listOptions();

                if ($lists === []) {
                    return Dialogs::info(
                        I18n::translate('e9li.newsletter.import.noLists', 'Create a list first'),
                        'You need at least one list before you can import subscribers.'
                    );
                }

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size'   => 'medium',
                        'submitButton' => [
                            'icon' => 'upload',
                            'text' => I18n::translate('e9li.newsletter.import.analyse', 'Analyse file'),
                        ],
                        'fields' => [
                            'info' => [
                                'type'  => 'info',
                                'theme' => 'notice',
                                'text'  => I18n::translate(
                                    'e9li.newsletter.import.warning',
                                    'Only import addresses that asked to hear from you. Purchased or scraped lists contain spam traps, and a single hit can get your domain blocked permanently.'
                                ),
                            ],
                            'file' => [
                                'label'    => I18n::translate('e9li.newsletter.import.file', 'CSV file'),
                                'type'     => 'textarea',
                                'buttons'  => false,
                                'size'     => 'medium',
                                'required' => true,
                                'help'     => I18n::translate(
                                    'e9li.newsletter.import.filehelp',
                                    'Paste the contents of your CSV file, including the header row. Comma, semicolon and tab separators are all detected automatically.'
                                ),
                            ],
                            'lists' => [
                                'label'    => I18n::translate('e9li.newsletter.form.lists', 'Lists'),
                                'type'     => 'checkboxes',
                                'required' => true,
                                'options'  => $lists,
                            ],
                        ],
                        'value' => ['file' => '', 'lists' => []],
                    ],
                ];
            },
            'submit' => function (): array {
                $request = App::instance()->request();
                $body    = $request->body();

                $contents = (string)$body->get('file', '');
                $lists    = (array)$body->get('lists', []);

                if (trim($contents) === '') {
                    throw new Exception(message: 'Please paste the contents of a CSV file.');
                }

                if ($lists === []) {
                    throw new Exception(message: 'Please choose at least one list.');
                }

                $token = Dialogs::stash($contents, $lists);

                return [
                    'redirect' => false,
                    'event'    => 'newsletter.import.uploaded',
                    // Chain straight into the mapping dialog with the stash id.
                    'route'    => '/dialogs/newsletter/import/' . $token . '/map',
                ];
            },
        ];
    }

    // ── Step 2: map columns and show the dry run ────────────────────────────

    public static function importMap(): array
    {
        return [
            'pattern' => 'newsletter/import/(:any)/map',
            'load'    => function (string $token): array {
                $stash = Dialogs::unstash($token);
                $parsed = Csv::parse($stash['file']);

                if ($parsed['rows'] === []) {
                    return Dialogs::info('Nothing to import', 'The file has a header row but no data rows.');
                }

                $mapping = Csv::autoMap($parsed['headers']);
                $fields  = [];

                foreach ($parsed['headers'] as $index => $header) {
                    $sample = $parsed['rows'][0][$index] ?? '';

                    $fields['col' . $index] = [
                        'label'   => $header !== '' ? $header : 'Column ' . ($index + 1),
                        'type'    => 'select',
                        'width'   => '1/2',
                        'empty'   => false,
                        'options' => Dialogs::fieldOptions(),
                        'help'    => $sample === ''
                            ? null
                            : I18n::translate('e9li.newsletter.import.sample', 'Example') . ': ' . $sample,
                    ];
                }

                $value = [];

                foreach ($mapping as $index => $field) {
                    $value['col' . $index] = $field;
                }

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size'   => 'large',
                        'submitButton' => [
                            'icon' => 'check',
                            'text' => I18n::translate('e9li.newsletter.import.preview', 'Preview import'),
                        ],
                        'fields' => [
                            'summary' => [
                                'type'  => 'info',
                                'theme' => 'info',
                                'text'  => count($parsed['rows']) . ' '
                                    . I18n::translate('e9li.newsletter.import.rowsFound', 'rows found. Check the column mapping below.'),
                            ],
                            ...$fields,
                        ],
                        'value' => $value,
                    ],
                ];
            },
            'submit' => function (string $token): array {
                $stash  = Dialogs::unstash($token);
                $body   = App::instance()->request()->body();
                $parsed = Csv::parse($stash['file']);

                $mapping = [];

                foreach (array_keys($parsed['headers']) as $index) {
                    $mapping[$index] = (string)$body->get('col' . $index, 'ignore');
                }

                if (in_array('email', $mapping, true) === false) {
                    throw new Exception(message: 'One column must be mapped to "Email address".');
                }

                $stash['mapping'] = $mapping;
                Dialogs::restash($token, $stash);

                return [
                    'route' => '/dialogs/newsletter/import/' . $token . '/confirm',
                ];
            },
        ];
    }

    // ── Step 3: dry-run result + attestation + apply ────────────────────────

    public static function importConfirm(): array
    {
        return [
            'pattern' => 'newsletter/import/(:any)/confirm',
            'load'    => function (string $token): array {
                $stash  = Dialogs::unstash($token);
                $parsed = Csv::parse($stash['file']);
                $diff   = Csv::analyse($parsed['rows'], $stash['mapping'], $stash['lists']);

                $writes = $diff['summary'][Csv::OUTCOME_CREATE]
                    + $diff['summary'][Csv::OUTCOME_SUBSCRIBE]
                    + $diff['summary'][Csv::OUTCOME_UPDATE];

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size'   => 'medium',
                        'submitButton' => $writes === 0
                            ? false
                            : [
                                'icon'  => 'import',
                                'text'  => I18n::translate('e9li.newsletter.import.apply', 'Import')
                                    . ' ' . $writes,
                                'theme' => 'positive',
                            ],
                        'fields' => [
                            'diff' => [
                                'type'  => 'info',
                                'theme' => $writes === 0 ? 'negative' : 'positive',
                                'label' => I18n::translate('e9li.newsletter.import.dryrun', 'Dry run'),
                                'text'  => Dialogs::diffText($diff['summary']),
                            ],
                            'activate' => [
                                'label'   => I18n::translate('e9li.newsletter.import.activate', 'Skip confirmation email'),
                                'type'    => 'toggle',
                                'default' => false,
                                'help'    => I18n::translate(
                                    'e9li.newsletter.import.activateHelp',
                                    'Only for migrating a list whose consent you can already prove. Otherwise leave this off and everyone receives a confirmation email.'
                                ),
                            ],
                            'source' => [
                                'label'    => I18n::translate('e9li.newsletter.import.source', 'Where did this consent come from?'),
                                'type'     => 'text',
                                'required' => true,
                                'help'     => I18n::translate(
                                    'e9li.newsletter.import.sourceHelp',
                                    'Recorded against every imported subscriber, e.g. "Campaign Monitor export 2026-07-31". This is your evidence under GDPR Art. 7(1).'
                                ),
                            ],
                            'attest' => [
                                'label'    => I18n::translate('e9li.newsletter.import.attest', 'Confirmation'),
                                'type'     => 'toggle',
                                'required' => true,
                                'text'     => I18n::translate(
                                    'e9li.newsletter.import.attestText',
                                    'These people asked to receive this newsletter. This list was not purchased, rented or scraped.'
                                ),
                            ],
                        ],
                        'value' => [
                            'activate' => false,
                            'source'   => '',
                            'attest'   => false,
                        ],
                    ],
                ];
            },
            'submit' => function (string $token): array {
                $stash = Dialogs::unstash($token);
                $body  = App::instance()->request()->body();

                if ($body->get('attest') != true) {
                    throw new Exception(
                        message: 'Please confirm that these people asked to receive this newsletter.'
                    );
                }

                $source = trim((string)$body->get('source', ''));

                if ($source === '') {
                    throw new Exception(message: 'Please record where this consent came from.');
                }

                $parsed = Csv::parse($stash['file']);
                $diff   = Csv::analyse($parsed['rows'], $stash['mapping'], $stash['lists']);

                $applied = Csv::apply(
                    $diff['rows'],
                    $stash['lists'],
                    $body->get('activate') == true,
                    $source
                );

                Dialogs::discard($token);

                $imported = $applied[Csv::OUTCOME_CREATE]
                    + $applied[Csv::OUTCOME_SUBSCRIBE]
                    + $applied[Csv::OUTCOME_UPDATE];

                return [
                    'message' => $imported . ' ' . I18n::translate('e9li.newsletter.import.done', 'subscribers imported'),
                    'event'   => 'newsletter.import',
                ];
            },
        ];
    }

    // ── Export ──────────────────────────────────────────────────────────────

    public static function export(): array
    {
        return [
            'pattern' => 'newsletter/export',
            'load'    => function (): array {
                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size'   => 'medium',
                        'submitButton' => [
                            'icon' => 'download',
                            'text' => I18n::translate('e9li.newsletter.export.submit', 'Export'),
                        ],
                        'fields' => [
                            'list' => [
                                'label'   => I18n::translate('e9li.newsletter.form.lists', 'List'),
                                'type'    => 'select',
                                'options' => Dialogs::listOptions(),
                                'empty'   => I18n::translate('e9li.newsletter.export.allLists', 'All lists'),
                            ],
                            'status' => [
                                'label'   => I18n::translate('e9li.newsletter.export.status', 'Status'),
                                'type'    => 'select',
                                'options' => Views::statusOptions(),
                                'empty'   => I18n::translate('e9li.newsletter.export.allStatuses', 'All statuses'),
                            ],
                            'info' => [
                                'type'  => 'info',
                                'theme' => 'info',
                                'text'  => I18n::translate(
                                    'e9li.newsletter.export.info',
                                    'The export includes the consent record (signup and confirmation dates, source). Keep it — that evidence cannot be reconstructed later.'
                                ),
                            ],
                        ],
                        'value' => ['list' => null, 'status' => null],
                    ],
                ];
            },
            'submit' => function (): Response {
                $body = App::instance()->request()->body();

                $csv = Csv::export([
                    'list'   => Dialogs::nullable($body->get('list')),
                    'status' => Dialogs::nullable($body->get('status')),
                ]);

                Log::info('Subscribers exported', [
                    'bytes' => strlen($csv),
                    'user'  => App::instance()->user()?->email(),
                ]);

                // Returned as a Response so it bypasses the dialog JSON envelope
                // entirely and the browser receives a real file download.
                return new Response($csv, 'text/csv', 200, [
                    'Content-Disposition' => 'attachment; filename="newsletter-subscribers-'
                        . date('Y-m-d') . '.csv"',
                ]);
            },
        ];
    }

    // ── Subscriber actions ──────────────────────────────────────────────────

    public static function unsubscribe(): array
    {
        return [
            'pattern' => 'newsletter/subscriber/(:num)/unsubscribe',
            'load'    => function (int $id): array {
                $subscriber = Subscribers::find($id);

                if ($subscriber === null) {
                    throw new Exception(message: 'Subscriber not found.');
                }

                return [
                    'component' => 'k-remove-dialog',
                    'props'     => [
                        'text' => I18n::translate(
                            'e9li.newsletter.dialog.unsubscribe',
                            'Unsubscribe this person from every list?'
                        ) . "\n\n" . $subscriber->email,
                        'submitButton' => [
                            'icon'  => 'cancel',
                            'text'  => I18n::translate('e9li.newsletter.dialog.unsubscribeSubmit', 'Unsubscribe'),
                            'theme' => 'negative',
                        ],
                    ],
                ];
            },
            'submit' => function (int $id): array {
                Subscriptions::unsubscribe($id, null, Subscription::REASON_ADMIN);

                return [
                    'message' => I18n::translate('e9li.newsletter.dialog.unsubscribed', 'Unsubscribed'),
                    'event'   => 'newsletter.subscriber.update',
                ];
            },
        ];
    }

    public static function delete(): array
    {
        return [
            'pattern' => 'newsletter/subscriber/(:num)/delete',
            'load'    => function (int $id): array {
                $subscriber = Subscribers::find($id);

                if ($subscriber === null) {
                    throw new Exception(message: 'Subscriber not found.');
                }

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => [
                            'icon'  => 'trash',
                            'text'  => I18n::translate('delete', 'Delete'),
                            'theme' => 'negative',
                        ],
                        'fields' => [
                            'warning' => [
                                'type'  => 'info',
                                'theme' => 'negative',
                                'text'  => I18n::translate(
                                    'e9li.newsletter.dialog.deleteText',
                                    'This permanently deletes the person and their whole consent record. It cannot be undone.'
                                ) . "\n\n" . $subscriber->email,
                            ],
                            'keepHash' => [
                                'label'   => I18n::translate('e9li.newsletter.dialog.keepHash', 'Keep a suppression record'),
                                'type'    => 'toggle',
                                'default' => true,
                                'help'    => I18n::translate(
                                    'e9li.newsletter.dialog.keepHashHelp',
                                    'Stores only a one-way hash of the address, so a future CSV import cannot resurrect someone who asked to be forgotten. Recommended.'
                                ),
                            ],
                        ],
                        'value' => ['keepHash' => true],
                    ],
                ];
            },
            'submit' => function (int $id): array {
                $subscriber = Subscribers::find($id);

                if ($subscriber === null) {
                    throw new Exception(message: 'Subscriber not found.');
                }

                $keepHash = App::instance()->request()->body()->get('keepHash') != false;
                Subscribers::erase($id, $keepHash === true);

                return [
                    'message' => I18n::translate('e9li.newsletter.dialog.deleted', 'Subscriber deleted'),
                    'event'   => 'newsletter.subscriber.delete',
                ];
            },
        ];
    }

    public static function suppress(): array
    {
        return [
            'pattern' => 'newsletter/suppress',
            'load'    => function (): array {
                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => [
                            'icon' => 'cancel',
                            'text' => I18n::translate('e9li.newsletter.dialog.suppressSubmit', 'Suppress'),
                            'theme' => 'negative',
                        ],
                        'fields' => [
                            'email' => [
                                'label'    => I18n::translate('e9li.newsletter.form.email', 'Email address'),
                                'type'     => 'email',
                                'required' => true,
                            ],
                            'reason' => [
                                'label'   => I18n::translate('e9li.newsletter.dialog.reason', 'Reason'),
                                'type'    => 'select',
                                'default' => Suppressions::REASON_MANUAL,
                                'empty'   => false,
                                'options' => [
                                    ['value' => Suppressions::REASON_MANUAL, 'text' => 'Manual'],
                                    ['value' => Suppressions::REASON_COMPLAINT, 'text' => 'Complaint'],
                                    ['value' => Suppressions::REASON_HARD_BOUNCE, 'text' => 'Hard bounce'],
                                    ['value' => Suppressions::REASON_INVALID, 'text' => 'Invalid address'],
                                ],
                            ],
                            'info' => [
                                'type'  => 'info',
                                'theme' => 'notice',
                                'text'  => I18n::translate(
                                    'e9li.newsletter.dialog.suppressInfo',
                                    'A suppressed address is never sent to and can never be re-added by an import.'
                                ),
                            ],
                        ],
                        'value' => ['email' => '', 'reason' => Suppressions::REASON_MANUAL],
                    ],
                ];
            },
            'submit' => function (): array {
                $body  = App::instance()->request()->body();
                $email = trim((string)$body->get('email', ''));

                if (EmailAddress::isValid($email) === false) {
                    throw new Exception(message: 'Please enter a valid email address.');
                }

                Suppressions::add(
                    $email,
                    (string)$body->get('reason', Suppressions::REASON_MANUAL),
                    'Added in the Panel',
                    App::instance()->user()?->email()
                );

                $subscriber = Subscribers::findByEmail($email);

                if ($subscriber !== null) {
                    Subscriptions::unsubscribe($subscriber->id, null, Subscription::REASON_ADMIN);
                }

                return [
                    'message' => I18n::translate('e9li.newsletter.dialog.suppressed', 'Address suppressed'),
                    'event'   => 'newsletter.subscriber.update',
                ];
            },
        ];
    }

    // ── Preview, test, send, report ─────────────────────────────────────────

    public static function preview(): array
    {
        return [
            'pattern' => 'newsletter/preview/(:all)',
            'load'    => function (string $id): array {
                $page     = Dialogs::campaignPage($id);
                $rendered = \E9li\Newsletter\Render\Document::withSample(
                    \E9li\Newsletter\Render\Document::render($page),
                    $page
                );

                return [
                    'component' => 'k-newsletter-preview-dialog',
                    'props'     => [
                        'title'   => $page->title()->value(),
                        'html'    => $rendered['html'],
                        'bytes'   => $rendered['bytes'],
                        'warning' => $rendered['warnings'] === []
                            ? ''
                            : I18n::translate(
                                'e9li.newsletter.preview.warning',
                                'This message is large enough that Gmail may clip it.'
                            ),
                    ],
                ];
            },
        ];
    }

    public static function testSend(): array
    {
        return [
            'pattern' => 'newsletter/test/(:all)',
            'load'    => function (string $id): array {
                $page = Dialogs::campaignPage($id);
                $tags = $page->newsletterTestRecipients()->split();

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => [
                            'icon' => 'email',
                            'text' => I18n::translate('e9li.newsletter.action.sendTest', 'Send test'),
                        ],
                        'fields' => [
                            'recipients' => [
                                'label' => I18n::translate('e9li.newsletter.campaign.testRecipients', 'Test recipients'),
                                'type'  => 'tags',
                                'icon'  => 'email',
                            ],
                        ],
                        'value' => ['recipients' => $tags],
                    ],
                ];
            },
            'submit' => function (string $id): array {
                $page = Dialogs::campaignPage($id);
                $raw  = App::instance()->request()->body()->get('recipients', []);
                $list = is_array($raw) === true ? $raw : preg_split('/\s*,\s*/', (string)$raw);

                $result = \E9li\Newsletter\Send\Runner::test($page, array_values(array_filter(
                    array_map(static fn ($item): string => trim((string)$item), $list ?: []),
                    static fn (string $item): bool => $item !== ''
                )));

                if ($result['sent'] === 0) {
                    throw new Exception(message: I18n::translate(
                        'e9li.newsletter.send.testNone',
                        'No test message was accepted. Check the addresses.'
                    ));
                }

                return [
                    'message' => I18n::translate('e9li.newsletter.send.testDone', 'Test message handed to the transport.')
                        . ' ' . $result['sent'],
                ];
            },
        ];
    }

    public static function send(): array
    {
        return [
            'pattern' => 'newsletter/send/(:all)',
            'load'    => function (string $id): array {
                $page  = Dialogs::campaignPage($id);
                $check = \E9li\Newsletter\Send\Preflight::check($page);
                $fields = [
                    'summary' => [
                        'type' => 'info',
                        'text' => I18n::translate('e9li.newsletter.report.recipients', 'Recipients')
                            . ': ' . $check['recipients'],
                    ],
                ];

                if ($check['failures'] !== []) {
                    $fields['failures'] = [
                        'type'  => 'info',
                        'theme' => 'negative',
                        'label' => I18n::translate('e9li.newsletter.send.disabled', 'Sending is blocked'),
                        'text'  => Dialogs::checkLines($check['failures']),
                    ];
                }

                if ($check['warnings'] !== []) {
                    $fields['warnings'] = [
                        'type'  => 'info',
                        'theme' => 'notice',
                        'label' => I18n::translate('e9li.newsletter.send.warnings', 'Warnings'),
                        'text'  => Dialogs::checkLines($check['warnings']),
                    ];
                }

                $fields['cron'] = [
                    'type' => 'info',
                    'text' => I18n::translate(
                        'e9li.newsletter.send.cron',
                        'After queueing, cron must call `kirby newsletter:tick` every minute until the queue is empty.'
                    ),
                ];

                $value = [];

                if (\E9li\Newsletter\Newsletter::option('track.opens', false) === true) {
                    $fields['opens'] = [
                        'type'  => 'info',
                        'theme' => 'notice',
                        'text'  => I18n::translate(
                            'e9li.newsletter.send.opensApproximate',
                            'Opens are approximate, because Apple Mail prefetches images.'
                        ),
                    ];
                }

                if (App::instance()->option('debug') === true && $check['failures'] === []) {
                    $fields['now'] = [
                        'label' => I18n::translate('e9li.newsletter.send.now', 'Send the first batch now'),
                        'type'  => 'toggle',
                    ];
                    $value['now'] = false;
                }

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => $check['failures'] === [] ? [
                            'icon' => 'plane',
                            'text' => I18n::translate('e9li.newsletter.action.send', 'Send'),
                        ] : false,
                        'fields' => $fields,
                        'value'  => $value,
                    ],
                ];
            },
            'submit' => function (string $id): array {
                $page  = Dialogs::campaignPage($id);
                $check = \E9li\Newsletter\Send\Preflight::check($page);

                if ($check['failures'] !== []) {
                    throw new Exception(message: I18n::translate(
                        'e9li.newsletter.send.disabled',
                        'Sending is blocked'
                    ));
                }

                $seeded = \E9li\Newsletter\Send\Seeder::seed($page);

                if ($seeded['ok'] !== true) {
                    throw new Exception(message: (string)($seeded['error'] ?? 'Could not queue the send.'));
                }

                $message = I18n::translate('e9li.newsletter.send.started', 'Queued.')
                    . ' ' . $seeded['queued'] . '. '
                    . I18n::translate(
                        'e9li.newsletter.send.cron',
                        'After queueing, cron must call `kirby newsletter:tick` every minute until the queue is empty.'
                    );

                $now = App::instance()->request()->body()->get('now');

                if (App::instance()->option('debug') === true && in_array($now, [true, 1, '1', 'on', 'true'], true) === true) {
                    $tick = \E9li\Newsletter\Send\Runner::tick();
                    $message .= ' ' . $tick['sent'];
                }

                return [
                    'message' => $message,
                    'event'   => 'newsletter.campaigns',
                ];
            },
        ];
    }

    public static function report(): array
    {
        return [
            'pattern' => 'newsletter/report/(:all)',
            'load'    => function (string $id): array {
                $page   = Dialogs::campaignPage($id);
                $uuid   = $page->uuid()?->toString() ?? '';
                $latest = $uuid === '' ? null : \E9li\Newsletter\Send\Seeder::latest($uuid);
                $not    = I18n::translate('e9li.newsletter.report.notRecorded', 'not recorded');

                $lines = [
                    I18n::translate('e9li.newsletter.report.recipients', 'Recipients') . ': ' . ($latest['total'] ?? 0),
                    I18n::translate('e9li.newsletter.report.sent', 'Sent') . ': ' . ($latest['sent'] ?? 0),
                    I18n::translate('e9li.newsletter.report.failed', 'Failed') . ': ' . ($latest['failed'] ?? 0),
                    I18n::translate('e9li.newsletter.report.opened', 'Opened') . ': '
                        . ($latest !== null && $latest['tracked'] === true ? (string)$latest['opened'] : $not),
                ];

                if ($latest !== null && $latest['tracked'] === true) {
                    $lines[] = I18n::translate(
                        'e9li.newsletter.send.opensApproximate',
                        'Opens are approximate, because Apple Mail prefetches images.'
                    );
                }

                return Dialogs::info(
                    I18n::translate('e9li.newsletter.report.title', 'Report'),
                    implode("\n", $lines)
                );
            },
        ];
    }

    public static function reconsent(): array
    {
        return [
            'pattern' => 'newsletter/reconsent',
            'load'    => function (): array {
                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => [
                            'icon'  => 'refresh',
                            'text'  => I18n::translate('e9li.newsletter.action.reconsent', 'Ask for fresh consent'),
                            'theme' => 'negative',
                        ],
                        'fields' => [
                            'info' => [
                                'type'  => 'info',
                                'theme' => 'notice',
                                'text'  => I18n::translate(
                                    'e9li.newsletter.reconsent.text',
                                    'Active subscribers on the chosen list go back to pending immediately. Nothing is mailed from this dialog. Queue the opt-in messages with `kirby newsletter:reconsent <list-id>`, then let `kirby newsletter:tick` send them.'
                                ),
                            ],
                            'list' => [
                                'label'    => I18n::translate('e9li.newsletter.reconsent.list', 'List'),
                                'type'     => 'select',
                                'required' => true,
                                'empty'    => false,
                                'options'  => Dialogs::listOptions(),
                            ],
                        ],
                        'value' => ['list' => Dialogs::listOptions()[0]['value'] ?? ''],
                    ],
                ];
            },
            'submit' => function (): array {
                $uuid = (string)App::instance()->request()->body()->get('list', '');
                $list = Lists::find($uuid);

                if ($list === null) {
                    throw new Exception(message: 'List not found.');
                }

                $changed = Subscriptions::markReconsent($uuid);

                return [
                    'message' => I18n::translate('e9li.newsletter.reconsent.done', 'Marked as pending.') . ' ' . $changed,
                    'event'   => 'newsletter.subscriber.update',
                ];
            },
        ];
    }

    public static function suppressionImport(): array
    {
        return [
            'pattern' => 'newsletter/suppressions/import',
            'load'    => function (): array {
                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'medium',
                        'submitButton' => [
                            'icon' => 'import',
                            'text' => I18n::translate('e9li.newsletter.suppressions.import', 'Import addresses'),
                        ],
                        'fields' => [
                            'addresses' => [
                                'label'    => I18n::translate('e9li.newsletter.suppressions.addresses', 'Addresses'),
                                'type'     => 'textarea',
                                'buttons'  => false,
                                'required' => true,
                                'help'     => I18n::translate(
                                    'e9li.newsletter.suppressions.import',
                                    'One address per line. Each is stored as a manual suppression.'
                                ),
                            ],
                        ],
                        'value' => ['addresses' => ''],
                    ],
                ];
            },
            'submit' => function (): array {
                $text   = (string)App::instance()->request()->body()->get('addresses', '');
                $result = Suppressions::importLines($text);

                return [
                    'message' => I18n::translate('e9li.newsletter.suppressions.imported', 'Imported.')
                        . ' ' . $result['added'],
                    'event'   => 'newsletter.suppressions',
                ];
            },
        ];
    }

    public static function suppressionRemove(): array
    {
        return [
            'pattern' => 'newsletter/suppressions/(:num)/remove',
            'load'    => function (int $id): array {
                $row = \E9li\Newsletter\Database::first(
                    'SELECT * FROM nl_suppressions WHERE id = :id',
                    ['id' => $id]
                );

                if ($row === null) {
                    throw new Exception(message: 'Suppression not found.');
                }

                $permanent = in_array($row['reason'], Suppressions::PERMANENT, true) === true;

                return [
                    'component' => 'k-form-dialog',
                    'props'     => [
                        'size' => 'small',
                        'submitButton' => [
                            'icon'  => 'trash',
                            'text'  => I18n::translate('e9li.newsletter.suppressions.remove', 'Remove'),
                            'theme' => 'negative',
                        ],
                        'fields' => [
                            'info' => [
                                'type' => 'info',
                                'text' => ($row['email_norm'] === null || $row['email_norm'] === ''
                                    ? I18n::translate('e9li.newsletter.suppressions.hashOnly', 'Hash only')
                                    : EmailAddress::mask((string)$row['email_norm']))
                                    . ' · ' . $row['reason'],
                            ],
                            'force' => [
                                'label'   => I18n::translate('e9li.newsletter.suppressions.force', 'Remove anyway'),
                                'type'    => 'toggle',
                                'default' => false,
                                'help'    => $permanent
                                    ? I18n::translate(
                                        'e9li.newsletter.suppressions.forceHelp',
                                        'Complaint and erasure rows stay until this is switched on.'
                                    )
                                    : '',
                            ],
                        ],
                        'value' => ['force' => false],
                    ],
                ];
            },
            'submit' => function (int $id): array {
                $force = App::instance()->request()->body()->get('force') != false;

                if (Suppressions::removeById($id, $force === true) === false) {
                    throw new Exception(message: I18n::translate(
                        'e9li.newsletter.suppressions.refused',
                        'This suppression cannot be removed without the second confirmation.'
                    ));
                }

                return [
                    'message' => I18n::translate('e9li.newsletter.suppressions.removed', 'Suppression removed'),
                    'event'   => 'newsletter.suppressions',
                ];
            },
        ];
    }

    /**
     * @param array<int, array{label: string, value: string, help: string|null}> $items
     */
    public static function checkLines(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $line = $item['label'] . ': ' . $item['value'];

            if (($item['help'] ?? null) !== null && $item['help'] !== '') {
                $line .= ' — ' . $item['help'];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public static function campaignPage(string $id): \Kirby\Cms\Page
    {
        $page = App::instance()->page($id);

        if ($page === null || $page->intendedTemplate()->name() !== 'newsletter-campaign') {
            throw new Exception(message: 'Campaign not found.');
        }

        return $page;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{value: string, text: string}>
     */
    public static function listOptions(): array
    {
        $options = [];

        foreach (Lists::all() as $page) {
            $options[] = [
                'value' => Lists::uuid($page),
                'text'  => $page->title()->value(),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, text: string}>
     */
    public static function fieldOptions(): array
    {
        return [
            ['value' => 'ignore',    'text' => I18n::translate('e9li.newsletter.import.ignore', '— ignore —')],
            ['value' => 'email',     'text' => I18n::translate('e9li.newsletter.form.email', 'Email address')],
            ['value' => 'name',      'text' => I18n::translate('e9li.newsletter.form.name', 'Name')],
            ['value' => 'language',  'text' => I18n::translate('e9li.newsletter.preferences.language', 'Language')],
            ['value' => 'consentAt', 'text' => I18n::translate('e9li.newsletter.import.consentAt', 'Consent date')],
        ];
    }

    public static function diffText(array $summary): string
    {
        $labels = [
            Csv::OUTCOME_CREATE     => 'New subscribers',
            Csv::OUTCOME_SUBSCRIBE  => 'Existing people added to the list',
            Csv::OUTCOME_UPDATE     => 'Already on the list',
            Csv::OUTCOME_SUPPRESSED => 'Suppressed — will be skipped',
            Csv::OUTCOME_INVALID    => 'Invalid addresses — will be skipped',
            Csv::OUTCOME_DUPLICATE  => 'Duplicates in the file — will be skipped',
        ];

        $lines = [];

        foreach ($labels as $key => $label) {
            $count = $summary[$key] ?? 0;

            if ($count === 0) {
                continue;
            }

            $lines[] = $count . ' — ' . $label;
        }

        return $lines === [] ? 'Nothing to import.' : implode("\n\n", $lines);
    }

    // ── Import stash ────────────────────────────────────────────────────────
    //
    // The pasted CSV has to survive three dialog round-trips. Keeping it in a
    // temp file rather than a hidden form field means a 5,000-row paste is not
    // re-uploaded twice, and the data never sits in the browser's history.

    public static function stashDir(): string
    {
        $dir = Newsletter::root() . '/imports';

        if (is_dir($dir) === false) {
            Dir::make($dir, true);
        }

        return $dir;
    }

    public static function stash(string $contents, array $lists): string
    {
        Dialogs::gc();

        $token = bin2hex(random_bytes(8));

        F::write(Dialogs::stashDir() . '/' . $token . '.json', json_encode([
            'file'    => $contents,
            'lists'   => array_values($lists),
            'mapping' => [],
            'created' => time(),
            'user'    => App::instance()->user()?->id(),
        ]));

        return $token;
    }

    public static function unstash(string $token): array
    {
        if (preg_match('/^[a-f0-9]{16}$/', $token) !== 1) {
            throw new Exception(message: 'Invalid import session.');
        }

        $file = Dialogs::stashDir() . '/' . $token . '.json';

        if (is_file($file) === false) {
            throw new Exception(message: 'This import session has expired. Please start again.');
        }

        $data = json_decode((string)F::read($file), true);

        if (is_array($data) === false) {
            throw new Exception(message: 'This import session is unreadable. Please start again.');
        }

        // Scoped to the user who started it, so one editor's half-finished
        // import is never visible to another.
        if (($data['user'] ?? null) !== App::instance()->user()?->id()) {
            throw new Exception(message: 'This import session belongs to another user.');
        }

        return $data;
    }

    public static function restash(string $token, array $data): void
    {
        F::write(Dialogs::stashDir() . '/' . $token . '.json', json_encode($data));
    }

    public static function discard(string $token): void
    {
        F::remove(Dialogs::stashDir() . '/' . $token . '.json');
    }

    /**
     * Removes stashes older than an hour.
     */
    public static function gc(): void
    {
        foreach (Dir::files(Dialogs::stashDir()) as $file) {
            $path = Dialogs::stashDir() . '/' . $file;

            if (is_file($path) === true && filemtime($path) < time() - 3600) {
                F::remove($path);
            }
        }
    }

    public static function nullable(mixed $value): string|null
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    public static function info(string $title, string $text): array
    {
        return [
            'component' => 'k-text-dialog',
            'props'     => [
                'text'         => $title . "\n\n" . $text,
                'submitButton' => false,
            ],
        ];
    }
}
