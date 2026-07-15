<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sentence-ish unit of a {@see TtsProject}: its text, the pause that should
 * follow it ('sentence'|'paragraph'), and the path to its own generated raw
 * audio. Regenerating a chunk replaces only its audio; the project's final file
 * is rebuilt by concatenating every chunk's audio in order.
 */
class TtsChunk extends Model
{
    use HasUuids;

    protected $fillable = [
        'tts_project_id',
        'position',
        'text',
        'break_after',
        'voice_id',
        'settings',
        'status',
        'skipped',
        'audio_path',
        'characters',
        'error_message',
        'asr_score',
        'asr_report',
    ];

    protected $casts = [
        'status' => ChunkStatus::class,
        // Excluded from stitched output but kept in the project (reversible);
        // composes with status — a chunk can be skipped AND pending/completed.
        'skipped' => 'boolean',
        'position' => 'integer',
        'characters' => 'integer',
        // Lifetime characters synthesized for this chunk (never decremented —
        // see ProjectService::recordTake()). Deliberately NOT fillable: only
        // recordTake's increments and the backfill migration write it.
        'spent_characters' => 'integer',
        'settings' => 'array',
        'asr_score' => 'float',
        'asr_report' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(TtsProject::class, 'tts_project_id');
    }

    /**
     * Every saved take of this chunk, newest first. The chunk's audio_path points
     * at whichever take is currently selected (see {@see TtsChunkTake}).
     */
    public function takes(): HasMany
    {
        return $this->hasMany(TtsChunkTake::class, 'tts_chunk_id')->orderByDesc('created_at');
    }

    /**
     * The chunk's explicit voice override, or null when it inherits the project
     * voice. Generation uses {@see TtsChunk::voice} ?? the project voice.
     */
    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === ChunkStatus::Completed;
    }

    /**
     * Presentation data for the Studio per-chunk ASR badge, or null when there's
     * nothing to show. The verdict describes the chunk's CURRENT audio, so it is
     * suppressed unless the chunk is completed (an edited/retuned/failed chunk's
     * old verdict no longer applies; it returns on regenerate). Single source of
     * truth shared by the Blade view and the generate/reroll JSON so the two
     * never drift.
     *
     * @return array{tone: string, text: string, title: string}|null
     */
    public function asrBadge(): ?array
    {
        if ($this->status !== ChunkStatus::Completed) {
            return null;
        }

        return self::asrBadgeFrom($this->asr_report);
    }

    /**
     * Human labels for the scorer's problem codes. The raw codes stay in the
     * persisted asr_report (and docs/ASR-SETUP.md); only presentation changes.
     * An unknown code falls back to itself so a new signal is never hidden.
     */
    private const ASR_PROBLEM_LABELS = [
        'TRUNC' => 'possible cut-off',
        'NOSPEECH' => 'no speech heard',
        'TAIL' => 'junk tail',
        'TAILNOISE' => 'loud tail',
        'PAUSE' => 'long pause',
        'BNDNOISE' => 'boundary hum',
    ];

    /**
     * Format an ASR report into badge presentation data, independent of any
     * status gate. Shared by {@see TtsChunk::asrBadge()} (which adds the
     * completed-status gate) and {@see TtsChunkTake::asrBadge()} (a take's audio
     * is always a completed render) so the two never drift.
     *
     * The badge is short plain language ("QA: possible cut-off · boundary hum —
     * re-rolled ×3, still flagged"); the title is one sentence per finding
     * (newline-separated — a title attribute renders the line breaks), keeping
     * the exact measurements inline for threshold tuning.
     *
     * @param  array<string, mixed>|null  $report
     * @return array{tone: string, text: string, title: string}|null
     */
    public static function asrBadgeFrom(?array $report): ?array
    {
        if (! is_array($report) || $report === []) {
            return null;
        }

        $ok = (bool) ($report['ok'] ?? false);
        $problems = is_array($report['problems'] ?? null) ? $report['problems'] : [];
        $action = $report['action'] ?? null;
        $attempts = isset($report['reroll_attempts']) ? (int) $report['reroll_attempts'] : null;
        $has = fn (string $code) => in_array($code, $problems, true);

        $text = $ok
            ? 'QA ✓'
            : 'QA: '.implode(' · ', array_map(fn ($p) => self::ASR_PROBLEM_LABELS[$p] ?? $p, $problems));
        // Note a take-changing action inline (e.g. a flagged chunk auto-recovered).
        // "fixed by re-roll" is a success story; "still flagged" is a listen-to-it
        // story — the raw enums read almost identically, so spell them apart.
        $actionLabel = match ($action) {
            'rerolled' => 'fixed by re-roll',
            'rerolled_unrecovered' => 're-rolled'.($attempts ? " ×{$attempts}" : '').', still flagged',
            'trimmed' => 'tail trimmed off',
            'trim_failed' => 'tail trim failed',
            default => null,
        };
        if ($actionLabel !== null) {
            $text .= ' — '.$actionLabel;
        }

        $lines = [];
        if (isset($report['score'])) {
            $line = 'Speech recognition heard '.round((float) $report['score'] * 100).'% of the script';
            if (isset($report['tail_cov'])) {
                $line .= ' ('.round((float) $report['tail_cov'] * 100).'% of its ending)';
            }
            $lines[] = $line.($has('TRUNC') || $has('NOSPEECH') ? ' — words may be missing or garbled.' : '.');
        }
        if (is_array($report['boundary_noise'] ?? null)) {
            $bn = $report['boundary_noise'];
            $lines[] = 'A '.number_format((float) $bn['zcr_hz'])." Hz hum ({$bn['dbfs']} dBFS) fills a {$bn['gap_s']} s pause at a sentence boundary.";
        }
        $timings = [];
        if (isset($report['max_gap_s'])) {
            $timings[] = "longest mid-speech pause {$report['max_gap_s']} s";
        }
        if (isset($report['trail_s'])) {
            $timings[] = "audio after the last word {$report['trail_s']} s";
        }
        if ($timings !== []) {
            $lines[] = ucfirst(implode(' · ', $timings)).'.';
        }
        if (isset($report['tail_peak_dbfs'])) {
            $peak = $report['tail_peak_dbfs'];
            if (isset($report['speech_dbfs'])) {
                $lines[] = $has('TAILNOISE')
                    ? "The tail is louder than the speech ({$peak} dBFS vs {$report['speech_dbfs']} dBFS) — junk noise after the last word."
                    : "Tail peaks at {$peak} dBFS vs {$report['speech_dbfs']} dBFS speech — the tail itself is clean.";
            } else {
                $lines[] = "Tail peak: {$peak} dBFS.";
            }
        }
        if ($attempts) {
            $times = $attempts === 1 ? 'once' : "{$attempts} times";
            $lines[] = match ($action) {
                'rerolled' => "Re-rolled {$times} until a take passed QA.",
                'rerolled_unrecovered' => "Re-rolled {$times} and kept the best-scoring take; none passed QA — worth a listen.",
                default => "Re-rolled {$times}.",
            };
        }
        if (isset($report['trimmed_to_ms'])) {
            $lines[] = 'Trimmed to '.number_format((int) $report['trimmed_to_ms'] / 1000, 2).' s to cut the junk tail.';
        }

        return [
            'tone' => $ok ? 'ok' : 'bad',
            'text' => $text,
            'title' => implode("\n", $lines),
        ];
    }
}
