<x-layout title="Insights" :description="'How the instance is being used — active people, feature uptake, screens, and generation volume over the last '.$windowDays.' days. Feature and page-view data comes from the app\'s own first-party events; volume comes from the billing ledger.'">

    @php
        $fmt = fn (int $n): string => number_format($n);
        $maxDaily = max(1, max(array_column($daily, 'events')));
        $maxFeature = max(1, (int) ($features->max('events') ?? 0));
    @endphp

    {{-- Headline tiles --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['label' => 'Active · 7d', 'value' => $fmt($tiles['active_7']), 'title' => 'Users seen in the last 7 days (last_active_at)'],
            ['label' => 'Active · 30d', 'value' => $fmt($tiles['active_30']), 'title' => 'Users seen in the last 30 days'],
            ['label' => 'Users total', 'value' => $fmt($tiles['users_total']), 'title' => 'All accounts'],
            ['label' => 'Characters · 30d', 'value' => $fmt($tiles['characters_30']), 'title' => 'Billed characters, all engines (credit ledger)'],
            ['label' => 'Renders · 30d', 'value' => $fmt($tiles['calls_30']), 'title' => 'Billed generation calls (credit ledger)'],
            ['label' => 'Page views · 30d', 'value' => $fmt($tiles['page_views_30']), 'title' => 'Full-page admin screen views (first-party)'],
        ] as $tile)
            <div class="rounded-[14px] border border-white/8 bg-panel px-4 py-3.5" title="{{ $tile['title'] }}">
                <div class="text-[22px] font-bold tabular-nums tracking-[-0.01em] text-zinc-100">{{ $tile['value'] }}</div>
                <div class="mt-0.5 text-xs text-zinc-400">{{ $tile['label'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Daily activity trend --}}
    <section class="mt-6 rounded-[14px] border border-white/8 bg-panel p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-zinc-100">Daily events</h2>
            <span class="text-xs text-zinc-500">busiest day {{ $fmt($maxDaily) }} · hover a bar for the date</span>
        </div>
        <div class="mt-4 flex h-32 items-end gap-[2px]" role="img"
             aria-label="Daily event counts for the last {{ $windowDays }} days; busiest day {{ $fmt($maxDaily) }} events.">
            @foreach ($daily as $day)
                <div class="group flex h-full min-w-0 flex-1 items-end"
                     title="{{ $day['date']->format('D, M j') }} — {{ $fmt($day['events']) }} event{{ $day['events'] === 1 ? '' : 's' }}, {{ $fmt($day['users']) }} user{{ $day['users'] === 1 ? '' : 's' }}">
                    <div class="w-full rounded-t-[4px] {{ $day['events'] > 0 ? 'bg-accent/70 group-hover:bg-accent' : 'bg-white/8' }}"
                         style="height: {{ $day['events'] > 0 ? max(4, round($day['events'] / $maxDaily * 100)) : 2 }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1.5 flex justify-between text-[11px] text-zinc-500">
            <span>{{ $daily[0]['date']->format('M j') }}</span>
            <span>{{ $daily[intdiv($windowDays, 2)]['date']->format('M j') }}</span>
            <span>{{ end($daily)['date']->format('M j') }}</span>
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Feature adoption --}}
        <section class="rounded-[14px] border border-white/8 bg-panel p-5">
            <h2 class="text-sm font-semibold text-zinc-100">Feature use · {{ $windowDays }}d</h2>
            @if ($features->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No feature events yet — they'll appear as people use Studio, voices, and the API.</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">Event</th>
                            <th class="w-24 py-1.5 pr-3 text-right font-medium">Count</th>
                            <th class="w-20 py-1.5 pr-3 text-right font-medium">Users</th>
                            <th class="w-1/4 py-1.5 font-medium"><span class="sr-only">Share</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($features as $feature)
                            <tr>
                                <td class="py-2 pr-3 font-mono text-[12.5px] text-zinc-300">{{ $feature->name }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-zinc-100">{{ $fmt((int) $feature->events) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-zinc-400">{{ $fmt((int) $feature->users) }}</td>
                                <td class="py-2">
                                    <div class="h-2 rounded-[4px] bg-accent/70" style="width: {{ max(3, round($feature->events / $maxFeature * 100)) }}%"></div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- Top screens --}}
        <section class="rounded-[14px] border border-white/8 bg-panel p-5">
            <h2 class="text-sm font-semibold text-zinc-100">Top screens · {{ $windowDays }}d</h2>
            @if ($screens->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No page views yet — admin screens are counted server-side as people browse.</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">Route</th>
                            <th class="w-24 py-1.5 pr-3 text-right font-medium">Views</th>
                            <th class="w-20 py-1.5 text-right font-medium">Users</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($screens as $screen)
                            <tr>
                                <td class="py-2 pr-3 font-mono text-[12.5px] text-zinc-300">{{ $screen->route }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-zinc-100">{{ $fmt((int) $screen->views) }}</td>
                                <td class="py-2 text-right tabular-nums text-zinc-400">{{ $fmt((int) $screen->users) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- Volume by source --}}
        <section class="rounded-[14px] border border-white/8 bg-panel p-5">
            <h2 class="text-sm font-semibold text-zinc-100">Generation volume by source · {{ $windowDays }}d</h2>
            <p class="mt-1 text-xs text-zinc-500">From the billing ledger — Studio panel work vs API/plugin traffic.</p>
            @if ($volumeBySource->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No billed generation in the window.</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">Source</th>
                            <th class="w-32 py-1.5 pr-3 text-right font-medium">Characters</th>
                            <th class="w-24 py-1.5 text-right font-medium">Renders</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($volumeBySource as $row)
                            <tr>
                                <td class="py-2 pr-3 font-mono text-[12.5px] text-zinc-300">{{ $row->source ?: '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-zinc-100">{{ $fmt((int) $row->chars) }}</td>
                                <td class="py-2 text-right tabular-nums text-zinc-400">{{ $fmt((int) $row->calls) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- Volume by engine --}}
        <section class="rounded-[14px] border border-white/8 bg-panel p-5">
            <h2 class="text-sm font-semibold text-zinc-100">Generation volume by engine · {{ $windowDays }}d</h2>
            <p class="mt-1 text-xs text-zinc-500">Which speech engines the billed characters ran through.</p>
            @if ($volumeByModel->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No billed generation in the window.</p>
            @else
                <table class="mt-3 w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="py-1.5 pr-3 font-medium">Engine</th>
                            <th class="w-32 py-1.5 pr-3 text-right font-medium">Characters</th>
                            <th class="w-24 py-1.5 text-right font-medium">Renders</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @foreach ($volumeByModel as $row)
                            <tr>
                                <td class="py-2 pr-3 font-mono text-[12.5px] text-zinc-300">{{ $row->model ?: '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-zinc-100">{{ $fmt((int) $row->chars) }}</td>
                                <td class="py-2 text-right tabular-nums text-zinc-400">{{ $fmt((int) $row->calls) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    {{-- Recent activity feed --}}
    <section class="mt-6 rounded-[14px] border border-white/8 bg-panel p-5">
        <h2 class="text-sm font-semibold text-zinc-100">Recent activity</h2>
        <p class="mt-1 text-xs text-zinc-500">The latest feature events (page views excluded). Older rows are pruned after {{ (int) config('tts.analytics.retention_days') }} days.</p>
        @if ($recent->isEmpty())
            <p class="mt-3 text-sm text-zinc-500">Nothing yet.</p>
        @else
            <ul class="mt-3 divide-y divide-white/5">
                @foreach ($recent as $event)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2 text-sm">
                        <span class="font-mono text-[12.5px] text-zinc-300">{{ $event->name }}</span>
                        <span class="text-zinc-400">{{ $event->user?->name ?? 'system' }}</span>
                        @if ($model = $event->meta['model'] ?? null)
                            <span class="text-xs text-zinc-500">{{ $model }}</span>
                        @endif
                        <span class="ml-auto whitespace-nowrap text-xs text-zinc-500" title="{{ $event->created_at }}">{{ $event->created_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layout>
