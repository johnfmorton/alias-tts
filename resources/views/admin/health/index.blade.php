<x-layout title="Health" description="Confirms the service is wired up correctly — PHP, database, ffmpeg, storage, provider, queue, and scheduler.">
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
            <a href="{{ route('admin.health') }}" data-health-run data-loading-label="Running…" class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Run checks</a>
            <a href="{{ route('admin.health', ['deep' => 1]) }}" data-health-run data-loading-label="Running live checks…" class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">Run live checks</a>
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

    {{-- Live provider test --}}
    <div class="mb-6 rounded-xl border border-zinc-800 bg-zinc-900/50">
        <div class="border-b border-zinc-800 px-5 py-4">
            <h2 class="font-semibold">Live provider test</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Generates real audio through the provider. <span class="text-zinc-300">Short</span> tests the synchronous
                path; <span class="text-zinc-300">long</span> tests async chunking, the queue worker, and concatenation.
                Each run makes a real, billable provider call.
            </p>
        </div>
        <div class="space-y-4 p-5">
            @if($voices->isEmpty())
                <p class="text-sm text-zinc-500">No voices configured — <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">add one</a> to run a test.</p>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <label for="health-test-voice" class="text-sm text-zinc-400">Voice</label>
                    <select id="health-test-voice" class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm">
                        @foreach($voices as $v)
                            <option value="{{ $v->slug }}">{{ $v->name }}</option>
                        @endforeach
                    </select>
                    <button type="button" data-test-short
                            data-url="{{ route('admin.health.test.short') }}"
                            data-voice-select="#health-test-voice"
                            data-audio-target="#health-test-audio"
                            data-status-target="#health-test-status"
                            class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Test short text</button>
                    <button type="button" data-test-long
                            data-url="{{ route('admin.health.test.long') }}"
                            data-voice-select="#health-test-voice"
                            data-audio-target="#health-test-audio"
                            data-status-target="#health-test-status"
                            class="rounded-lg border border-cyan-700/50 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-300 hover:bg-cyan-500/20">Test long text (async)</button>
                </div>
                <div id="health-test-status" class="text-sm text-zinc-400" role="status" aria-live="polite"></div>
                <audio id="health-test-audio" controls class="hidden w-full"></audio>
            @endif
        </div>
    </div>

    {{-- Per-check results --}}
    <div data-health-results class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-900/50 transition-opacity">
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
</x-layout>
