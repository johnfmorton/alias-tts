@props([
    'user' => null,
    'name' => null,
    'initials' => null,
    'url' => null,
    'size' => 32,
    'accent' => true,
])
@php
    use Illuminate\Support\Str;

    $name = $name ?? $user?->name;
    $initials = $initials ?? ($user
        ? $user->initials()
        : Str::upper(Str::substr((string) ($name ?? '?'), 0, 2)));
    $url = $url ?? $user?->avatarUrl();
    $px = (int) $size;
    // Initials track the circle size (12px at 32, 22px at 64 in the spec).
    $fs = max(10, (int) round($px * 0.36));
    $ink = $accent ? 'text-accent' : 'text-zinc-200';
@endphp
@if($url)
    <img src="{{ $url }}" alt="{{ $name }}"
         style="width:{{ $px }}px;height:{{ $px }}px"
         {{ $attributes->merge(['class' => 'shrink-0 rounded-full object-cover']) }}>
@else
    <span style="width:{{ $px }}px;height:{{ $px }}px;font-size:{{ $fs }}px"
          {{ $attributes->merge(['class' => "grid shrink-0 place-items-center rounded-full bg-zinc-800 font-bold {$ink}"]) }}>{{ $initials }}</span>
@endif
