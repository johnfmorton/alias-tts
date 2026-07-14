{{-- SuperAdmin-only owner filter (Studio projects, Voices). A plain GET form
     (?owner=) so pagination — withQueryString — keeps the filter. The list
     lands on the signed-in admin's own scope by default; "All owners" widens
     explicitly (?owner=all). Extra hidden fields (e.g. Studio's tab) go in
     the slot. --}}
@props(['action', 'owners', 'ownerId'])

<form method="GET" action="{{ $action }}" class="flex items-center gap-2">
    {{ $slot }}
    <label for="owner-filter" class="text-xs text-zinc-400">Owner</label>
    <select id="owner-filter" name="owner" onchange="this.form.requestSubmit()"
            class="rounded-[8px] border border-white/12 bg-inset px-2.5 py-1.5 text-sm text-zinc-200 focus:border-accent/50 focus:outline-none">
        <option value="{{ auth()->id() }}" @selected($ownerId === auth()->id())>{{ auth()->user()->name }} (you)</option>
        <option value="all" @selected($ownerId === null)>All owners</option>
        @foreach($owners as $ownerOpt)
            <option value="{{ $ownerOpt->id }}" @selected($ownerId === $ownerOpt->id)>{{ $ownerOpt->name }}</option>
        @endforeach
    </select>
</form>
