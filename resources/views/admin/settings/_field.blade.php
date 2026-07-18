@php
    $locked = ! empty($f['locked']);
    $name = $f['field'];
    $value = old($name, $f['value']);
    $disabled = $locked ? 'disabled' : '';
    $input = 'w-full rounded-lg border border-edge bg-zinc-900 px-3 py-2 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/30 disabled:cursor-not-allowed disabled:opacity-60';
@endphp
<div>
    <div class="flex items-center justify-between gap-3">
        <label for="{{ $name }}" class="text-sm font-medium text-zinc-200">{{ $f['label'] }}</label>
        @if($locked)
            <span class="rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300"
                  title="Pinned by the {{ $f['env'] }} environment variable">Set in .env</span>
        @elseif(! empty($f['advanced']) && array_key_exists('default', $f))
            {{-- A bad Advanced value can wreck generation, so every threshold can be
                 put back to its shipped default. Stages the change like any edit;
                 the user still clicks Save to persist it. --}}
            <button type="button" data-restore-default="{{ $name }}" data-default="{{ $f['default'] }}"
                    title="Restore the shipped default ({{ $f['default'] }})"
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
