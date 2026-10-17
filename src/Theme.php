<?php

namespace E9li\Newsletter;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;

/**
 * Design tokens of one newsletter-template page.
 *
 * A template is colours, a logo and a footer — not an HTML document. The
 * renderer is what turns those tokens, plus the campaign layout, into mail.
 */
final class Theme
{
    public const FONTS = [
        'system'  => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        'arial'   => 'Arial,Helvetica,sans-serif',
        'georgia' => "Georgia,'Times New Roman',Times,serif",
    ];

    /**
     * Fallbacks for a template that has not filled a colour in yet.
     * Brand and link match the blueprint defaults.
     */
    public const DEFAULTS = [
        'page'         => '#f4f4f5',
        'card'         => '#ffffff',
        'text'         => '#18181b',
        'muted'        => '#71717a',
        'button'       => '#1d4ed8',
        'buttonText'   => '#ffffff',
        'brand'        => '#1d4ed8',
        'link'         => '#1d4ed8',
        'dark'         => '#18181b',
        'darkText'     => '#ffffff',
        'mutedSurface' => '#e4e4e7',
    ];

    /**
     * @param array<string, string> $colors
     * @param array<int, array{label: string, url: string}> $social
     */
    public function __construct(
        public readonly array $colors,
        public readonly int $width,
        public readonly string|null $logo,
        public readonly string|null $logoDark,
        public readonly string $logoAlign,
        public readonly string $headerHtml,
        public readonly string|null $headerImage,
        public readonly string $font,
        public readonly string $legalName,
        public readonly string $address,
        public readonly string $footerHtml,
        public readonly string|null $privacyUrl,
        public readonly string|null $imprintUrl,
        public readonly array $social,
    ) {
    }

    public static function fromPage(Page|null $page): self
    {
        $colors = static::DEFAULTS;

        if ($page !== null) {
            $colors['brand']      = static::color($page->newsletterBrand()->value(), $colors['brand']);
            $colors['link']       = static::color($page->newsletterLink()->value(), $colors['link']);
            $colors['page']       = static::color($page->newsletterPage()->value(), $colors['page']);
            $colors['card']       = static::color($page->newsletterCard()->value(), $colors['card']);
            $colors['text']       = static::color($page->newsletterText()->value(), $colors['text']);
            $colors['muted']      = static::color($page->newsletterMuted()->value(), $colors['muted']);
            $colors['button']     = static::color($page->newsletterButton()->value(), $colors['button']);
            $colors['buttonText'] = static::color($page->newsletterButtonText()->value(), $colors['buttonText']);
        }

        $width = $page === null ? 600 : (int)$page->newsletterContentWidth()->or(600)->value();
        $width = max(480, min(700, $width > 0 ? $width : 600));

        $fontKey = $page === null ? 'system' : (string)$page->newsletterFont()->or('system')->value();
        $font    = static::FONTS[$fontKey] ?? static::FONTS['system'];

        $align = $page === null ? 'left' : (string)$page->newsletterLogoAlign()->or('left')->value();

        if (in_array($align, ['left', 'center'], true) === false) {
            $align = 'left';
        }

        $social = [];

        if ($page !== null) {
            foreach ($page->newsletterSocial()->toStructure() as $item) {
                if (count($social) >= 4) {
                    break;
                }

                $label = trim($item->label()->value());
                $url   = trim($item->url()->value());

                if ($label === '' || $url === '') {
                    continue;
                }

                $social[] = ['label' => $label, 'url' => $url];
            }
        }

        return new self(
            colors: $colors,
            width: $width,
            logo: $page === null ? null : static::fileUrl($page->newsletterLogo()->toFile()),
            logoDark: $page === null ? null : static::fileUrl($page->newsletterLogoDark()->toFile()),
            logoAlign: $align,
            headerHtml: $page === null ? '' : static::inline($page->newsletterHeaderLine()->value()),
            headerImage: $page === null ? null : static::fileUrl($page->newsletterHeaderImage()->toFile()),
            font: $font,
            legalName: $page === null ? '' : trim($page->newsletterLegalName()->value()),
            address: $page === null ? '' : trim($page->newsletterAddress()->value()),
            footerHtml: $page === null ? '' : static::inline($page->newsletterFooterText()->value()),
            privacyUrl: $page === null ? null : static::httpUrl($page->newsletterPrivacyUrl()->value()),
            imprintUrl: $page === null ? null : static::httpUrl($page->newsletterImprintUrl()->value()),
            social: $social,
        );
    }

    /**
     * Absolute http(s) URL for a raster image, or null.
     *
     * SVG is rejected: Gmail and Outlook block it, and an email has no site
     * root to resolve a relative path against.
     */
    public static function fileUrl(File|null $file): string|null
    {
        if ($file === null) {
            return null;
        }

        $extension = strtolower($file->extension());

        if ($extension === 'svg' || $file->mime() === 'image/svg+xml') {
            return null;
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true) === false) {
            return null;
        }

        return static::absolute((string)$file->url());
    }

    public static function absolute(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim((string)App::instance()->url(), '/') . '/' . ltrim($url, '/');
    }

    public static function httpUrl(string|null $url): string|null
    {
        $url = trim((string)$url);

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return $url === '' ? null : static::absolute($url);
        }

        return $url;
    }

    /**
     * Background and text colour for a layout row token.
     *
     * @return array{background: string, text: string}
     */
    public function surface(string $token): array
    {
        return match ($token) {
            'page'  => ['background' => $this->colors['page'], 'text' => $this->colors['text']],
            'muted' => ['background' => $this->colors['mutedSurface'], 'text' => $this->colors['text']],
            'brand' => ['background' => $this->colors['brand'], 'text' => $this->colors['buttonText']],
            'dark'  => ['background' => $this->colors['dark'], 'text' => $this->colors['darkText']],
            default => ['background' => $this->colors['card'], 'text' => $this->colors['text']],
        };
    }

    public static function color(string|null $value, string $fallback): string
    {
        $value = trim((string)$value);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return strtolower($value);
        }

        return $fallback;
    }

    /**
     * Editor HTML reduced to the marks a footer or heading is allowed to use.
     * Panel users are trusted, but a writer field will happily store a script
     * tag, and that must not reach an inbox.
     */
    public static function inline(string|null $html): string
    {
        return static::scrub($html, '<a><b><strong><i><em><br>');
    }

    public static function rich(string|null $html): string
    {
        return static::scrub($html, '<a><b><strong><i><em><br><p><ul><ol><li><h1><h2><h3><h4><blockquote>');
    }

    protected static function scrub(string|null $html, string $allowed): string
    {
        $html = strip_tags((string)$html, $allowed);
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', ' $1=$2#$2', $html) ?? $html;

        return trim($html);
    }
}
