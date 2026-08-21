<?php

namespace BBS\Services;

/**
 * The settings pages, in the order they are shown.
 *
 * One list, used by the sidebar on desktop and the jump menu on mobile. They
 * were separate before and drifted: the tab strip was removed as redundant
 * with the sidebar, which is true on a desktop and left phones with no way to
 * reach any settings page but the first.
 */
class SettingsNav
{
    /** @return array<int, array{tab: string, label: string, icon: string}> */
    public static function pages(): array
    {
        return [
            ['tab' => 'general',       'label' => 'General',            'icon' => 'bi-gear'],
            ['tab' => 'agent',         'label' => 'Agent',              'icon' => 'bi-incognito'],
            ['tab' => 'notifications', 'label' => 'Email Settings',     'icon' => 'bi-envelope'],
            ['tab' => 'push',          'label' => 'Apprise',            'icon' => 'bi-megaphone'],
            ['tab' => 'push_service',  'label' => 'Push Service',       'icon' => 'bi-broadcast'],
            ['tab' => 'profiles',      'label' => 'Profiles',           'icon' => 'bi-diagram-3'],
            ['tab' => 'templates',     'label' => 'Templates',          'icon' => 'bi-clipboard-check'],
            ['tab' => 'auth',          'label' => 'Authentication',     'icon' => 'bi-shield-lock'],
            ['tab' => 'branding',      'label' => 'Branding',           'icon' => 'bi-palette'],
            ['tab' => 'ssl',           'label' => 'SSL Certificate',    'icon' => 'bi-lock'],
            ['tab' => 'api',           'label' => 'API',                'icon' => 'bi-key'],
            ['tab' => 'updates',       'label' => 'Updates',            'icon' => 'bi-arrow-repeat'],
        ];
    }

    /** The label for one tab, for a heading or a menu button. */
    public static function label(string $tab): string
    {
        foreach (self::pages() as $page) {
            if ($page['tab'] === $tab) {
                return $page['label'];
            }
        }
        return 'Settings';
    }
}
