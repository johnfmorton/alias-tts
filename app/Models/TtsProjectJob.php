<?php

namespace App\Models;

use App\Enums\ProjectJobStatus;
use App\Jobs\GenerateProjectChunksJob;
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
        'cancel_requested',
        'error',
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

        [$message, $tone] = match (true) {
            $this->status === ProjectJobStatus::Queued && $this->cancel_requested,
            $this->status === ProjectJobStatus::Running && $this->cancel_requested => ['Stopping after the current clip…', null],
            $this->status === ProjectJobStatus::Queued => ['Waiting for a queue worker…', null],
            $this->status === ProjectJobStatus::Running => [sprintf('Creating clip %d of %d', min($processed + 1, $total), $this->chunks_total), null],
            $this->status === ProjectJobStatus::Completed && $this->chunks_failed > 0 => [sprintf('✗ %d chunk(s) failed — retry them, then build the final.', $this->chunks_failed), 'error'],
            $this->status === ProjectJobStatus::Completed => [sprintf('✓ All %d chunk(s) generated — build the final to stitch.', $this->chunks_done), 'ok'],
            $this->status === ProjectJobStatus::Failed => ['✗ '.($this->error ?: 'The run failed.'), 'error'],
            default => [sprintf('Stopped — %d of %d generated.', $this->chunks_done, $this->chunks_total), null],
        };

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
            'created_human' => $this->created_at?->diffForHumans(),
            'finished_human' => $this->finished_at?->diffForHumans(),
            'eta_seconds' => $eta['eta_seconds'],
            'eta_human' => $eta['eta_human'],
            'cancel_url' => $this->isActive() ? route('admin.jobs.cancel', $this) : null,
        ];
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
        if ($this->status !== ProjectJobStatus::Running) {
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
