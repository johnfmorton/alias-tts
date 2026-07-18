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

    public function test_about_page_walks_the_signal_with_an_anchored_map(): void
    {
        // The waveform map after the hero anchors to every section of the tour.
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Step into a full audio studio when you need verifiable output.')
            ->assertSee('id="api"', false)
            ->assertSee('id="voices"', false)
            ->assertSee('id="quality"', false)
            ->assertSee('id="studio"', false)
            ->assertSee('id="provenance"', false);
    }

    public function test_landing_page_links_to_the_about_page(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSeeInOrder(['Two APIs.', 'One Studio.', 'Your voices.'])
            ->assertSee('Alias TTS — Two APIs. One Studio. Your voices.', false)
            ->assertSee(route('about'), false);
    }

    public function test_about_page_renders_the_bundled_screenshots(): void
    {
        // Real captures ship in public/images/about; every frame should render
        // its screenshot instead of the "on the way" placeholder.
        $response = $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('Screenshot on the way');

        foreach (['dashboard', 'voices', 'studio', 'verify'] as $shot) {
            $response->assertSee('images/about/'.$shot.'.png', false);
        }
    }

    public function test_about_page_screenshots_open_in_a_lightbox(): void
    {
        // Each capture is a button that opens the shared lightbox dialog; the
        // dialog itself must render exactly once.
        $html = $this->get(route('about'))
            ->assertOk()
            ->assertSee('data-shot-trigger', false)
            ->getContent();

        $this->assertSame(4, substr_count($html, '<button type="button" data-shot-trigger'));
        $this->assertSame(1, substr_count($html, 'id="shot-lb"'));
    }
}
