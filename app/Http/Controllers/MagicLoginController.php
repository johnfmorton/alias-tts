<?php

namespace App\Http\Controllers;

use App\Models\MagicLoginToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Redeems a project's single-use auto-login link (minted by
 * {@see ProjectApiController}). A valid token logs the bound user in and lands
 * them on the project; a spent, expired, or unknown token is a 403. This route
 * is intentionally guest-accessible — it's how an unauthenticated visitor
 * becomes authenticated.
 */
class MagicLoginController extends Controller
{
    public function open(Request $request, string $token): RedirectResponse
    {
        $record = MagicLoginToken::redeem($token);

        abort_if($record === null, 403, 'This link has expired or has already been used.');

        Auth::login($record->user);
        $request->session()->regenerate();

        return redirect()->route('admin.studio.projects.show', $record->tts_project_id);
    }
}
