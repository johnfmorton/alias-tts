<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in page's "forgot your password?" guidance. Recovery is
 * human-mediated (no mail is ever sent), so the page must at minimum point a
 * locked-out user at a person — and at a pre-drafted email when the instance
 * sets TTS_SUPPORT_EMAIL.
 */
class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_support_email_it_shows_ask_your_admin_guidance(): void
    {
        config(['tts.support_email' => null]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee('Ask an administrator to send you a reset link');
    }

    public function test_with_a_support_email_it_links_a_predrafted_message(): void
    {
        config(['tts.support_email' => 'help@example.com', 'app.url' => 'https://tts.example.com']);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('mailto:help@example.com', $html);
        $this->assertStringContainsString(rawurlencode('Alias TTS password reset request'), $html);
        // The drafted body names the instance so the admin knows which install.
        $this->assertStringContainsString(rawurlencode('https://tts.example.com'), $html);
        $this->assertStringContainsString('Email the administrator', $html);
    }

    public function test_the_drafted_body_carries_the_typed_account_email(): void
    {
        config(['tts.support_email' => 'help@example.com']);

        // A failed login redirects back with the email as old input — the
        // re-rendered page's draft should carry it.
        $html = $this->from(route('login'))
            ->followingRedirects()
            ->post(route('login.submit'), ['email' => 'locked-out@example.com', 'password' => 'wrong'])
            ->getContent();

        $this->assertStringContainsString(rawurlencode('locked-out@example.com'), $html);
    }
}
