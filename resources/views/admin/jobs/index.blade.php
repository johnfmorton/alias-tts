<x-layout title="Jobs" description="Background “Generate remaining” runs — live progress while they work, a Stop button while they can still be stopped, and the reason when one fails.">

    @php
        $jobStyles = [
            'queued'    => 'border-zinc-700 bg-zinc-800 text-zinc-400',
            'running'   => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',
            'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
            'failed'    => 'border-red-500/30 bg-red-500/10 text-red-300',
            'cancelled' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        ];
    @endphp

    <div id="jobs-page" data-status-url="{{ route('admin.jobs.status') }}">
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

        <p class="mt-3 text-xs text-zinc-600">Runs keep working after you leave the page. Stopping a running job finishes the clip it’s on, then winds down — finished clips are kept. Showing the latest {{ count($jobs) }} run(s).</p>
    </div>
</x-layout>
