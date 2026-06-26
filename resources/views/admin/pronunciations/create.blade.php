<x-layout title="Add pronunciation">
    <form method="POST" action="{{ route('admin.pronunciations.store') }}" class="max-w-lg space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
        @csrf
        @include('admin.pronunciations._fields', ['entry' => null])
        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-medium text-zinc-950 hover:bg-cyan-400">Add pronunciation</button>
            <a href="{{ route('admin.pronunciations.index') }}" class="text-sm text-zinc-400 hover:text-zinc-200">Cancel</a>
        </div>
    </form>
</x-layout>
