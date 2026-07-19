<?php

namespace App\Support;

/**
 * The dismissable "Getting Started" intro messages — one per major page.
 *
 * Each page's visibility is its own managed per-user bool (config/settings.php,
 * `interface` group), so dismissing the message on one page never hides
 * another's. PAGES maps the `page` value posted by the dismiss/restore forms to
 * that page's config key; the extra `all` value (the Account page's "Restore
 * Getting Started messages" button) is handled in the controller and writes
 * every key at once.
 */
class GettingStarted
{
    public const PAGES = [
        'dashboard' => 'tts.show_getting_started',
        'studio' => 'tts.show_getting_started_studio',
        'voices' => 'tts.show_getting_started_voices',
        'pronunciations' => 'tts.show_getting_started_pronunciations',
        'api' => 'tts.show_getting_started_api',
    ];
}
