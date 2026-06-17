<x-layout title="API Keys" description="Keys authenticate requests via the xi-api-key header.">
    @if(session('new_key'))
        <div class="mb-4 rounded-lg border border-cyan-500/30 bg-cyan-500/10 p-4">
            <div class="text-sm font-medium text-cyan-300">New key created — copy it now:</div>
            <div class="mt-2 flex items-center gap-2">
                <code class="flex-1 truncate rounded-lg border border-cyan-500/30 bg-zinc-950 px-3 py-2 font-mono text-sm text-cyan-200">{{ session('new_key') }}</code>
                <button data-copy="{{ session('new_key') }}" class="rounded-lg border border-cyan-500/40 px-3 py-2 text-sm text-cyan-200 hover:bg-cyan-500/10">Copy</button>
            </div>
        </div>
    @endif

    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.api-keys.create') }}" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">New key</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-900/60 text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Key</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Rate limit</th>
                    <th class="px-4 py-3">Used</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
                @forelse($apiKeys as $key)
                    <tr class="hover:bg-zinc-900/40">
                        <td class="px-4 py-3 font-medium text-zinc-200">{{ $key->name }}</td>
                        <td class="px-4 py-3">
                            <button data-copy="{{ $key->key }}" class="font-mono text-xs text-zinc-400 hover:text-cyan-400" title="Click to copy">{{ substr($key->key, 0, 12) }}…</button>
                        </td>
                        <td class="px-4 py-3">
                            @if($key->is_active)
                                <span class="rounded-md bg-emerald-500/10 px-2 py-1 text-xs text-emerald-400">Active</span>
                            @else
                                <span class="rounded-md bg-zinc-700/40 px-2 py-1 text-xs text-zinc-400">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-400">{{ $key->rate_limit ? $key->rate_limit.'/hr' : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-400">{{ $key->speeches_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1.5">
                                <form method="POST" action="{{ route('admin.api-keys.toggle', $key) }}">@csrf
                                    <button class="rounded-md border border-zinc-700 px-2.5 py-1 text-xs hover:bg-zinc-800">{{ $key->is_active ? 'Disable' : 'Enable' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.api-keys.destroy', $key) }}" onsubmit="return confirm('Delete this API key?')">@csrf @method('DELETE')
                                    <button class="rounded-md border border-red-500/30 px-2.5 py-1 text-xs text-red-400 hover:bg-red-500/10">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">No API keys yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
