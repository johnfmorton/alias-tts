<?php

namespace App\Models;

use App\Enums\ProjectJobStatus;
use App\Jobs\DuplicateProjectJob;
use App\Jobs\GenerateProjectChunksJob;
use App\Jobs\StitchProjectJob;
use App\Support\GenerationEstimator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One background "Generate remaining" run for a {@see TtsProject}. Dispatched
 * from the project page, executed by {@see GenerateProjectChunksJob}
 * on the queue worker, polled by the project page and listed on the Jobs page.
 * `chunk_ids` starts as the outstanding-chunk snapshot taken at dispatch and
 * can grow while the run is active (per-chunk "Regenerate" inserts right after
 * the entry in flight — see StudioProjectController::queueChunk()); the
 * counters and status are updated by the worker as it goes. Cancel is
 * cooperative: `cancel_requested` is checked between chunks, never mid-synthesis.
 */
class TtsProjectJob extends Model
{
    use HasUuids;

    public const TYPE_GENERATE = 'generate_chunks';

    /** A "Build final" stitch run — executed by {@see StitchProjectJob}. */
    public const TYPE_STITCH = 'stitch';

    /**
     * A "Duplicate project" run — executed by {@see DuplicateProjectJob}.
     * Booked on the SOURCE project (it occupies it like a stitch), but its work
     * is a deep copy into a NEW project recorded in `result_project_id`; the
     * page follows it and opens the copy when it finishes.
     */
    public const TYPE_DUPLICATE = 'duplicate';

    protected $fillable = [
        'tts_project_id',
        'user_id',
        'created_by_id',
        'type',
        'status',
        'chunk_ids',
        'chunks_total',
        'chunks_done',
        'chunks_failed',
        'concurrency',
        'chunks_claimed',
        'cancel_requested',
        'error',
        'result_project_id',
        'result_message',
        'started_at',
        'finished_at',
        'estimated_ms',
    ];

    protected $casts = [
        'status' => ProjectJobStatus::class,
        'chunk_ids' => 'array',
        'chunks_total' => 'integer',
        'chunks_done' => 'integer',
        'chunks_failed' => 'integer',
        'concurrency' => 'integer',
        'chunks_claimed' => 'integer',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'estimated_ms' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(TtsProject::class, 'tts_project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * A "Build final" stitch run. It occupies the project like a generate run
     * (the guards 409 the other kind while one is active) but carries no chunk
     * list — the worker stitches whatever is selected when it runs.
     */
    public function isStitch(): bool
    {
        return $this->type === self::TYPE_STITCH;
    }

    /**
     * A "Duplicate project" run. Like a stitch it occupies the source and carries
     * no chunk line to join, but unlike either other type it mints a NEW project
     * (`result_project_id`) the page opens when the run completes.
     */
    public function isDuplicate(): bool
    {
        return $this->type === self::TYPE_DUPLICATE;
    }

    /**
     * Executed by claim-based worker jobs (bounded concurrency) rather than the
     * legacy serial loop. The claim cursor `chunks_claimed` is only meaningful
     * for these runs; the serial loop's cursor stays chunks_done+chunks_failed.
     */
    public function claimBased(): bool
    {
        return $this->concurrency !== null;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [ProjectJobStatus::Queued->value, ProjectJobStatus::Running->value]);
    }

    /** The active (queued/running) run occupying a project, if any. */
    public static function activeFor(string $projectId): ?self
    {
        return self::query()
            ->where('tts_project_id', $projectId)
            ->active()
            ->latest('created_at')
            ->first();
    }

    /**
     * The run serialized for polling clients (project page + Jobs page). The
     * message is composed server-side so both pages print the same words, and
     * the terminal copy matches what the old in-page loop said. `tone` drives
     * the status line's color client-side; `cancel_url` only exists while the
     * run can still be stopped.
     *
     * @return array<string, mixed>
     */
    public function statusPayload(): array
    {
        $processed = $this->chunks_done + $this->chunks_failed;
        $total = max(1, $this->chunks_total);

        [$message, $tone] = $this->isStitch() ? $this->stitchMessage()
            : ($this->isDuplicate() ? $this->duplicateMessage() : match (true) {
                $this->status === ProjectJobStatus::Queued && $this->cancel_requested,
                $this->status === ProjectJobStatus::Running && $this->cancel_requested => ['Stopping after the current clip…', null],
                $this->status === ProjectJobStatus::Queued => ['Waiting for a queue worker…', null],
                // Several clips are in flight on a concurrent run — a sequential
                // "Creating clip N" would be a lie there, so count landings instead.
                $this->status === ProjectJobStatus::Running && $this->concurrency > 1 => [sprintf('Creating clips — %d of %d done', $processed, $this->chunks_total), null],
                $this->status === ProjectJobStatus::Running => [sprintf('Creating clip %d of %d', min($processed + 1, $total), $this->chunks_total), null],
                $this->status === ProjectJobStatus::Completed && $this->chunks_failed > 0 => [sprintf('✗ %d chunk(s) failed — retry them, then build the final.', $this->chunks_failed), 'error'],
                // A single-chunk run (per-chunk Regenerate): "All 1 chunk(s)
                // generated" reads as project-wide on a 30-chunk project — name
                // the one clip that landed instead.
                $this->status === ProjectJobStatus::Completed && $this->chunks_total === 1 => ['✓ '.$this->singleClipLabel().' generated — build the final to include it.', 'ok'],
                $this->status === ProjectJobStatus::Completed => [sprintf('✓ All %d chunk(s) generated — build the final to stitch.', $this->chunks_done), 'ok'],
                $this->status === ProjectJobStatus::Failed => ['✗ '.($this->error ?: 'The run failed.'), 'error'],
                default => [sprintf('Stopped — %d of %d generated.', $this->chunks_done, $this->chunks_total), null],
            });

        // Estimated time left — only while actually rendering, and folded into
        // the same message string so every poller (project page, Jobs page)
        // shows it with no client change. The live running average takes over
        // once a chunk has finished; before that the stored up-front estimate
        // seeds it (scaled to what's still outstanding).
        $eta = GenerationEstimator::payload($this->etaMs($processed));
        if ($this->status === ProjectJobStatus::Running && ! $this->cancel_requested && $eta['eta_human'] !== null) {
            $message .= ' · '.$eta['eta_human'].' left';
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status->value,
            'active' => $this->isActive(),
            'cancel_requested' => $this->cancel_requested,
            'chunks_total' => $this->chunks_total,
            'chunks_done' => $this->chunks_done,
            'chunks_failed' => $this->chunks_failed,
            'percent' => (int) floor($processed / $total * 100),
            'message' => $message,
            'tone' => $tone,
            'error' => $this->error,
            // Where the page navigates once a duplicate run finishes: the fresh
            // copy. Null for every other type and until a duplicate completes.
            'redirect_url' => $this->isDuplicate() && $this->status === ProjectJobStatus::Completed && $this->result_project_id
                ? route('admin.studio.projects.show', $this->result_project_id)
                : null,
            'created_human' => $this->created_at?->diffForHumans(),
            'finished_human' => $this->finished_at?->diffForHumans(),
            'eta_seconds' => $eta['eta_seconds'],
            'eta_human' => $eta['eta_human'],
            // A running stitch is one ffmpeg pass, and a running duplicate is a
            // single copy sweep — neither has a between-chunks seam to stop at,
            // so Stop is only offered while they still wait for a worker.
            'cancel_url' => $this->isActive() && ! (($this->isStitch() || $this->isDuplicate()) && $this->status === ProjectJobStatus::Running)
                ? route('admin.jobs.cancel', $this)
                : null,
        ];
    }

    /**
     * [message, tone] for a stitch run. All-or-nothing, so there is no clip
     * counter to narrate — just the phase. Cancelled is only reachable while
     * the run was still queued (a running stitch can't be stopped).
     *
     * @return array{0: string, 1: ?string}
     */
    private function stitchMessage(): array
    {
        $n = $this->chunks_total;

        return match (true) {
            $this->status === ProjectJobStatus::Queued && $this->cancel_requested => ['Stopping…', null],
            $this->status === ProjectJobStatus::Queued => ['Waiting for a queue worker…', null],
            $this->status === ProjectJobStatus::Running => [sprintf('Stitching %d clip%s into the final…', $n, $n === 1 ? '' : 's'), null],
            $this->status === ProjectJobStatus::Completed => ['✓ Final rebuilt.', 'ok'],
            $this->status === ProjectJobStatus::Failed => ['✗ '.($this->error ?: 'The rebuild failed.'), 'error'],
            default => ['Stopped before it started.', null],
        };
    }

    /**
     * [message, tone] for a duplicate run. It copies one file per chunk, so
     * `chunks_done` climbs as clips are copied and the progress column reads N/M
     * like a generate run; the terminal line points the page at the copy (the
     * URL rides in the payload's `redirect_url`).
     *
     * @return array{0: string, 1: ?string}
     */
    private function duplicateMessage(): array
    {
        $total = max(1, $this->chunks_total);

        return match (true) {
            $this->status === ProjectJobStatus::Queued && $this->cancel_requested => ['Stopping…', null],
            $this->status === ProjectJobStatus::Queued => ['Waiting for a queue worker…', null],
            $this->status === ProjectJobStatus::Running => [sprintf('Duplicating — copying clip %d of %d…', min($this->chunks_done + 1, $total), $this->chunks_total), null],
            $this->status === ProjectJobStatus::Completed => ['✓ Duplicated — opening the copy…', 'ok'],
            $this->status === ProjectJobStatus::Failed => ['✗ '.($this->error ?: 'The duplicate failed.'), 'error'],
            default => ['Stopped before it finished.', null],
        };
    }

    /**
     * "Clip 29" for a single-chunk run's messages. Looked up live — cheap,
     * since only terminal single-chunk payloads ask — and just "Clip" when
     * the chunk was deleted after the run.
     */
    private function singleClipLabel(): string
    {
        $ids = array_values((array) $this->chunk_ids);
        $position = count($ids) === 1 ? TtsChunk::whereKey($ids[0])->value('position') : null;

        return $position === null ? 'Clip' : 'Clip '.($position + 1);
    }

    /**
     * ms remaining for a running job, or null when there's nothing to estimate
     * (not running, or every chunk processed). O(1) — pure arithmetic on the
     * row's own columns, so the Jobs list can serialize many runs cheaply.
     * Running average once ≥1 chunk is done; before that the stored up-front
     * estimate, scaled to the still-outstanding share.
     */
    private function etaMs(int $processed): ?int
    {
        // A stitch has no per-chunk cadence to extrapolate from, and a duplicate's
        // per-clip copy time isn't the learned synthesis cadence — skip both.
        if ($this->isStitch() || $this->isDuplicate() || $this->status !== ProjectJobStatus::Running) {
            return null;
        }

        $remaining = $this->chunks_total - $processed;
        if ($remaining < 1) {
            return null;
        }

        if ($this->started_at && $processed >= 1) {
            $elapsedMs = max(0, now()->timestamp - $this->started_at->timestamp) * 1000;
            if ($live = GenerationEstimator::liveMs($elapsedMs, $processed, $remaining)) {
                return $live;
            }
        }

        if ($this->estimated_ms) {
            return (int) round($this->estimated_ms * $remaining / max(1, $this->chunks_total));
        }

        return null;
    }
}
