<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trust the proxy in front of us (Cloudflare, a load balancer, nginx) so the app
 * reads the real client IP and the true request scheme from its X-Forwarded-*
 * headers, instead of seeing the proxy's own IP and http.
 *
 * The trusted list is read from config HERE, at request time — deliberately not
 * in bootstrap/app.php, whose middleware closure runs BEFORE the framework loads
 * configuration, so an env()/config() read there returns null under a cached
 * production config (the same Forge "optimize" trap that bit admin:create).
 *
 * Opt-in: TRUSTED_PROXIES unset => trust nothing, which is correct for a
 * directly-exposed install (trusting forwarded headers there would let any
 * client spoof its IP or scheme). "*" trusts any upstream — only safe when the
 * origin is reachable ONLY through the proxy (firewall the origin to the proxy's
 * IPs); otherwise set a comma-separated list of the proxy's IP ranges.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = trim((string) config('tts.trusted_proxies'));

        // '*' / '**' and comma-separated IP lists are both handled by the parent;
        // null falls through to "trust nothing" (plus Forge's built-in
        // *.on-forge.com auto-trust, which the parent still applies).
        return $proxies === '' ? null : $proxies;
    }
}
