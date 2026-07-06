<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The About page is a public marketing tour — it must render for guests and be
 * reachable from the landing page.
 */
class AboutPageTest extends TestCase
{
    public function test_about_page_is_publicly_viewable(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Alias TTS')
            ->assertSee('/v1/text-to-speech/', false)
            ->assertSee('/v1/audio/speech', false)
            ->assertSee('Fix the sentence, not the file.');
    }

    public function test_landing_page_links_to_the_about_page(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(route('about'), false);
    }

    public function test_about_page_shows_screenshot_placeholders_until_captures_exist(): void
    {
        // No screenshots are bundled in the repo; the frame should render the
        // placeholder with the drop path so the page never looks broken.
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Screenshot on the way')
            ->assertSee('public/images/about/studio.png');
    }
}
