<?php

namespace E9li\Newsletter\Render;

use E9li\Newsletter\Lists;
use E9li\Newsletter\Log;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Theme;
use Kirby\Cms\Page;

/**
 * Compiles one campaign language into HTML and plain text.
 *
 * Placeholders are left untouched. Name, unsubscribe and the other
 * per-recipient values are filled later, at send time, so the frozen file
 * can be reused for every subscriber of that language.
 */
final class Document
{
    public const PLACEHOLDERS = [
        'name',
        'email',
        'unsubscribe_url',
        'preferences_url',
        'web_url',
        'list_reason',
        'open_url',
        'confirm_url',
    ];

    /**
     * @return array{html: string, text: string, bytes: int, warnings: array<int, string>}
     */
    public static function render(Page $campaign, string|null $language = null, bool|null $trackOpens = null): array
    {
        return Newsletter::withLanguage($language, static function () use ($campaign, $trackOpens): array {
            $template = $campaign->newsletterTemplate()->toPage();
            $theme    = Theme::fromPage($template);
            $track    = $trackOpens ?? (Newsletter::option('track.opens', false) === true);

            $rows  = [];
            $plain = [];

            foreach ($campaign->newsletterBody()->toLayouts() as $layout) {
                $attrs  = $layout->attrs();
                $token  = (string)$attrs->get('background')->value();
                $token  = $token === '' ? 'canvas' : $token;
                $surface = $theme->surface($token);
                $padKey = (string)$attrs->get('paddingY')->value();
                $pad    = Blocks::PADDING[$padKey] ?? Blocks::PADDING['m'];

                $columns = [];

                foreach ($layout->columns() as $column) {
                    $html  = '';
                    $lines = [];

                    foreach ($column->blocks() as $block) {
                        $html .= Blocks::html($block, $theme);
                        $line = trim(Blocks::plain($block));

                        if ($line !== '') {
                            $lines[] = $line;
                        }
                    }

                    $columns[] = [
                        'width' => static::percent((string)$column->width()),
                        'html'  => $html,
                    ];

                    if ($lines !== []) {
                        $plain[] = implode("\n\n", $lines);
                    }
                }

                $rows[] = [
                    'columns'    => $columns,
                    'background' => $surface['background'],
                    'color'      => $surface['text'],
                    'padding'    => $pad,
                    'hide'       => $attrs->get('hideOnMobile')->toBool(false),
                    'bleed'      => $attrs->get('fullWidth')->toBool(false),
                ];
            }

            $html = (string)snippet('newsletter/email/document', [
                'theme'      => $theme,
                'subject'    => $campaign->newsletterSubject()->value(),
                'preheader'  => $campaign->newsletterPreheader()->value(),
                'rows'       => $rows,
                'trackOpens' => $track,
            ], true);

            $text = (string)snippet('newsletter/email/document.text', [
                'theme'      => $theme,
                'subject'    => $campaign->newsletterSubject()->value(),
                'preheader'  => $campaign->newsletterPreheader()->value(),
                'body'       => implode("\n\n", $plain),
                'reasons'    => static::reasons($campaign),
                'trackOpens' => $track,
            ], true);

            $bytes    = strlen($html);
            $warnings = [];
            $limit    = (int)Newsletter::option('render.warnBytes', 102400);

            if ($limit > 0 && $bytes > $limit) {
                $warnings[] = 'html-size';
                Log::warning('Rendered newsletter exceeds the size warning', [
                    'page'  => $campaign->id(),
                    'bytes' => $bytes,
                    'limit' => $limit,
                ]);
            }

            return [
                'html'     => $html,
                'text'     => $text,
                'bytes'    => $bytes,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Sample values for the Panel preview. The frozen send does not use these.
     *
     * @param array{html: string, text: string, bytes: int, warnings: array<int, string>} $rendered
     * @return array{html: string, text: string, bytes: int, warnings: array<int, string>}
     */
    public static function withSample(array $rendered, Page $campaign): array
    {
        $site = rtrim((string)kirby()->url(), '/');
        $reasons = static::reasons($campaign);

        $values = [
            'name'             => 'Ada',
            'email'            => 'ada@example.com',
            'unsubscribe_url'  => $site . '/newsletter/u/preview',
            'preferences_url'  => $site . '/newsletter/preferences/preview',
            'web_url'          => $site,
            'list_reason'      => $reasons,
            'open_url'         => $site . '/newsletter/o/preview',
            'confirm_url'      => $site . '/newsletter/confirm/preview',
        ];

        $rendered['html'] = static::fill($rendered['html'], $values, true);
        $rendered['text'] = static::fill($rendered['text'], $values, false);
        $rendered['bytes'] = strlen($rendered['html']);

        return $rendered;
    }

    /**
     * @param array<string, string> $values keys without braces
     */
    public static function fill(string $document, array $values, bool $html): string
    {
        $map = [];

        foreach (static::PLACEHOLDERS as $key) {
            $value = (string)($values[$key] ?? '');

            if ($html === true) {
                $value = esc($value);
            }

            $map['{{' . $key . '}}'] = $value;
        }

        return strtr($document, $map);
    }

    public static function reasons(Page $campaign): string
    {
        $lines = [];

        foreach ($campaign->newsletterLists()->toPages() as $list) {
            if (Lists::isList($list) === false) {
                continue;
            }

            $reason = trim($list->newsletterReason()->value());

            if ($reason !== '') {
                $lines[] = $reason;
            }
        }

        return implode("\n", array_unique($lines));
    }

    /**
     * Per-recipient reason: only the lists this person is actually on.
     *
     * @param array<int, string> $listUuids
     */
    public static function reasonsFor(array $listUuids): string
    {
        $lines = [];

        foreach ($listUuids as $uuid) {
            $list = Lists::find($uuid);

            if ($list === null) {
                continue;
            }

            $reason = trim($list->newsletterReason()->value());

            if ($reason !== '') {
                $lines[] = $reason;
            }
        }

        return implode("\n", array_unique($lines));
    }

    protected static function percent(string $width): int
    {
        return match ($width) {
            '1/2' => 50,
            '1/3' => 33,
            '2/3' => 67,
            '1/4' => 25,
            '3/4' => 75,
            default => 100,
        };
    }
}
