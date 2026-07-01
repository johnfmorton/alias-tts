<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SocialAuthController;
use App\Http\Controllers\Admin\TwoFactorChallengeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MagicLoginController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

// Offline-capable "is this the approved final?" verifier. Public by design: it
// hashes a dropped file locally with Web Crypto and never uploads anything; the
// expected hash travels in the URL fragment (#expect=…), not the request. Served
// as the static file so the hosted page is byte-identical to the in-zip copy.
Route::get('/verify', fn () => response()->file(public_path('verify.html')))->name('verify');

// Single-use auto-login link for an API-created project. Guest-accessible by
// design: redeeming the token is what authenticates the visitor.
Route::get('/projects/open/{token}', [MagicLoginController::class, 'open'])->name('projects.open');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

// Second login step for 2FA accounts. Session-gated (login.2fa.*), not auth-gated —
// the user isn't signed in until the code checks out.
Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.verify');

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
