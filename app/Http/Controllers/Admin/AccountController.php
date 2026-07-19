<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AvatarProcessor;
use App\Services\Settings\SettingsManager;
use App\Services\TwoFactorService;
use App\Support\GettingStarted;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self-service account management (design 2A) — any signed-in user manages their
 * own profile, password, avatar, and account, regardless of role. Two-factor setup
 * lives in TwoFactorController and connected-account (SSO) in SocialAuthController;
 * index() feeds this screen their current state.
 */
class AccountController extends Controller
{
    public function index(Request $request, TwoFactorService $twoFactor, SettingsManager $settings): View
    {
        $user = $request->user();

        return view('admin.account.index', [
            'user' => $user,
            'two' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => $user->hasTwoFactorPending(),
                'qr' => $user->hasTwoFactorPending() ? $twoFactor->qrSvg($user, $user->two_factor_secret) : null,
                'secret' => $user->hasTwoFactorPending() ? $user->two_factor_secret : null,
            ],
            'providers' => $this->providerStatus($user),
            // Only when EVERY message's key is env-pinned is there nothing the
            // restore button could bring back — then the Interface card hides.
            'gettingStartedLocked' => collect(GettingStarted::PAGES)->every(fn (string $key) => $settings->isLocked($key)),
        ]);
    }

    /** Per-provider SSO status for the Connected-accounts section. */
    private function providerStatus(User $user): Collection
    {
        return collect(['google' => 'Google', 'github' => 'GitHub'])->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'configured' => filled(config("services.{$key}.client_id")) && filled(config("services.{$key}.client_secret")),
            'account' => $user->connectedAccounts->firstWhere('provider', $key),
        ])->values();
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

    public function updateAvatar(Request $request, AvatarProcessor $processor): RedirectResponse
    {
        $request->validate([
            'avatar' => [
                'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096',
                // Guard against decompression bombs before the processor decodes:
                // getimagesize() reads only the header, so a tiny file claiming
                // gigapixel dimensions is rejected without allocating the bitmap.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $info = @getimagesize($value->getRealPath());
                    if ($info === false) {
                        $fail('The photo must be a valid image file.');

                        return;
                    }
                    if ($info[0] * $info[1] > AvatarProcessor::MAX_SOURCE_PIXELS) {
                        $fail('The photo is too large in dimensions. Please use an image under 40 megapixels.');
                    }
                },
            ],
        ]);

        $user = $request->user();
        $old = $user->avatar_path;

        // Re-encode to a small square WebP. This strips anything riding along in
        // the original file and downsamples large images — we store only the
        // result, never the uploaded bytes. Random filename, fixed extension.
        $webp = $processor->toWebp($request->file('avatar'));
        $path = 'avatars/'.Str::uuid()->toString().'.webp';

        // Same private disk as audio (B2 in prod); served back via the proxy route.
        $disk = $this->avatarDisk();
        $disk->put($path, $webp);
        $user->update(['avatar_path' => $path]);

        if ($old && $old !== $path) {
            $disk->delete($old);
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
            // Never let a browser sniff a stored avatar into something executable.
            'X-Content-Type-Options' => 'nosniff',
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
