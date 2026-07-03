<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The app must honor a trusted proxy's X-Forwarded-* headers (real client IP +
 * https scheme) only when TRUSTED_PROXIES opts in — and ignore them otherwise,
 * so a directly-exposed install can't be spoofed. The global TrustProxies
 * middleware reads the list from config at request time (see the middleware).
 */
class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A tiny probe reachable through the global middleware stack.
        Route::get('/_test/proxy-probe', fn () => response()->json([
            'secure' => request()->isSecure(),
            'ip' => request()->ip(),
        ]));
    }

    /**
     * Request carrying a proxy's forwarded scheme + client IP. The base URL is
     * explicitly http:// so that trusting X-Forwarded-Proto: https is what flips
     * isSecure() — otherwise a https APP_URL (e.g. under DDEV) makes every
     * relative-path test request secure regardless of proxy trust.
     */
    private function probe(): TestResponse
    {
        return $this->get('http://localhost/_test/proxy-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.9',
        ]);
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        config(['tts.trusted_proxies' => null]);

        $this->probe()->assertOk()->assertJson([
            'secure' => false,       // the real https scheme is NOT trusted
            'ip' => '127.0.0.1',     // the caller's own IP, not the forwarded one
        ]);
    }

    public function test_a_wildcard_trusts_the_forwarded_scheme_and_client_ip(): void
    {
        config(['tts.trusted_proxies' => '*']);

        $this->probe()->assertOk()->assertJson([
            'secure' => true,
            'ip' => '203.0.113.9',
        ]);
    }

    public function test_an_ip_list_trusts_a_matching_caller(): void
    {
        // The test request's REMOTE_ADDR is 127.0.0.1, which is in the list.
        config(['tts.trusted_proxies' => '10.0.0.5, 127.0.0.1']);

        $this->probe()->assertOk()->assertJson([
            'secure' => true,
            'ip' => '203.0.113.9',
        ]);
    }

    public function test_an_ip_list_that_excludes_the_caller_does_not_trust_it(): void
    {
        config(['tts.trusted_proxies' => '10.0.0.5']);

        $this->probe()->assertOk()->assertJson([
            'secure' => false,
            'ip' => '127.0.0.1',
        ]);
    }
}
