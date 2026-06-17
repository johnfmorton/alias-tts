<x-layout title="New API key">
    <form method="POST" action="{{ route('admin.api-keys.store') }}" class="max-w-md space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf
        <div>
            <label for="name" class="mb-1.5 block text-sm font-medium">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. Bespoken production"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        </div>
        <div>
            <label for="rate_limit" class="mb-1.5 block text-sm font-medium">Rate limit <span class="text-zinc-500">(requests/hour, optional)</span></label>
            <input id="rate_limit" name="rate_limit" type="number" min="1" value="{{ old('rate_limit') }}" placeholder="unlimited"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30">
        </div>
        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Create key</button>
            <a href="{{ route('admin.api-keys.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
