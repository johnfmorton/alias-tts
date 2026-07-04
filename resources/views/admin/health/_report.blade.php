{{--
    The diagnostic report body: summary + per-check results. Rendered on its own
    (no layout) by HealthController::results and fetched async by the health page,
    so the ~21 checks (ffmpeg, storage, sidecar pings, deep provider/queue probes)
    run AFTER the page has painted. The Run / Run-live buttons re-fetch this same
    partial (see initHealthReport in app.js) rather than reloading the page.
--}}
@php
    $styles = [
        'PASS' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'WARN' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'FAIL' => 'border-red-500/30 bg-red-500/10 text-red-300',
    ];
    $overall = $summary['fail'] > 0 ? 'FAIL' : ($summary['warn'] > 0 ? 'WARN' : 'PASS');
    $overallLabel = $summary['fail'] > 0
        ? 'Action needed'
        : ($summary['warn'] > 0 ? 'OK, with warnings' : 'All systems go');
@endphp

{{-- Summary + actions --}}
<div class="mb-6 flex flex-col gap-4 rounded-xl border border-zinc-800 bg-zinc-900/50 p-5 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
        <span class="inline-flex rounded-md border px-2.5 py-1 text-xs font-semibold uppercase tracking-wide {{ $styles[$overall] }}">{{ $overall }}</span>
        <div>
            <div class="font-semibold">{{ $overallLabel }}</div>
            <div class="text-sm text-zinc-500">
                {{ $summary['pass'] }} pass · {{ $summary['warn'] }} warn · {{ $summary['fail'] }} fail
                · checked {{ now()->format('M j, H:i:s') }}
            </div>
        </div>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <button type="button" data-health-run data-deep="0" class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Run checks</button>
        <button type="button" data-health-run data-deep="1" class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">Run live checks</button>
    </div>
</div>

@if($deep)
    <p class="mb-4 rounded-lg border border-zinc-800 bg-zinc-900/40 px-4 py-3 text-sm text-zinc-400">
        Live mode: validated the provider token against Replicate and dispatched a probe job to confirm a queue worker is running.
    </p>
@endif

@if($asrAutoEnabled ?? false)
    <p class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
        The Whisper sidecar is reachable, so Transcript QA was turned on automatically. Adjust it any time in <a href="{{ route('admin.settings.index') }}" class="underline">Settings</a>.
    </p>
@endif

{{-- Per-check results --}}
<div class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/50">
    <ul class="divide-y divide-zinc-800">
        @foreach($results as $result)
            <li class="flex items-start gap-4 px-5 py-4">
                <span class="mt-0.5 inline-flex w-14 shrink-0 justify-center rounded-md border px-2 py-1 text-xs font-semibold {{ $styles[$result->status->value] }}">{{ $result->status->value }}</span>
                <div class="min-w-0">
                    <div class="font-medium">{{ $result->label }}</div>
                    <div class="mt-0.5 break-words text-sm text-zinc-400">{{ $result->detail }}</div>
                    @if($result->helpUrl)
                        <a href="{{ $result->helpUrl }}" target="_blank" rel="noopener noreferrer"
                           class="mt-1 inline-block text-sm text-cyan-400 hover:underline">Setup guide ↗</a>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
