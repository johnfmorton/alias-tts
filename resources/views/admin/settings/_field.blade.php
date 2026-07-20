@php
    $locked = ! empty($f['locked']);
    $name = $f['field'];
    $value = old($name, $f['value']);
    $disabled = $locked ? 'disabled' : '';
    $input = 'w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30 disabled:cursor-not-allowed disabled:opacity-60';

    // Does this field's current value deviate from its shipped default? Compared
    // per type so "80" vs 80 and 1.20 vs 1.2 don't read as changes. A locked
    // (.env) field is never flagged — its value is instance policy, not a choice.
    $hasDefault = array_key_exists('default', $f);
    $default = $hasDefault ? $f['default'] : null;
    $isCustom = ! $locked && $hasDefault && match ($f['type']) {
        'bool' => (bool) $value !== (bool) $default,
        'int' => (int) $value !== (int) $default,
        'float' => abs((float) $value - (float) $default) > 1e-9,
        default => (string) $value !== (string) $default,
    };
@endphp
<div>
    <div class="flex items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
            <label for="{{ $name }}" class="text-sm font-medium text-zinc-200">{{ $f['label'] }}</label>
            @if($isCustom)
                {{-- Value differs from the shipped default — flag it so a customised
                     field is obvious at a glance, paired with Reset to default below. --}}
                <span class="shrink-0 rounded-md border border-cyan-500/30 bg-cyan-500/10 px-2 py-0.5 text-xs text-cyan-300"
                      title="You've changed this from its default — use Reset to default to restore it.">Modified</span>
            @endif
        </div>
        @if($locked)
            <span class="shrink-0 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300"
                  title="Pinned by the {{ $f['env'] }} environment variable">Set in .env</span>
        @elseif($isCustom && $f['type'] !== 'bool')
            {{-- Any changed field can be put back to its shipped default. Stages the
                 change like any edit; the user still clicks Save to persist it.
                 Booleans are omitted — the checkbox itself is the one-click reset. --}}
            <button type="button" data-restore-default="{{ $name }}" data-default="{{ $default }}"
                    title="Restore the shipped default ({{ $f['option_labels'][$default] ?? $default }})"
                    class="shrink-0 rounded-md border border-edge px-2 py-0.5 text-xs text-zinc-400 transition hover:border-zinc-500 hover:text-zinc-200">
                Reset to default
            </button>
        @endif
    </div>

    @if(! empty($f['help']))
        <p class="mt-1 text-xs text-zinc-500">{{ $f['help'] }}</p>
    @endif

    <div class="mt-2">
        @switch($f['type'])
            @case('bool')
                <label class="flex items-center gap-2 text-sm text-zinc-400">
                    <input type="checkbox" id="{{ $name }}" name="{{ $name }}" value="1" @checked($value) {{ $disabled }}
                           class="rounded border-edge bg-zinc-900 text-cyan-500 focus:ring-cyan-500/30 disabled:opacity-60">
                    On
                </label>
                @break

            @case('enum')
                <select id="{{ $name }}" name="{{ $name }}" {{ $disabled }} class="{{ $input }}">
                    @foreach($f['options'] as $opt)
                        <option value="{{ $opt }}" @selected((string) $value === $opt)>{{ $f['option_labels'][$opt] ?? $opt }}</option>
                    @endforeach
                </select>
                @break

            @default
                <input id="{{ $name }}" name="{{ $name }}" type="number"
                       @if($f['type'] === 'float') step="0.01" @endif
                       @isset($f['min']) min="{{ $f['min'] }}" @endisset
                       @isset($f['max']) max="{{ $f['max'] }}" @endisset
                       value="{{ $value }}" {{ $disabled }} class="{{ $input }}">
        @endswitch

        @if($locked)
            <p class="mt-1 text-xs text-zinc-600">Managed by <code>{{ $f['env'] }}</code> in <code>.env</code> — edit it there.</p>
        @endif
    </div>
</div>
