{{-- Dismissable "Getting Started" intro message — one per major page, each
     backed by its own managed per-user bool (App\Support\GettingStarted maps
     page => key). Renders nothing once the user hides it; the Account page's
     "Restore Getting Started messages" button brings every dismissed message
     back. Dismiss is a real form so it works without JS; app.js intercepts it
     to hide the panel in place (and, on the Dashboard, reveal the header's
     restore link). When the page's key is pinned in .env the hide control
     disappears and the message is always on (or always off). --}}
@props(['page', 'title'])

@php
    $gsKey = \App\Support\GettingStarted::PAGES[$page];
    $gsShow = (bool) config($gsKey);
    $gsLocked = app(\App\Services\Settings\SettingsManager::class)->isLocked($gsKey);
@endphp

@if($gsShow)
    <section id="getting-started" aria-labelledby="getting-started-title"
             data-dismiss-url="{{ route('admin.dashboard.getting-started') }}" data-page="{{ $page }}"
             {{ $attributes->merge(['class' => 'mb-8 rounded-[14px] border border-accent/30 bg-accent/5 px-7 py-6']) }}>
        <h2 id="getting-started-title" class="text-[19px] font-bold text-zinc-100">{{ $title }}</h2>

        {{ $slot }}

        @unless($gsLocked)
            <form data-getting-started-dismiss method="POST" action="{{ route('admin.dashboard.getting-started') }}"
                  class="mt-5 border-t border-accent/15 pt-4 text-right">
                @csrf
                <input type="hidden" name="page" value="{{ $page }}">
                <input type="hidden" name="show" value="0">
                <button class="text-[12.5px] text-zinc-500 transition hover:text-zinc-300">Hide this guide ✕</button>
            </form>
        @endunless
    </section>
@endif
