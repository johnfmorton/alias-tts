@php
    $closeUrl = route('admin.users.index');
    $firstName = \Illuminate\Support\Str::before($u->name, ' ') ?: $u->name;
    $tile = 'rounded-[10px] border border-white/8 bg-inset p-3';
    $action = 'w-full rounded-[9px] border py-[10px] text-center text-sm transition';
    $userActive = ! $u->isSuperAdmin();
@endphp

{{-- Scrim: click to close --}}
<a href="{{ $closeUrl }}" class="fixed inset-x-0 top-[74px] bottom-0 z-40 bg-black/45" aria-label="Close"></a>

<aside class="fixed top-[74px] right-0 bottom-0 z-50 w-96 max-w-full overflow-y-auto border-l border-white/10 bg-drawer p-6 shadow-[-24px_0_50px_-12px_rgba(0,0,0,0.6)]">
    <div class="flex items-start justify-between">
        <div class="flex items-center gap-[13px]">
            <x-avatar :user="$u" :size="48" :accent="$u->isSuperAdmin()" />
            <div class="min-w-0">
                <div class="truncate text-base font-semibold text-zinc-100">{{ $u->name }}</div>
                <div class="mt-0.5 truncate text-xs text-zinc-500">{{ $u->email }}</div>
            </div>
        </div>
        <a href="{{ $closeUrl }}" class="text-lg leading-none text-zinc-500 transition hover:text-zinc-300">&times;</a>
    </div>

    {{-- Stat tiles --}}
    <div class="my-[22px] grid grid-cols-3 gap-2.5">
        <div class="{{ $tile }}">
            <div class="text-[11px] tracking-wide text-zinc-500 uppercase">Gens</div>
            <div class="mt-1 text-lg font-bold text-zinc-100">{{ number_format($gens) }}</div>
        </div>
        <div class="{{ $tile }}">
            <div class="text-[11px] tracking-wide text-zinc-500 uppercase">API keys</div>
            <div class="mt-1 text-lg font-bold text-zinc-100">{{ number_format($keys) }}</div>
        </div>
        <div class="{{ $tile }}">
            <div class="text-[11px] tracking-wide text-zinc-500 uppercase">Active</div>
            <div class="mt-1 text-sm font-bold text-zinc-100">{{ $u->last_active_at?->diffForHumans(null, true) ?? '—' }}</div>
        </div>
    </div>

    {{-- Prepaid credit: balance + grant/adjust + recent ledger rows. The
         balance is what the USER is charged (marked-up); the actual provider
         cost shows alongside — this whole screen is SuperAdmin-only. --}}
    @php $fmt = \App\Services\Credit\CreditService::formatMicro(...); @endphp
    <div class="mb-2 text-[13px] text-zinc-400">Credit</div>
    <div class="mb-[22px] rounded-[10px] border border-white/8 bg-inset p-3">
        <div class="flex items-baseline justify-between">
            <div class="text-lg font-bold {{ $u->hasLimitedCredit() && $u->credit_balance_micro <= 0 ? 'text-warn' : 'text-zinc-100' }}">
                {{ $fmt($u->credit_balance_micro) }}
            </div>
            @if($u->hasLimitedCredit())
                <form method="POST" action="{{ route('admin.users.credit.unlimited', $u) }}">
                    @csrf
                    @method('DELETE')
                    <button data-confirm="Remove {{ $firstName }}'s spending limit? Their generation will no longer be metered against a balance."
                            class="text-xs text-zinc-500 underline-offset-2 transition hover:text-zinc-300 hover:underline">Make unlimited</button>
                </form>
            @endif
        </div>
        @if(($creditTotals['charged_micro'] ?? 0) > 0)
            <div class="mt-1 text-xs text-zinc-500">
                Lifetime: billed {{ $fmt($creditTotals['charged_micro']) }} · cost you {{ $fmt($creditTotals['actual_micro']) }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.users.credit', $u) }}" class="mt-3 flex items-end gap-2">
            @csrf
            <div class="w-24 shrink-0">
                <label class="mb-1 block text-[11px] tracking-wide text-zinc-500 uppercase">Amount $</label>
                <input name="amount" type="number" step="0.01" min="-10000" max="10000" required placeholder="5.00"
                       class="w-full rounded-[8px] border border-edge bg-panel px-2.5 py-2 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none">
            </div>
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-[11px] tracking-wide text-zinc-500 uppercase">Note</label>
                <input name="note" type="text" maxlength="255" placeholder="optional"
                       class="w-full rounded-[8px] border border-edge bg-panel px-2.5 py-2 text-sm text-zinc-200 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none">
            </div>
            <button class="shrink-0 rounded-[8px] bg-accent px-3 py-2 text-sm font-semibold text-accent-on transition hover:bg-accent/90">Add</button>
        </form>
        <div class="mt-1.5 text-[11px] leading-snug text-zinc-600">Negative amounts adjust down. Granting to an unlimited account starts metering it.</div>

        @if($creditRecent->isNotEmpty())
            <ul class="mt-3 space-y-1 border-t border-white/8 pt-2.5">
                @foreach($creditRecent as $tx)
                    <li class="flex items-center justify-between gap-2 text-[11px] text-zinc-500"
                        @if($tx->type === \App\Models\CreditTransaction::TYPE_CHARGE)
                            title="Actual provider cost: {{ $fmt($tx->actual_cost_micro ?? 0) }}{{ $tx->characters ? ' · '.number_format($tx->characters).' chars ('.$tx->model.')' : '' }}"
                        @elseif($tx->note)
                            title="{{ $tx->note }}"
                        @endif>
                        <span class="truncate">{{ $tx->created_at->format('M j') }} · {{ $tx->source }}</span>
                        <span class="{{ $tx->amount_micro < 0 ? 'text-zinc-400' : 'text-ok' }}">{{ $tx->amount_micro > 0 ? '+' : '' }}{{ $fmt($tx->amount_micro) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Role segmented control --}}
    <div class="mb-2 text-[13px] text-zinc-400">Role</div>
    <form method="POST" action="{{ route('admin.users.role', $u) }}" class="mb-[22px]">
        @csrf
        @method('PATCH')
        <div class="flex rounded-[9px] border border-white/10 bg-inset p-1">
            <button type="{{ $userActive ? 'button' : 'submit' }}" name="role" value="User" @disabled($isSelf)
                    class="flex-1 rounded-[6px] py-2 text-center text-sm transition {{ $userActive ? 'bg-accent font-semibold text-accent-on' : 'text-zinc-400 hover:text-zinc-200' }} {{ $isSelf ? 'cursor-not-allowed opacity-60' : '' }}">User</button>
            <button type="{{ $userActive ? 'submit' : 'button' }}" name="role" value="SuperAdmin" @disabled($isSelf)
                    class="flex-1 rounded-[6px] py-2 text-center text-sm transition {{ ! $userActive ? 'bg-accent font-semibold text-accent-on' : 'text-zinc-400 hover:text-zinc-200' }} {{ $isSelf ? 'cursor-not-allowed opacity-60' : '' }}">SuperAdmin</button>
        </div>
    </form>

    @if($isSelf)
        <div class="rounded-[10px] border border-white/8 bg-inset px-3 py-2.5 text-[13px] text-zinc-400">
            This is your account. Change your password or delete it from <a href="{{ route('admin.account.index') }}" class="text-accent hover:underline">Account</a>.
        </div>
    @else
        <div class="flex flex-col gap-2.5">
            {{-- Suspend / reactivate --}}
            <form method="POST" action="{{ route('admin.users.suspend', $u) }}">
                @csrf
                @if($u->isSuspended())
                    <button class="{{ $action }} border-ok/35 text-ok hover:bg-ok/[0.06]">Reactivate account</button>
                @else
                    <button class="{{ $action }} border-warn/35 text-warn hover:bg-warn/[0.06]">Suspend account</button>
                @endif
            </form>

            {{-- Force reset / resend invite --}}
            <form method="POST" action="{{ route('admin.users.force-reset', $u) }}">
                @csrf
                <button class="{{ $action }} border-white/14 text-zinc-300 hover:bg-white/[0.04]">
                    {{ $u->status === \App\Models\User::STATUS_INVITED ? 'Resend invite link' : 'Force password reset' }}
                </button>
            </form>

            {{-- Impersonate (active users only) --}}
            @if($u->status === \App\Models\User::STATUS_ACTIVE)
                <form method="POST" action="{{ route('admin.users.impersonate', $u) }}">
                    @csrf
                    <button class="{{ $action }} border-white/14 text-zinc-300 hover:bg-white/[0.04]">Sign in as {{ $firstName }}</button>
                </form>
            @endif

            <div class="my-1 h-px bg-white/8"></div>

            {{-- Delete (two-step) --}}
            <button id="user-delete-toggle" type="button" class="{{ $action }} border-bad/45 font-semibold text-bad hover:bg-bad/[0.06]">Delete user</button>
            <div id="user-delete-confirm" class="hidden rounded-[10px] border border-bad/25 bg-bad/[0.04] p-3">
                <p class="mb-3 text-[13px] leading-relaxed text-zinc-400">Permanently delete {{ $u->name }} and everything they own. This cannot be undone.</p>
                <form method="POST" action="{{ route('admin.users.destroy', $u) }}">
                    @csrf
                    @method('DELETE')
                    <button class="w-full rounded-[9px] bg-bad py-[10px] text-sm font-semibold text-white transition hover:bg-bad/90">Permanently delete</button>
                </form>
            </div>
        </div>
    @endif
</aside>
