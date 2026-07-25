@php
    $btnPrimary = 'inline-flex items-center justify-center rounded-[9px] bg-accent px-4 py-[9px] text-sm font-semibold text-accent-on transition hover:bg-accent/90';
    $btnSecondary = 'inline-flex items-center justify-center rounded-[9px] border border-white/14 px-4 py-[9px] text-sm text-zinc-300 transition hover:bg-white/[0.04]';
    $well = 'w-full rounded-[9px] border border-white/10 bg-inset px-[13px] py-[10px] text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none';
    $cols = 'grid-cols-[1.8fr_2fr_1fr_1fr_1.1fr_1fr_1fr_0.8fr]';

    $roleBadge = fn (\App\Models\User $u) => $u->isSuperAdmin()
        ? '<span class="rounded-[6px] border border-accent/30 bg-accent/[0.12] px-[9px] py-[3px] text-xs font-semibold text-accent">SuperAdmin</span>'
        : '<span class="rounded-[6px] border border-white/12 bg-white/[0.06] px-[9px] py-[3px] text-xs font-semibold text-zinc-400">User</span>';

    $statusBadge = function (\App\Models\User $u) {
        return match ($u->status) {
            \App\Models\User::STATUS_ACTIVE => '<span class="rounded-[6px] border border-ok/25 bg-ok/[0.12] px-[9px] py-[3px] text-xs font-semibold text-ok">Active</span>',
            \App\Models\User::STATUS_SUSPENDED => '<span class="rounded-[6px] border border-warn/28 bg-warn/[0.12] px-[9px] py-[3px] text-xs font-semibold text-warn">Suspended</span>',
            default => '<span class="rounded-[6px] border border-white/15 bg-white/[0.06] px-[9px] py-[3px] text-xs font-semibold text-zinc-400">Invited</span>',
        };
    };
@endphp

<x-layout title="Users" description="Manage everyone with access to this server.">
    <x-slot:titleActions>
        <span class="rounded-[6px] border border-accent/30 bg-accent/[0.12] px-[9px] py-[3px] text-xs font-semibold text-accent">SuperAdmin</span>
    </x-slot:titleActions>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <p class="text-sm text-zinc-500">{{ $activeCount }} active · {{ $invitedCount }} invited.</p>
        <div class="flex gap-2.5">
            <button id="invite-toggle" type="button" class="{{ $btnSecondary }}">Invite by link</button>
            <button id="create-toggle" type="button" class="{{ $btnPrimary }}">+ Create user</button>
        </div>
    </div>

    @include('admin.users._reveals')

    {{-- Invite form (revealed) --}}
    <div id="invite-form" class="mb-6 hidden rounded-[14px] border border-white/8 bg-panel p-5">
        <form method="POST" action="{{ route('admin.users.invite') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-[240px] flex-1">
                <label class="mb-1.5 block text-[13px] text-zinc-400">Email to invite</label>
                <input name="email" type="email" required placeholder="name@example.com" class="{{ $well }}">
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] text-zinc-400">Role</label>
                <select name="role" class="{{ $well }}">
                    <option value="User" selected>User</option>
                    <option value="SuperAdmin">SuperAdmin</option>
                </select>
            </div>
            <button type="submit" class="{{ $btnPrimary }}">Create invite link</button>
        </form>
        <p class="mt-3 text-xs text-zinc-500">No email is sent — you'll get a link to share yourself. Your friend clicks it to set their own password (valid 7 days).</p>
    </div>

    {{-- Create form (revealed) --}}
    <div id="create-form" class="mb-6 hidden rounded-[14px] border border-white/8 bg-panel p-5">
        <form method="POST" action="{{ route('admin.users.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-[180px] flex-1">
                <label class="mb-1.5 block text-[13px] text-zinc-400">Display name</label>
                <input name="name" type="text" required class="{{ $well }}">
            </div>
            <div class="min-w-[220px] flex-1">
                <label class="mb-1.5 block text-[13px] text-zinc-400">Email</label>
                <input name="email" type="email" required class="{{ $well }}">
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] text-zinc-400">Role</label>
                <select name="role" class="{{ $well }}">
                    <option value="User" selected>User</option>
                    <option value="SuperAdmin">SuperAdmin</option>
                </select>
            </div>
            <button type="submit" class="{{ $btnPrimary }}">Create user</button>
        </form>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-[14px] border border-white/8 bg-panel">
        <div class="grid {{ $cols }} border-b border-white/8 px-[22px] py-[13px] text-[11px] font-semibold tracking-wide text-zinc-500 uppercase">
            <div>User</div><div>Email</div><div>Role</div><div>Status</div><div>Last active</div><div>Balance</div><div>Spend</div><div>Gens</div>
        </div>

        @foreach($users as $u)
            <a href="{{ route('admin.users.show', $u) }}"
               class="grid {{ $cols }} items-center border-b border-white/[0.06] px-[22px] py-[15px] transition last:border-b-0 hover:bg-white/[0.02]">
                <div class="flex items-center gap-[11px]">
                    <x-avatar :user="$u" :size="30" :accent="$u->isSuperAdmin()" />
                    <span class="truncate text-sm text-zinc-100">{{ $u->name }}</span>
                </div>
                <div class="truncate pr-2 text-[13px] text-zinc-400">{{ $u->email }}</div>
                <div>{!! $roleBadge($u) !!}</div>
                <div>{!! $statusBadge($u) !!}</div>
                <div class="text-[13px] text-zinc-500">{{ $u->last_active_at?->diffForHumans() ?? '—' }}</div>
                {{-- Prepaid balance; "Unlimited" = no metering (the default). --}}
                <div class="text-[13px] {{ $u->hasLimitedCredit() ? ($u->credit_balance_micro <= 0 ? 'font-semibold text-warn' : 'text-zinc-200') : 'text-zinc-500' }}">
                    {{ \App\Services\Credit\CreditService::formatMicro($u->credit_balance_micro) }}
                </div>
                {{-- Lifetime spend: what the user was billed (marked-up). When a
                     markup is in effect the provider cost to you shows on hover. --}}
                @php
                    $sp = $spend[$u->id] ?? null;
                    $billed = (int) ($sp->billed ?? 0);
                    $actual = (int) ($sp->actual ?? 0);
                @endphp
                <div class="text-[13px] {{ $billed > 0 ? 'text-zinc-300' : 'text-zinc-600' }}"
                     @if($billed > 0 && $billed !== $actual) title="Cost you {{ \App\Services\Credit\CreditService::formatMicro($actual) }}" @endif>
                    {{ $billed > 0 ? \App\Services\Credit\CreditService::formatMicro($billed) : '—' }}
                </div>
                <div class="text-sm text-zinc-200">{{ $gens[$u->id] ?? 0 }}</div>
            </a>
        @endforeach
    </div>

</x-layout>
