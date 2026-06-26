<x-layout title="Edit pronunciation">
    <form method="POST" action="{{ route('admin.pronunciations.update', $entry) }}" class="max-w-lg space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf @method('PUT')
        @include('admin.pronunciations._fields', ['entry' => $entry])
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="approved" value="1" @checked(old('approved', $entry->approved))
                   class="rounded border-zinc-700 bg-zinc-900 text-cyan-500 focus:ring-2 focus:ring-cyan-500/30">
            Approved — applied to your text
        </label>
        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Save changes</button>
            <a href="{{ route('admin.pronunciations.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
