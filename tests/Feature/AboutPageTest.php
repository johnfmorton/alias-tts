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
            ->assertSee('Alias TTS for audio creators')
            ->assertSee('Make the voice yours.')
            ->assertSee('Shape every take.')
            ->assertSee('Fix the sentence, not the file.')
            ->assertSee(route('about.developers'), false);
    }

    public function test_about_page_walks_the_creator_workflow(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSeeInOrder(['Clone', 'Prepare', 'Direct', 'Seal'])
            ->assertSee('id="voice"', false)
            ->assertSee('id="prepare"', false)
            ->assertSee('id="direct"', false)
            ->assertSee('id="final"', false);
    }

    public function test_landing_page_links_to_the_about_page(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSeeInOrder(['Two APIs.', 'One Studio.', 'Your voices.'])
            ->assertSee('Alias TTS — Two APIs. One Studio. Your voices.', false)
            ->assertSee('Clone your voices, generate through familiar APIs, and shape every take in Studio.')
            ->assertSee(route('about'), false);
    }

    public function test_about_page_renders_the_bundled_screenshots(): void
    {
        $response = $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('Screenshot on the way');

        foreach (['voices', 'studio', 'verify'] as $shot) {
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

        $this->assertSame(3, substr_count($html, '<button type="button" data-shot-trigger'));
        $this->assertSame(1, substr_count($html, 'id="shot-lb"'));
    }

    public function test_audience_specific_about_drafts_are_publicly_viewable(): void
    {
        $this->get(route('about.developers'))
            ->assertOk()
            ->assertSee('Alias TTS for developers')
            ->assertSee('Keep the client.')
            ->assertSee('Upgrade the workflow.')
            ->assertSee(route('about'), false);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Alias TTS for audio creators')
            ->assertSee('Make the voice yours.')
            ->assertSee('Shape every take.')
            ->assertSee(route('about.developers'), false);

        $this->get(route('about.studio'))
            ->assertRedirect(route('about'));
    }
}
