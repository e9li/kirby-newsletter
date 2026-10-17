<?php

namespace E9li\Newsletter\Render;

use E9li\Newsletter\Log;
use E9li\Newsletter\Theme;
use Kirby\Cms\Block;

/**
 * One layout block, as email HTML and as plain text.
 *
 * Unknown types are skipped. An old campaign that still contains a video
 * block must not fatal a send; the editor sees the gap in the preview and
 * the log records which type was dropped.
 */
final class Blocks
{
    public const PADDING = [
        'none' => 0,
        's'    => 12,
        'm'    => 24,
        'l'    => 40,
    ];

    public static function html(Block $block, Theme $theme): string
    {
        $type = $block->type();
        $known = [
            'heading', 'text', 'image', 'list', 'quote', 'line',
            'newsletter-button', 'newsletter-spacer',
        ];

        if (in_array($type, $known, true) === false) {
            Log::warning('Skipped a block an email cannot render', ['type' => $type]);

            return '';
        }

        $snippet = 'newsletter/email/blocks/' . $type;

        return (string)snippet($snippet, [
            'block' => $block,
            'theme' => $theme,
            'html'  => static::body($block, $type),
            'plain' => static::plain($block),
        ], true);
    }

    public static function plain(Block $block): string
    {
        $type = $block->type();

        return match ($type) {
            'heading', 'text', 'quote' => static::textOf($block),
            'list' => static::listPlain($block),
            'newsletter-button' => static::buttonPlain($block),
            'image' => static::imagePlain($block),
            'line', 'newsletter-spacer' => '',
            default => '',
        };
    }

    /**
     * Sanitised HTML for the blocks whose field is a writer.
     */
    public static function body(Block $block, string $type): string
    {
        if ($type === 'text') {
            return Theme::rich($block->text()->value());
        }

        if ($type === 'heading' || $type === 'quote') {
            return Theme::inline($block->text()->value());
        }

        return '';
    }

    public static function imageUrl(Block $block): string|null
    {
        $location = (string)$block->location()->or('kirby')->value();

        if ($location === 'web') {
            $src = trim($block->src()->value());

            if ($src === '' || preg_match('/\.svg(\?|$)/i', $src) === 1) {
                return null;
            }

            $absolute = Theme::absolute($src);

            return preg_match('#^https?://#i', $absolute) === 1 ? $absolute : null;
        }

        return Theme::fileUrl($block->image()->toFile());
    }

    public static function buttonUrl(Block $block): string|null
    {
        $kind = (string)$block->linktype()->or('url')->value();

        if ($kind === 'file') {
            return Theme::fileUrl($block->file()->toFile());
        }

        $url = trim($block->url()->value());

        if ($url === '') {
            return null;
        }

        return Theme::absolute($url);
    }

    public static function headingLevel(Block $block): string
    {
        $level = (string)$block->level()->or('h2')->value();

        return preg_match('/^h[1-6]$/', $level) === 1 ? $level : 'h2';
    }

    public static function spacerHeight(Block $block): int
    {
        $size = (string)$block->size()->or('m')->value();

        return static::PADDING[$size] ?? static::PADDING['m'];
    }

    /**
     * @return array<int, string>
     */
    public static function listItems(Block $block): array
    {
        $items = $block->text()->yaml();

        if (is_array($items) === false) {
            return [];
        }

        $clean = [];

        foreach ($items as $item) {
            if (is_array($item) === true) {
                $item = $item['text'] ?? $item['content'] ?? '';
            }

            $item = trim(strip_tags((string)$item));

            if ($item !== '') {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    protected static function textOf(Block $block): string
    {
        $text = trim(html_entity_decode(strip_tags($block->text()->value()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($block->type() === 'quote') {
            $citation = trim(strip_tags($block->citation()->value()));

            if ($citation !== '') {
                $text = trim($text . ' — ' . $citation);
            }
        }

        return $text;
    }

    protected static function listPlain(Block $block): string
    {
        $lines = [];

        foreach (static::listItems($block) as $item) {
            $lines[] = '- ' . $item;
        }

        return implode("\n", $lines);
    }

    protected static function buttonPlain(Block $block): string
    {
        $label = trim(strip_tags($block->label()->value()));
        $url   = static::buttonUrl($block) ?? '';

        if ($label === '' && $url === '') {
            return '';
        }

        return trim($label . ($url === '' ? '' : "\n" . $url));
    }

    protected static function imagePlain(Block $block): string
    {
        $url = static::imageUrl($block);
        $alt = trim(strip_tags($block->alt()->value()));

        if ($url === null) {
            return $alt;
        }

        return trim($alt . "\n" . $url);
    }
}
