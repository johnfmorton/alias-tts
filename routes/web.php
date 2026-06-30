<?php

use App\Http\Controllers\Admin\AuthController;
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

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
