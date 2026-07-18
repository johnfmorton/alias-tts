@php
    // Shared field/button class strings, kept here so the three cards read cleanly.
    $well = 'w-full rounded-[9px] border border-edge bg-inset px-[13px] py-[11px] text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none';
    $btnPrimary = 'inline-flex items-center justify-center rounded-[9px] bg-accent px-[18px] py-[9px] text-sm font-semibold text-accent-on transition hover:bg-accent/90';
    $btnSecondary = 'inline-flex items-center justify-center rounded-[9px] border border-white/14 px-[14px] py-2 text-sm text-zinc-300 transition hover:bg-white/[0.04]';
    $btnSecondaryDisabled = 'inline-flex items-center justify-center rounded-[9px] border border-white/8 px-[14px] py-2 text-sm text-zinc-600 cursor-not-allowed';
    $badgeOk = 'rounded-[6px] border border-ok/25 bg-ok/[0.12] px-[9px] py-[3px] text-xs font-semibold text-ok';
    $badgeNeutral = 'rounded-[6px] border border-white/12 bg-white/[0.06] px-[9px] py-[3px] text-xs font-semibold text-zinc-400';
    $card = 'rounded-[14px] border border-white/8 bg-panel p-6';
@endphp

<x-layout title="Account" description="Manage your profile, security, and how you sign in." content-width="max-w-[720px]">

    {{-- ============ Credit (metered accounts only) ============ --}}
    @if($user->hasLimitedCredit())
        <div class="{{ $card }} mb-5">
            <h2 class="text-[17px] font-semibold text-zinc-100">Credit</h2>
            <div class="mt-3 text-2xl font-bold {{ $user->credit_balance_micro <= 0 ? 'text-warn' : 'text-zinc-100' }}">
                {{ \App\Services\Credit\CreditService::formatMicro($user->credit_balance_micro) }}
            </div>
            <p class="mt-2 text-sm text-zinc-500">
                Speech generation spends this balance and pauses when it reaches $0 — audio you've already
                generated stays available.
                @if(config('tts.support_email'))
                    Need more? Contact <a href="mailto:{{ config('tts.support_email') }}" class="text-accent hover:underline">{{ config('tts.support_email') }}</a>.
                @endif
            </p>
        </div>
    @endif

    {{-- ============ Profile ============ --}}
    <div class="{{ $card }} mb-5">
        <h2 class="text-[17px] font-semibold text-zinc-100">Profile</h2>

        <div class="mt-5 mb-[22px] flex items-center gap-4">
            <x-avatar :user="$user" :size="64" class="border border-accent/40" />
            <div class="flex items-center gap-2.5">
                <form id="avatar-form" method="POST" action="{{ route('admin.account.avatar') }}" enctype="multipart/form-data">
                    @csrf
                    <input id="avatar-input" name="avatar" type="file" accept="image/*" class="hidden">
                    <button id="avatar-change" type="button" class="{{ $btnSecondary }}">Change photo</button>
                </form>
                @if($user->avatar_path)
                    <form method="POST" action="{{ route('admin.account.avatar.delete') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="px-1.5 py-2 text-sm text-zinc-400 transition hover:text-zinc-200">Remove</button>
                    </form>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('admin.account.profile') }}">
            @csrf
            @method('PUT')
            <div class="mb-4">
                <label for="name" class="mb-[7px] block text-[13px] text-zinc-400">Display name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required class="{{ $well }}">
            </div>
            <div class="mb-[22px]">
                <label for="email" class="mb-[7px] block text-[13px] text-zinc-400">Email</label>
                <div class="relative">
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required
                           class="{{ $well }} {{ $user->email_verified_at ? 'pr-24' : '' }}">
                    @if($user->email_verified_at)
                        <span class="absolute top-1/2 right-[10px] -translate-y-1/2 {{ $badgeOk }}">Verified</span>
                    @endif
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="{{ $btnPrimary }}">Save changes</button>
            </div>
        </form>
    </div>

    {{-- ============ Password & security ============ --}}
    <div class="{{ $card }} mb-5">
        <h2 class="text-[17px] font-semibold text-zinc-100">Password &amp; security</h2>

        {{-- Password --}}
        <div class="flex items-center justify-between border-b border-white/8 py-[18px]">
            <div>
                <div class="text-sm text-zinc-200">Password</div>
                <div class="mt-[3px] text-[13px] text-zinc-500">Use a strong password unique to this account.</div>
            </div>
            <button id="password-toggle" type="button" class="{{ $btnSecondary }}">Change password</button>
        </div>
        <div id="password-form" class="hidden border-b border-white/8 py-[18px]">
            <form method="POST" action="{{ route('admin.account.password') }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label for="current_password" class="mb-[7px] block text-[13px] text-zinc-400">Current password</label>
                    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required class="{{ $well }}">
                </div>
                <div>
                    <label for="password" class="mb-[7px] block text-[13px] text-zinc-400">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required class="{{ $well }}">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-[7px] block text-[13px] text-zinc-400">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="{{ $well }}">
                </div>
                <div class="flex justify-end gap-2.5 pt-1">
                    <button id="password-cancel" type="button" class="{{ $btnSecondary }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Update password</button>
                </div>
            </form>
        </div>

        {{-- Two-factor authentication --}}
        <div class="border-b border-white/8 py-[18px]">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-sm text-zinc-200">Two-factor authentication</div>
                    <div class="mt-[3px] text-[13px] text-zinc-500">Authenticator app (TOTP)</div>
                </div>
                <div class="flex items-center gap-2.5">
                    @if($two['enabled'])
                        <span class="{{ $badgeOk }}">On</span>
                        <button id="twofa-manage-toggle" type="button" class="{{ $btnSecondary }}">Manage</button>
                    @elseif($two['pending'])
                        <span class="{{ $badgeNeutral }}">Setup pending</span>
                    @else
                        <span class="{{ $badgeNeutral }}">Off</span>
                        <form method="POST" action="{{ route('admin.account.2fa.enable') }}">
                            @csrf
                            <button type="submit" class="{{ $btnSecondary }}">Set up</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- Pending: scan the QR + confirm a code to finish --}}
            @if($two['pending'])
                <div class="mt-4 rounded-[12px] border border-white/10 bg-inset p-4">
                    <div class="flex flex-wrap items-start gap-5">
                        <div class="shrink-0 rounded-[10px] bg-white p-2">{!! $two['qr'] !!}</div>
                        <div class="min-w-[220px] flex-1">
                            <p class="text-[13px] leading-relaxed text-zinc-400">Scan this with your authenticator app (or type the key in manually), then enter the 6-digit code to finish.</p>
                            <div class="mt-2 font-mono text-xs break-all text-zinc-500">{{ $two['secret'] }}</div>
                            <div class="mt-3 flex flex-wrap items-end gap-2.5">
                                <form method="POST" action="{{ route('admin.account.2fa.confirm') }}" class="flex items-end gap-2.5">
                                    @csrf
                                    <div>
                                        <label for="code" class="mb-[7px] block text-[13px] text-zinc-400">6-digit code</label>
                                        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required
                                               class="{{ $well }} w-36 font-mono tracking-widest">
                                    </div>
                                    <button type="submit" class="{{ $btnPrimary }}">Confirm</button>
                                </form>
                                <form method="POST" action="{{ route('admin.account.2fa.disable') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-2 py-2 text-sm text-zinc-500 hover:text-zinc-300">Cancel</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Enabled → Manage: regenerate codes / turn off (both need the password) --}}
            @if($two['enabled'])
                <div id="twofa-manage" class="mt-4 hidden space-y-3 rounded-[12px] border border-white/10 bg-inset p-4">
                    <form method="POST" action="{{ route('admin.account.2fa.recovery') }}" class="flex flex-wrap items-end gap-2.5">
                        @csrf
                        <div class="min-w-[200px] flex-1">
                            <label class="mb-[7px] block text-[13px] text-zinc-400">Password — to regenerate recovery codes</label>
                            <input name="password" type="password" autocomplete="current-password" required class="{{ $well }}">
                        </div>
                        <button type="submit" class="{{ $btnSecondary }}">Regenerate codes</button>
                    </form>
                    <form method="POST" action="{{ route('admin.account.2fa.disable') }}" class="flex flex-wrap items-end gap-2.5">
                        @csrf
                        @method('DELETE')
                        <div class="min-w-[200px] flex-1">
                            <label class="mb-[7px] block text-[13px] text-zinc-400">Password — to turn off 2FA</label>
                            <input name="password" type="password" autocomplete="current-password" required class="{{ $well }}">
                        </div>
                        <button type="submit" class="rounded-[9px] border border-bad/45 px-[14px] py-2 text-sm text-bad transition hover:bg-bad/[0.06]">Turn off</button>
                    </form>
                </div>
            @endif
        </div>

        {{-- Recovery codes — shown once, right after enabling or regenerating --}}
        @if(session('recovery_codes'))
            <div class="border-b border-white/8 py-[18px]">
                <div class="rounded-[12px] border border-ok/30 bg-ok/[0.06] p-4">
                    <div class="text-sm font-semibold text-ok">Recovery codes</div>
                    <p class="mt-1 text-[13px] leading-relaxed text-zinc-400">Store these somewhere safe. Each works once if you lose your authenticator — they won't be shown again.</p>
                    <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[13px] text-zinc-200">
                        @foreach(session('recovery_codes') as $rc)<div>{{ $rc }}</div>@endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Connected accounts (SSO) --}}
        <div class="pt-[18px]">
            <div class="mb-3.5 text-sm text-zinc-200">Connected accounts</div>
            @foreach($providers as $p)
                <div class="mb-3 last:mb-0">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-[11px]">
                            <span class="grid h-[30px] w-[30px] place-items-center rounded-[7px] bg-zinc-800 font-bold text-zinc-200 {{ $p['key'] === 'github' ? 'font-mono text-[13px]' : 'text-sm' }}">{{ $p['key'] === 'github' ? 'GH' : 'G' }}</span>
                            <div>
                                <div class="text-sm text-zinc-200">{{ $p['label'] }}</div>
                                <div class="mt-0.5 text-xs text-zinc-500">{{ $p['account'] ? ($p['account']->email ?? 'Connected') : (! $p['configured'] ? 'Not configured' : 'Not connected') }}</div>
                            </div>
                        </div>
                        @if($p['account'])
                            <form method="POST" action="{{ route('admin.account.connections.disconnect', $p['key']) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="{{ $btnSecondary }}">Disconnect</button>
                            </form>
                        @elseif($p['configured'])
                            <button type="button" class="connect-toggle {{ $btnSecondary }}" data-target="connect-{{ $p['key'] }}">Connect</button>
                        @else
                            <button type="button" disabled title="Set {{ strtoupper($p['key']) }}_CLIENT_ID and _SECRET in .env" class="{{ $btnSecondaryDisabled }}">Connect</button>
                        @endif
                    </div>
                    {{-- Connecting adds a sign-in method, so it's password-gated. --}}
                    @if(! $p['account'] && $p['configured'])
                        <div id="connect-{{ $p['key'] }}" class="mt-2.5 hidden">
                            <form method="POST" action="{{ route('admin.account.connections.connect', $p['key']) }}" class="flex flex-wrap items-end gap-2.5">
                                @csrf
                                <div class="min-w-[200px] flex-1">
                                    <label class="mb-[7px] block text-[13px] text-zinc-400">Password — to connect {{ $p['label'] }}</label>
                                    <input name="password" type="password" autocomplete="current-password" required class="{{ $well }}">
                                </div>
                                <button type="submit" class="{{ $btnPrimary }}">Connect {{ $p['label'] }}</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- ============ Danger zone ============ --}}
    <div class="rounded-[14px] border border-bad/28 bg-bad/[0.04] p-6">
        <h2 class="text-[17px] font-semibold text-bad">Danger zone</h2>
        <div class="mt-4 flex items-center justify-between gap-5">
            <p class="max-w-[440px] text-[13px] leading-relaxed text-zinc-400">
                Permanently delete your account and every project, take, and generation tied to it. This cannot be undone.
            </p>
            <button id="danger-toggle" type="button"
                    class="shrink-0 rounded-[9px] border border-bad/45 px-4 py-[9px] text-sm font-semibold text-bad transition hover:bg-bad/[0.06]">
                Delete account
            </button>
        </div>
        <div id="danger-confirm" class="mt-4 hidden border-t border-bad/20 pt-4">
            <form method="POST" action="{{ route('admin.account.destroy') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @method('DELETE')
                <div class="min-w-[220px] flex-1">
                    <label for="delete_password" class="mb-[7px] block text-[13px] text-zinc-400">Confirm with your password</label>
                    <input id="delete_password" name="password" type="password" autocomplete="current-password" required class="{{ $well }}">
                </div>
                <button type="submit" class="rounded-[9px] bg-bad px-4 py-[11px] text-sm font-semibold text-white transition hover:bg-bad/90">
                    Permanently delete
                </button>
                <button id="danger-cancel" type="button" class="{{ $btnSecondary }}">Cancel</button>
            </form>
        </div>
    </div>

</x-layout>
