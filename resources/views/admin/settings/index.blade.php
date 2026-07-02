<x-layout title="Settings" description="Your settings — they apply only to your account and your API keys. Values pinned in the server's .env file are instance-wide and shown read-only.">
    <form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-2xl space-y-6">
        @csrf
        @method('PUT')

        @foreach($groups as $groupName => $fields)
            @php
                $primary = array_values(array_filter($fields, fn ($f) => empty($f['advanced'])));
                $advanced = array_values(array_filter($fields, fn ($f) => ! empty($f['advanced'])));
            @endphp

            <section class="space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-300">
                    {{ ['asr' => 'ASR transcript QA', 'projects' => 'API projects'][$groupName] ?? ucfirst($groupName) }}
                </h2>

                @foreach($primary as $f)
                    @include('admin.settings._field', ['f' => $f])
                @endforeach

                @if(count($advanced))
                    <details class="rounded-lg border border-zinc-800 bg-zinc-900/40 p-4">
                        <summary class="cursor-pointer text-sm text-zinc-400 hover:text-zinc-200">Advanced — detection thresholds</summary>
                        <div class="mt-4 space-y-5">
                            @foreach($advanced as $f)
                                @include('admin.settings._field', ['f' => $f])
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endforeach

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Save settings</button>
            <a href="{{ route('admin.dashboard') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
