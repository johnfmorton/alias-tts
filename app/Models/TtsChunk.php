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
        'audio_path',
        'characters',
        'error_message',
        'asr_score',
        'asr_report',
    ];

    protected $casts = [
        'status' => ChunkStatus::class,
        'position' => 'integer',
        'characters' => 'integer',
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
     * Format an ASR report into badge presentation data, independent of any
     * status gate. Shared by {@see TtsChunk::asrBadge()} (which adds the
     * completed-status gate) and {@see TtsChunkTake::asrBadge()} (a take's audio
     * is always a completed render) so the two never drift.
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

        $text = $ok ? 'ASR ✓' : 'ASR: '.implode(', ', $problems);
        // Note a take-changing action inline (e.g. a flagged chunk auto-recovered).
        if (is_string($action) && in_array($action, ['rerolled', 'rerolled_unrecovered', 'trimmed', 'trim_failed'], true)) {
            $text .= ' · '.$action;
        }

        $detail = [];
        foreach (['score' => 'coverage', 'tail_cov' => 'tail_cov', 'trail_s' => 'trail', 'max_gap_s' => 'gap'] as $key => $label) {
            if (isset($report[$key])) {
                $unit = in_array($key, ['trail_s', 'max_gap_s'], true) ? 's' : '';
                $detail[] = "{$label} {$report[$key]}{$unit}";
            }
        }
        if (isset($report['tail_peak_dbfs'])) {
            $speech = isset($report['speech_dbfs']) ? " vs speech {$report['speech_dbfs']}" : '';
            $detail[] = "tail_peak {$report['tail_peak_dbfs']}dBFS{$speech}";
        }
        if (is_array($report['boundary_noise'] ?? null)) {
            $bn = $report['boundary_noise'];
            $detail[] = "hum {$bn['dbfs']}dBFS/{$bn['zcr_hz']}Hz @ {$bn['gap_s']}s";
        }
        if (isset($report['reroll_attempts'])) {
            $detail[] = $report['reroll_attempts'].' re-roll(s)';
        }
        if (isset($report['trimmed_to_ms'])) {
            $detail[] = 'trimmed to '.$report['trimmed_to_ms'].'ms';
        }

        return [
            'tone' => $ok ? 'ok' : 'bad',
            'text' => $text,
            'title' => implode(' · ', $detail),
        ];
    }
}
