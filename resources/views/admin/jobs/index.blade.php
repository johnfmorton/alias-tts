<x-layout title="Jobs" :description="'Background “Generate remaining” runs — live progress while they work, a Stop button while they can still be stopped, and the reason when one fails. '
    .'Finished runs clear automatically after '.config('tts.jobs_keep_days').' days, keeping at most the newest '.config('tts.jobs_keep_per_user').' per user.'">

    @php
        $jobStyles = [
            'queued'    => 'border-zinc-700 bg-zinc-800 text-zinc-400',
            'running'   => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
            'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'failed'    => 'border-red-500/30 bg-red-500/10 text-red-300',
            'cancelled' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        ];
    @endphp

    <div id="jobs-page" data-status-url="{{ route('admin.jobs.status', ['page' => $jobs->currentPage()]) }}">
        <p id="jobs-status" role="status" aria-live="polite" class="mb-2 text-sm text-zinc-400"></p>

        <div class="overflow-x-auto rounded-xl border border-zinc-800">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-900/60 text-xs uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">Project</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Progress</th>
                        <th class="px-4 py-3">Details</th>
                        <th class="px-4 py-3">Started</th>
                        @if($isSuperAdmin)
                            <th class="px-4 py-3">Owner</th>
                        @endif
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    @forelse($jobs as $job)
                        @php $payload = $job->statusPayload(); @endphp
                        <tr class="job-row align-top hover:bg-zinc-900/40" data-job-id="{{ $job->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.studio.projects.show', $job->project) }}" class="text-cyan-400 hover:underline">{{ $job->project->title ?: 'Untitled project' }}</a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="job-status inline-flex rounded-md border px-2 py-0.5 text-xs {{ $jobStyles[$payload['status']] ?? $jobStyles['queued'] }}">{{ $payload['status'] }}</span>
                            </td>
                            <td class="job-progress whitespace-nowrap px-4 py-3 text-zinc-300">{{ $payload['chunks_done'] + $payload['chunks_failed'] }}/{{ $payload['chunks_total'] }} · {{ $payload['percent'] }}%</td>
                            {{-- The server-composed message: live progress while running, the
                                 failure reason after a failure — same words the project page shows. --}}
                            <td class="job-message max-w-md px-4 py-3 text-zinc-400">{{ $payload['message'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-500" title="{{ $job->created_at }}">{{ $payload['created_human'] }}</td>
                            @if($isSuperAdmin)
                                <td class="whitespace-nowrap px-4 py-3 text-zinc-400">
                                    {{ $job->user?->name ?? '—' }}
                                    @if($job->created_by_id && $job->created_by_id !== $job->user_id)
                                        <span class="text-xs text-zinc-500">(run by {{ $job->createdBy?->name ?? 'deleted user' }})</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-3">
                                <div class="flex justify-end">
                                    <button type="button"
                                            class="job-cancel rounded-md border border-red-500/30 px-2.5 py-1 text-xs text-red-400 hover:bg-red-500/10 {{ $payload['active'] ? '' : 'hidden' }}"
                                            data-cancel-url="{{ $payload['cancel_url'] }}">Stop</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isSuperAdmin ? 7 : 6 }}" class="px-4 py-10 text-center text-zinc-500">No background runs yet — open a Studio project and click <span class="text-zinc-300">▶ Generate remaining</span> to start one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination — newest first. Each page reloads server-side, which
             re-points the poll (data-status-url carries ?page=) at the page you
             land on; only a page holding an active run polls at all. --}}
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <span class="text-xs text-zinc-500">
                @if($jobs->total() > 0)
                    Showing {{ $jobs->firstItem() }}–{{ $jobs->lastItem() }} of {{ $jobs->total() }} run(s)
                @else
                    No runs to show
                @endif
            </span>

            @if($jobs->hasPages())
                @php
                    $last = $jobs->lastPage();
                    $cur = $jobs->currentPage();
                    $from = max(1, $cur - 1);
                    $to = min($last, $cur + 1);
                    $pageClass = 'inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-zinc-800 px-1 text-xs text-zinc-300 transition hover:bg-zinc-800';
                    $activeClass = 'inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-cyan-500/30 bg-cyan-500/10 px-1 text-xs font-semibold text-cyan-300';
                    $chev = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-800 text-zinc-300 transition hover:bg-zinc-800';
                    $chevOff = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-zinc-800 text-zinc-600';
                    $ellipsis = 'px-1 text-zinc-600';
                @endphp
                <nav class="flex items-center gap-1.5" aria-label="Jobs pagination">
                    @if($jobs->onFirstPage())
                        <span class="{{ $chevOff }}" aria-hidden="true">‹</span>
                    @else
                        <a href="{{ $jobs->previousPageUrl() }}" class="{{ $chev }}" aria-label="Previous page">‹</a>
                    @endif

                    @if($from > 1)
                        <a href="{{ $jobs->url(1) }}" class="{{ $pageClass }}">1</a>
                        @if($from > 2)<span class="{{ $ellipsis }}">…</span>@endif
                    @endif

                    @for($p = $from; $p <= $to; $p++)
                        @if($p === $cur)
                            <span class="{{ $activeClass }}" aria-current="page">{{ $p }}</span>
                        @else
                            <a href="{{ $jobs->url($p) }}" class="{{ $pageClass }}">{{ $p }}</a>
                        @endif
                    @endfor

                    @if($to < $last)
                        @if($to < $last - 1)<span class="{{ $ellipsis }}">…</span>@endif
                        <a href="{{ $jobs->url($last) }}" class="{{ $pageClass }}">{{ $last }}</a>
                    @endif

                    @if($jobs->hasMorePages())
                        <a href="{{ $jobs->nextPageUrl() }}" class="{{ $chev }}" aria-label="Next page">›</a>
                    @else
                        <span class="{{ $chevOff }}" aria-hidden="true">›</span>
                    @endif
                </nav>
            @endif
        </div>

        <p class="mt-3 text-xs text-zinc-600">Runs keep working after you leave the page. Stopping a running job finishes the clip it’s on, then winds down — finished clips are kept.</p>
    </div>
</x-layout>
