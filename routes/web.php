<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SocialAuthController;
use App\Http\Controllers\Admin\TwoFactorChallengeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\VerifyController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

// Public feature tour. Screenshots dropped at public/images/about/ replace the
// on-page placeholders automatically (see resources/views/components/about/shot.blade.php).
Route::view('/about', 'about.studio')->name('about');
Route::view('/about/developers', 'about.developers')->name('about.developers');
Route::redirect('/about/studio', '/about')->name('about.studio');

// Public, server-side "is this the approved final?" verifier. The server hashes
// the uploaded bytes and matches a sealed project's final_sha256; a `?sha=` link
// opens the authoritative record for a known fingerprint. The POST is throttled
// per IP (audio uploads) and capped by tts.verify_max_upload_kb.
Route::get('/verify', [VerifyController::class, 'show'])->name('verify');
Route::post('/verify', [VerifyController::class, 'check'])
    ->middleware('throttle:20,1')
    ->name('verify.check');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // Throttle password guesses (per IP): 10 tries a minute is plenty for a human.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
});

// Second login step for 2FA accounts. Session-gated (login.2fa.*), not auth-gated —
// the user isn't signed in until the code checks out. Throttled hard because a
// 6-digit code is brute-forceable; the controller also caps per-session attempts.
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1')->name('two-factor.verify');

// SSO redirect + callback. One pair serves both intents: an authed user connects a
// provider; a guest signs in through an already-connected one. Dormant until creds
// are set (see docs/SSO-SETUP.md).
Route::get('/oauth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('oauth.redirect');
Route::get('/oauth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('oauth.callback');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Accept an invite / forced reset by setting a password. The GET is signed; a valid
// signature authorizes this session to POST a new password for that user (see
// InvitationController). Guest-accessible by design.
Route::get('/invite/{user}', [InvitationController::class, 'show'])->middleware('signed')->name('invite.accept');
Route::post('/invite/{user}', [InvitationController::class, 'store'])->name('invite.store');
