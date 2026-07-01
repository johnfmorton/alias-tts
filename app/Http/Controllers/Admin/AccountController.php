<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self-service account management (design 2A) — any signed-in user manages their
 * own profile, password, avatar, and account, regardless of role. Two-factor and
 * connected-account (SSO) wiring lands in the next phase; the screen renders those
 * sections now so the layout is complete.
 */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.account.index', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // The model casts `password` as hashed, so assigning plaintext hashes it.
        $request->user()->update(['password' => $request->input('password')]);

        return back()->with('success', 'Password changed.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $old = $user->avatar_path;

        // Same private disk as audio (B2 in prod); served back via the proxy route.
        $path = $request->file('avatar')->store('avatars', config('tts.storage_disk'));
        $user->update(['avatar_path' => $path]);

        if ($old) {
            $this->avatarDisk()->delete($old);
        }

        return back()->with('success', 'Photo updated.');
    }

    public function deleteAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            $this->avatarDisk()->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return back()->with('success', 'Photo removed.');
    }

    /**
     * Stream a user's avatar from the private disk. Guests can't reach it (the whole
     * panel is auth-gated) and any signed-in user may view any avatar — they surface
     * in the nav and, for SuperAdmins, the Users table.
     */
    public function avatar(User $user): Response
    {
        abort_unless($user->avatar_path, 404);

        $disk = $this->avatarDisk();
        abort_unless($disk->exists($user->avatar_path), 404);

        $mime = match (strtolower(pathinfo($user->avatar_path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return response($disk->get($user->avatar_path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function avatarDisk(): Filesystem
    {
        return Storage::disk(config('tts.storage_disk'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Never let the last SuperAdmin delete themselves — that would lock everyone
        // out of the server's admin surface with no way back in.
        if ($user->isSuperAdmin() && User::where('is_super_admin', true)->count() <= 1) {
            return back()->with('error', 'You are the only SuperAdmin. Promote another user before deleting your account.');
        }

        if ($user->avatar_path) {
            $this->avatarDisk()->delete($user->avatar_path);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $user->delete();

        return redirect()->route('login')->with('success', 'Your account has been deleted.');
    }
}
