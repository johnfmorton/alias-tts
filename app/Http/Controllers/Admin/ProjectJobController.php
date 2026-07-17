<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TtsProjectJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Jobs page: background "Generate remaining" runs, listed with live
 * progress and a Stop for active ones. Personal like the rest of the panel —
 * a user sees runs they own or started; a SuperAdmin sees everyone's (they can
 * dispatch runs on foreign projects, so their runs live on both lists).
 */
class ProjectJobController extends Controller
{
    private const PAGE_SIZE = 50;

    public function index(Request $request): View
    {
        return view('admin.jobs.index', [
            'jobs' => $this->visibleJobs($request->user())->with(['project', 'user', 'createdBy'])->get(),
            'isSuperAdmin' => $request->user()->isSuperAdmin(),
        ]);
    }

    /** Poll target for the page: current payloads for every listed run. */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'jobs' => $this->visibleJobs($request->user())
                ->get()
                ->map(fn (TtsProjectJob $job) => $job->statusPayload())
                ->values(),
        ]);
    }

    /**
     * Stop a run. Cooperative for a running job (the worker checks between
     * chunks — the clip being rendered still lands); immediate for a queued
     * one, so a run never sits stuck when no worker is draining the queue.
     */
    public function cancel(Request $request, TtsProjectJob $job): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->isSuperAdmin() || $job->user_id === $user->id || $job->created_by_id === $user->id,
            403,
        );

        if (! $job->isActive()) {
            return response()->json(['message' => 'This run has already finished.'], 409);
        }

        $job->update(['cancel_requested' => true]);

        // Flip a still-queued run to cancelled right now (atomic — only wins if
        // no worker picked it up in the meantime; a running worker winds down
        // on the flag instead).
        TtsProjectJob::whereKey($job->id)
            ->where('status', 'queued')
            ->update(['status' => 'cancelled', 'finished_at' => now()]);

        return response()->json(['ok' => true, 'job' => $job->fresh()->statusPayload()]);
    }

    /**
     * Runs this user may see, newest first, capped to keep the page (and its
     * poll) light — old runs age out of view, not out of the table.
     *
     * @return Builder<TtsProjectJob>
     */
    private function visibleJobs(User $user): Builder
    {
        return TtsProjectJob::query()
            ->when(! $user->isSuperAdmin(), fn (Builder $q) => $q->where(
                fn (Builder $own) => $own->where('user_id', $user->id)->orWhere('created_by_id', $user->id),
            ))
            ->latest('created_at')
            ->limit(self::PAGE_SIZE);
    }
}
