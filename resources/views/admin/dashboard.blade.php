<x-layout title="Dashboard" description="Overview and connection details for any ElevenLabs-compatible app.">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        @foreach(['Voices' => $stats['voices'], 'API keys' => $stats['apiKeys'], 'Generations' => $stats['speeches']] as $label => $value)
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5">
                <div class="text-sm text-zinc-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-8 rounded-xl border border-zinc-800 bg-zinc-900/50">
        <div class="border-b border-zinc-800 px-5 py-4">
            <h2 class="font-semibold">Connect your app</h2>
            <p class="mt-1 text-sm text-zinc-400">Alias speaks the ElevenLabs v1 API, so any ElevenLabs-compatible app works — including the Bespoken Craft CMS plugin. Paste these into its API settings.</p>
        </div>
        <div class="space-y-5 p-5">
            <div>
                <div class="mb-1.5 text-sm font-medium">Base URL</div>
                <div class="flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 font-mono text-sm text-zinc-300">{{ $connect['baseUrl'] }}</code>
                    <button data-copy="{{ $connect['baseUrl'] }}" class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Copy</button>
                </div>
            </div>

            <div>
                <div class="mb-1.5 text-sm font-medium">API key <span class="font-mono text-xs text-zinc-500">(xi-api-key)</span></div>
                @if($connect['apiKey'])
                    <div class="flex items-center gap-2">
                        <code class="flex-1 truncate rounded-lg border border-zinc-800 bg-zinc-950 px-3 py-2 font-mono text-sm text-zinc-300">{{ $connect['apiKey'] }}</code>
                        <button data-copy="{{ $connect['apiKey'] }}" class="rounded-lg border border-zinc-700 px-3 py-2 text-sm hover:bg-zinc-800">Copy</button>
                        <form method="POST" action="{{ route('admin.dashboard.reset-key') }}"
                              onsubmit="return confirm('Reset this API key? The current value stops working immediately — you will need to update your app with the new key.')">
                            @csrf
                            <button class="rounded-lg border border-red-500/30 px-3 py-2 text-sm text-red-400 hover:bg-red-500/10" title="Issue a new key if this one leaked">Reset</button>
                        </form>
                    </div>
                    <p class="mt-1.5 text-xs text-zinc-500">Leaked? <span class="text-zinc-400">Reset</span> issues a new key and immediately revokes this one.</p>
                @else
                    <p class="text-sm text-zinc-500">No active key yet — <a class="text-cyan-400 hover:underline" href="{{ route('admin.api-keys.create') }}">create one</a>.</p>
                @endif
            </div>

            <div>
                <div class="mb-1.5 text-sm font-medium">Voice IDs</div>
                @if(count($connect['voiceIds']))
                    <div class="flex flex-wrap gap-2">
                        @foreach($connect['voiceIds'] as $vid)
                            <button data-copy="{{ $vid }}" class="rounded-lg border border-zinc-700 px-3 py-1.5 font-mono text-sm hover:bg-zinc-800">{{ $vid }}</button>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-zinc-500">No voices yet — <a class="text-cyan-400 hover:underline" href="{{ route('admin.voices.create') }}">add one</a>.</p>
                @endif
            </div>
        </div>
    </div>
</x-layout>
