<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor helpers: secret generation, code verification, an inline SVG QR
 * (rendered locally — the secret is never sent to a third party), and recovery
 * codes. Wraps pragmarx/google2fa + bacon/bacon-qr-code.
 */
class TwoFactorService
{
    public function __construct(private Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /** Verify a 6-digit code against the secret, tolerating one time-step of drift. */
    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    /** An inline SVG QR of the otpauth:// URI for the authenticator app to scan. */
    public function qrSvg(User $user, string $secret): string
    {
        $uri = $this->google2fa->getQRCodeUrl(
            config('app.name', 'Mimic TTS'),
            $user->email,
            $secret,
        );

        $writer = new Writer(new ImageRenderer(new RendererStyle(196, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** Eight single-use recovery codes, Fortify-style (two dashed halves). */
    public function recoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::lower(Str::random(10).'-'.Str::random(10)))
            ->all();
    }
}
