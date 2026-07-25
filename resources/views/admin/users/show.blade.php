@php
    use App\Models\User;
    use App\Services\Credit\CreditService;
    use Illuminate\Support\Str;

    $fmt = CreditService::formatMicro(...);
    $firstName = Str::before($u->name, ' ') ?: $u->name;
    $invited = $u->status === User::STATUS_INVITED;
    $inactive = $u->isSuspended();

    [$dotClass, $statusLabel] = match ($u->status) {
        User::STATUS_ACTIVE => ['bg-ok', 'Active'],
        User::STATUS_SUSPENDED => ['bg-warn', 'Inactive'],
        default => ['bg-zinc-500', 'Invited'],
    };
    $statusText = match ($u->status) {
        User::STATUS_ACTIVE => 'text-ok',
        User::STATUS_SUSPENDED => 'text-warn',
        default => 'text-zinc-400',
    };

    $chips = ['all' => 'All', 'grants' => 'Grants', 'usage' => 'Usage', 'account' => 'Account events'];

    // Timeline dot per event lane: money in, money out, guarded changes, the rest.
    $dots = ['grant' => 'bg-ok', 'usage' => 'bg-accent', 'change' => 'bg-warn', 'account' => 'bg-zinc-600'];

    $cardLabel = 'mb-3 text-xs font-bold tracking-[1.2px] uppercase';
    $railBtn = 'rounded-[8px] border border-white/14 px-3 py-1.5 text-[12.5px] text-zinc-300 transition hover:bg-white/[0.04]';

    // Explanatory disabled states (design 2B): the switch stays visible but
    // locked, with the reason spelled out under it.
    $statusLocked = $isSelf || ($lastActiveSuperAdmin && ! $inactive);
    $roleLocked = $isSelf || ($lastSuperAdmin && $u->isSuperAdmin());
@endphp

<x-layout :title="$u->name" :heading="false" contentWidth="max-w-[1140px]">
    <div class="mb-4 text-[13px]">
        <a href="{{ route('admin.users.index') }}" class="text-accent transition hover:text-accent/80">← Users</a>
    </div>

    @include('admin.users._reveals')

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        {{-- ============ The statement: identity, balance, one filterable timeline ============ --}}
        <div class="min-w-0">
            <div class="mb-1.5 flex flex-wrap items-baseline gap-x-3.5 gap-y-1">
                <h1 class="text-[26px] font-bold tracking-[-0.4px] text-zinc-100">{{ $u->name }}</h1>
                <span class="text-[13.5px] text-zinc-500">{{ $u->email }}</span>
                <span class="inline-flex items-center gap-1.5 text-xs {{ $statusText }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $dotClass }}"></span>{{ $statusLabel }}
                </span>
            </div>

            <div class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-1">
                <span class="font-mono text-[32px] font-bold {{ $u->hasLimitedCredit() && $u->credit_balance_micro <= 0 ? 'text-warn' : 'text-zinc-100' }}">
                    {{ $fmt($u->credit_balance_micro) }}
                </span>
                @if(($creditTotals['charged_micro'] ?? 0) > 0)
                    <span class="text-[13px] text-zinc-500">lifetime billed {{ $fmt($creditTotals['charged_micro']) }} · cost you {{ $fmt($creditTotals['actual_micro']) }}</span>
                @endif
            </div>

            <div class="mb-3.5 flex flex-wrap gap-2">
                @foreach($chips as $key => $label)
                    <a href="{{ route('admin.users.show', $key === 'all' ? $u : [$u, 'filter' => $key]) }}"
                       class="rounded-2xl px-[13px] py-1.5 text-[12.5px] transition {{ $filter === $key
                            ? 'bg-accent font-semibold text-accent-on'
                            : 'border border-white/12 text-zinc-500 hover:border-white/25 hover:text-zinc-300' }}">{{ $label }}</a>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-[12px] border border-white/8">
                @forelse($events as $ev)
                    <div class="flex items-center gap-3.5 border-b border-white/[0.06] px-[18px] py-[13px] last:border-b-0">
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $dots[$ev['kind']] ?? 'bg-zinc-600' }}"></span>
                        <span class="min-w-0 flex-1 truncate text-[13.5px] text-zinc-200">
                            {{ $ev['title'] }}@if($ev['detail'])<span class="text-zinc-500"> · {{ $ev['detail'] }}</span>@endif
                        </span>
                        @if($ev['amount_micro'] === null)
                            <span class="shrink-0 text-[13px] text-zinc-600">—</span>
                        @else
                            <span class="shrink-0 font-mono text-[13px] {{ $ev['amount_micro'] > 0 ? 'text-ok' : 'text-zinc-300' }}">{{ $ev['amount_micro'] > 0 ? '+' : '' }}{{ $fmt($ev['amount_micro']) }}</span>
                        @endif
                        <span class="w-[76px] shrink-0 text-right text-[12.5px] text-zinc-500">
                            {{ $ev['at']->format($ev['at']->year === now()->year ? 'M j' : 'M j, Y') }}
                        </span>
                    </div>
                @empty
                    <div class="px-[18px] py-8 text-center text-[13px] text-zinc-500">Nothing here yet.</div>
                @endforelse
            </div>

            @if($total > $limit)
                <div class="mt-2.5">
                    <a href="{{ route('admin.users.show', array_filter(['user' => $u->id, 'filter' => $filter === 'all' ? null : $filter, 'limit' => $limit + 30])) }}"
                       class="text-[12.5px] text-accent hover:underline">View older →</a>
                </div>
            @elseif($events->isNotEmpty())
                <div class="mt-2.5 text-[12.5px] text-zinc-500">That's everything — {{ $events->count() }} {{ Str::plural('event', $events->count()) }}.</div>
            @endif
        </div>

        {{-- ============ The rail: grant credit, account switches, danger zone ============ --}}
        <div class="flex flex-col gap-3.5">
            {{-- Grant credit --}}
            <div class="rounded-[14px] border border-accent/25 bg-panel p-5">
                <div class="{{ $cardLabel }} text-accent">Grant credit</div>
                <form method="POST" action="{{ route('admin.users.credit', $u) }}">
                    @csrf
                    <div class="mb-2 flex gap-2">
                        <input name="amount" type="number" step="0.01" min="-10000" max="10000" required placeholder="5.00"
                               class="min-w-0 flex-1 rounded-[8px] border border-edge bg-inset px-3 py-[9px] text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none">
                        <button class="shrink-0 self-center rounded-[8px] bg-accent px-4 py-[9px] text-[13px] font-semibold text-accent-on transition hover:bg-accent/90">Add</button>
                    </div>
                    <input name="note" type="text" maxlength="255" placeholder="note (optional)"
                           class="w-full rounded-[8px] border border-edge bg-inset px-3 py-2 text-[13px] text-zinc-200 placeholder:text-zinc-600 focus:border-accent/50 focus:outline-none">
                </form>
                <div class="mt-2 text-[11px] leading-snug text-zinc-600">Negative amounts adjust down. Granting to an unlimited account starts metering it.</div>
                @if($u->hasLimitedCredit())
                    <div class="mt-3 flex items-center justify-between border-t border-white/8 pt-3">
                        <span class="text-xs text-zinc-600">or</span>
                        <form method="POST" action="{{ route('admin.users.credit.unlimited', $u) }}"
                              data-confirm="{{ $firstName }}'s balance is cleared and generation stops being metered. Granting later starts metering again."
                              data-confirm-title="Make {{ $u->name }} unlimited?"
                              data-confirm-label="Make unlimited"
                              data-confirm-tone="accent">
                            @csrf
                            @method('DELETE')
                            <button class="{{ $railBtn }}">Make unlimited</button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Account: status + role, both confirm before applying --}}
            <div class="rounded-[14px] border border-white/8 bg-panel p-5">
                <div class="{{ $cardLabel }} text-accent">Account</div>

                <div class="mb-[7px] text-[12.5px] text-zinc-500">Status</div>
                <form method="POST" action="{{ route('admin.users.suspend', $u) }}"
                      @unless($statusLocked)
                      data-confirm="{{ $inactive
                          ? $firstName.' will be able to sign in again and their API keys start working.'
                          : $firstName." won't be able to sign in and their API keys stop working. Balance and history keep." }}"
                      data-confirm-title="{{ $inactive ? 'Reactivate' : 'Deactivate' }} {{ $u->name }}?"
                      data-confirm-label="{{ $inactive ? 'Reactivate' : 'Deactivate' }}"
                      data-confirm-tone="{{ $inactive ? 'accent' : 'warn' }}"
                      data-confirm-from="{{ $inactive ? 'Inactive' : 'Active' }}"
                      data-confirm-to="{{ $inactive ? 'Active' : 'Inactive' }}"
                      @endunless>
                    @csrf
                    <div class="mb-1.5 flex gap-1.5 rounded-[10px] border border-white/8 bg-inset p-1">
                        <button type="{{ $inactive ? 'submit' : 'button' }}" @disabled($statusLocked)
                                class="flex-1 rounded-[7px] py-2 text-center text-[13px] transition {{ ! $inactive
                                    ? 'bg-ok font-semibold text-accent-on'
                                    : 'text-zinc-500 hover:text-zinc-300' }} {{ $statusLocked ? 'cursor-not-allowed opacity-60' : '' }}">Active</button>
                        <button type="{{ $inactive ? 'button' : 'submit' }}" @disabled($statusLocked)
                                class="flex-1 rounded-[7px] py-2 text-center text-[13px] transition {{ $inactive
                                    ? 'bg-warn font-semibold text-accent-on'
                                    : 'text-zinc-500 hover:text-zinc-300' }} {{ $statusLocked ? 'cursor-not-allowed opacity-60' : '' }}">Inactive</button>
                    </div>
                </form>
                <div class="mb-3.5 text-xs leading-normal text-zinc-600">
                    Inactive users can't sign in and their API keys stop working. Balance and history keep. Reversible.
                    @if($isSelf)
                        <span class="text-zinc-400">You can't deactivate your own account.</span>
                    @elseif($lastActiveSuperAdmin && ! $inactive)
                        <span class="text-warn">The last active SuperAdmin can't be deactivated.</span>
                    @endif
                </div>

                <div class="mb-[7px] text-[12.5px] text-zinc-500">Role</div>
                <form method="POST" action="{{ route('admin.users.role', $u) }}"
                      @unless($roleLocked)
                      data-confirm="{{ $u->isSuperAdmin()
                          ? $firstName.' loses the Users screen, server settings, and health checks. Their projects and voices keep.'
                          : 'SuperAdmins can manage every user on this server — grant credit, change roles, deactivate accounts, and read server settings.' }}"
                      data-confirm-title="Make {{ $u->name }} a {{ $u->isSuperAdmin() ? 'User' : 'SuperAdmin' }}?"
                      data-confirm-label="Make {{ $u->isSuperAdmin() ? 'User' : 'SuperAdmin' }}"
                      data-confirm-tone="accent"
                      data-confirm-from="{{ $u->roleLabel() }}"
                      data-confirm-to="{{ $u->isSuperAdmin() ? 'User' : 'SuperAdmin' }}"
                      @endunless>
                    @csrf
                    @method('PATCH')
                    {{-- The one possible transition rides a hidden field — the confirm
                         guard's requestSubmit() re-fire must not depend on which
                         button was the submitter. --}}
                    <input type="hidden" name="role" value="{{ $u->isSuperAdmin() ? 'User' : 'SuperAdmin' }}">
                    <div class="mb-1.5 flex gap-1.5 rounded-[10px] border border-white/8 bg-inset p-1">
                        <button type="{{ $u->isSuperAdmin() ? 'submit' : 'button' }}" @disabled($roleLocked)
                                class="flex-1 rounded-[7px] py-2 text-center text-[13px] transition {{ ! $u->isSuperAdmin()
                                    ? 'bg-accent font-semibold text-accent-on'
                                    : 'text-zinc-500 hover:text-zinc-300' }} {{ $roleLocked ? 'cursor-not-allowed opacity-60' : '' }}">User</button>
                        <button type="{{ $u->isSuperAdmin() ? 'button' : 'submit' }}" @disabled($roleLocked)
                                class="flex-1 rounded-[7px] py-2 text-center text-[13px] transition {{ $u->isSuperAdmin()
                                    ? 'bg-accent font-semibold text-accent-on'
                                    : 'text-zinc-500 hover:text-zinc-300' }} {{ $roleLocked ? 'cursor-not-allowed opacity-60' : '' }}">SuperAdmin</button>
                    </div>
                </form>
                <div class="text-xs leading-normal text-zinc-600">
                    Both switches confirm before applying.
                    @if($isSelf)
                        <span class="text-zinc-400">Use another SuperAdmin account to change your own role.</span>
                    @elseif($lastSuperAdmin && $u->isSuperAdmin())
                        <span class="text-warn">The last SuperAdmin can't be demoted.</span>
                    @endif
                </div>

                @if($isSelf)
                    <div class="mt-3.5 rounded-[10px] border border-white/8 bg-inset px-3 py-2.5 text-xs leading-normal text-zinc-500">
                        This is your account. Change your password or delete it from <a href="{{ route('admin.account.index') }}" class="text-accent hover:underline">Account</a>.
                    </div>
                @elseif($u->status === User::STATUS_ACTIVE)
                    <div class="mt-3.5 border-t border-white/8 pt-3.5">
                        <form method="POST" action="{{ route('admin.users.impersonate', $u) }}">
                            @csrf
                            <button class="w-full rounded-[8px] border border-white/14 py-2 text-[12.5px] text-zinc-300 transition hover:bg-white/[0.04]">Sign in as {{ $firstName }}</button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Danger zone: only the irreversible or disruptive --}}
            @unless($isSelf)
                <div class="rounded-[14px] border border-warn/25 bg-panel px-5 py-4">
                    <div class="mb-2.5 text-xs font-bold tracking-[1.2px] text-warn uppercase">Danger zone</div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.users.force-reset', $u) }}"
                              data-confirm="{{ $invited
                                  ? 'This re-issues '.$firstName."'s invite link — the old one stops working."
                                  : 'This immediately invalidates '.$firstName."'s current password. You'll get a fresh set-password link to share." }}"
                              data-confirm-title="{{ $invited ? 'Resend invite link?' : 'Reset '.$firstName."'s password?" }}"
                              data-confirm-label="{{ $invited ? 'Resend link' : 'Reset password' }}"
                              data-confirm-tone="warn">
                            @csrf
                            <button class="{{ $railBtn }}">{{ $invited ? 'Resend invite link…' : 'Force password reset…' }}</button>
                        </form>
                        @if($lastSuperAdmin)
                            <button disabled title="The last SuperAdmin can't be deleted."
                                    class="cursor-not-allowed rounded-[8px] border border-bad/20 px-3 py-1.5 text-[12.5px] text-bad/50">Delete user…</button>
                        @else
                            <form method="POST" action="{{ route('admin.users.destroy', $u) }}"
                                  data-delete-user="{{ $u->name }}" data-busy data-busy-label="Deleting…">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-[8px] border border-bad/35 px-3 py-1.5 text-[12.5px] text-bad transition hover:bg-bad/[0.06]">Delete user…</button>
                            </form>
                        @endif
                    </div>
                    <div class="mt-2.5 text-xs leading-relaxed text-zinc-600">Only irreversible or disruptive acts live here — deactivation is up in Status.</div>
                </div>
            @endunless
        </div>
    </div>
</x-layout>
