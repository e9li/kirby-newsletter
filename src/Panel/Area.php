<?php

namespace E9li\Newsletter\Panel;

use E9li\Newsletter\Newsletter;
use Kirby\Toolkit\I18n;

/**
 * The Panel area definition.
 *
 * One entry in the Panel sidebar. Kirby 5's sidebar is deliberately flat —
 * `k-panel-menu` renders `$panel.menu.entries` as a plain list of buttons split
 * only by `'-'` separators, with no `children` key — so the sub-navigation
 * across Newsletters / Lists / Templates / Settings lives inside the area as a
 * tab bar instead of as nested sidebar items.
 *
 * Registering the area automatically creates an `access.newsletter` permission
 * (see Kirby\Cms\AppPlugins::extendAreas), so a role blueprint can gate the
 * whole thing with:
 *
 *     permissions:
 *       access:
 *         newsletter: false
 */
final class Area
{
    public const ID = 'newsletter';

    public static function definition(): array
    {
        return [
            'label' => I18n::translate('e9li.newsletter.label', 'Newsletter'),
            'icon'  => 'email',

            // Deliberately the literal `true` rather than a closure. Kirby runs
            // the whole area array through A::apply() on every view render,
            // which would invoke a `menu` closure a second time with **zero
            // arguments** — a signature mismatch that fatals unless every
            // parameter is defaulted. `true` sidesteps the trap entirely.
            'menu' => true,

            'link'            => static::ID,
            'breadcrumbLabel' => I18n::translate('e9li.newsletter.label', 'Newsletter'),

            'views'    => Views::all(),
            'requests' => Requests::all(),
            'dialogs'  => Dialogs::all(),
        ];
    }

    /**
     * The sub-navigation shown at the top of every view in this area.
     *
     * `k-tabs` navigates by each tab's `link` and emits no change event, so
     * these are real Panel routes rather than client-side state — which also
     * means every tab is bookmarkable and survives a reload.
     *
     * @return array<int, array<string, string>>
     */
    public static function tabs(): array
    {
        return [
            [
                'name'  => 'campaigns',
                'label' => I18n::translate('e9li.newsletter.tab.campaigns', 'Newsletters'),
                'icon'  => 'megaphone',
                'link'  => static::ID,
            ],
            [
                'name'  => 'lists',
                'label' => I18n::translate('e9li.newsletter.tab.lists', 'Lists'),
                'icon'  => 'users',
                'link'  => static::ID . '/lists',
            ],
            [
                'name'  => 'templates',
                'label' => I18n::translate('e9li.newsletter.tab.templates', 'Templates'),
                'icon'  => 'template',
                'link'  => static::ID . '/templates',
            ],
            [
                'name'  => 'settings',
                'label' => I18n::translate('e9li.newsletter.tab.settings', 'Settings'),
                'icon'  => 'settings',
                'link'  => static::ID . '/settings',
            ],
        ];
    }

    /**
     * Props shared by every view in the area.
     */
    public static function shared(string $tab): array
    {
        return [
            'tabs' => static::tabs(),
            'tab'  => $tab,
        ];
    }

    /**
     * Panel-relative link helper.
     */
    public static function link(string $path = ''): string
    {
        return trim(static::ID . '/' . $path, '/');
    }
}
