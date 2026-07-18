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
     * Problem code → short headline for the badge popover, ordered worst-first so
     * a chunk with several findings names its most serious one. Raw codes stay in
     * the persisted asr_report (and docs/ASR-SETUP.md) with the exact measurements
     * for threshold tuning; only the surface prose lives here.
     */
    private const PROBLEM_HEADINGS = [
        'NOSPEECH' => 'No speech detected',
        'TRUNC' => 'Possible cut-off',
        'PAUSE' => 'Mid-speech pause',
        'BNDNOISE' => 'Boundary hum',
        'TAILNOISE' => 'Loud tail',
        'TAIL' => 'Junk tail',
    ];

    /** Problem code → the plain "what happened" sentence shown in the popover body. */
    private const PROBLEM_LEADS = [
        'NOSPEECH' => 'No speech was detected in this take.',
        'TRUNC' => 'The take ended before the last words.',
        'PAUSE' => 'A long gap opened up mid-sentence.',
        'BNDNOISE' => 'A tonal hum filled a pause at a sentence boundary.',
        'TAILNOISE' => 'A burst of noise landed after the speech ended.',
        'TAIL' => 'Extra audio ran on after the last word.',
    ];

    /**
     * Format an ASR report into badge presentation data, independent of any
     * status gate. Shared by {@see TtsChunk::asrBadge()} (which adds the
     * completed-status gate) and {@see TtsChunkTake::asrBadge()} (a take's audio
     * is always a completed render) so the two never drift.
     *
     * Three tones carry what the standing QA paragraph used to say (design "QA
     * Badge States"): a quiet green pass, an amber "fixed" when auto-remediation
     * CHANGED the audio to resolve a flag, and a red "check" when a flag was left
     * for a human. The popover fields (heading/body/fix/prompt/actions) drive the
     * rich hover card; `text` + `title` stay as the short label + plain-text
     * fallback the take pills and the receipt (asr_summary) read.
     *
     * @param  array<string, mixed>|null  $report
     * @return array{tone: string, text: string, heading: ?string, body: string, fix: array{label: string, text: string}|null, prompt: ?string, actions: list<array{act: string, label: string}>, title: string}|null
     */
    public static function asrBadgeFrom(?array $report): ?array
    {
        if (! is_array($report) || $report === []) {
            return null;
        }

        $ok = (bool) ($report['ok'] ?? false);
        $problems = is_array($report['problems'] ?? null) ? $report['problems'] : [];
        $action = $report['action'] ?? null;
        $attempts = isset($report['reroll_attempts']) ? (int) $report['reroll_attempts'] : 0;
        $dismissed = (bool) ($report['qa_dismissed'] ?? false);

        // A take-changing fix that resolved the flag: a re-roll that scored clean,
        // or a lossless trim of a junk tail. "We changed the audio" (amber) reads
        // apart from both "it passed" (green) and "you should check this" (red).
        $fixed = in_array($action, ['rerolled', 'trimmed'], true);

        $tone = match (true) {
            $dismissed => 'reviewed', // flagged, but a human listened and waved it through
            $fixed => 'fixed',
            $ok => 'ok',
            default => 'bad',         // flagged, not auto-fixed — needs a human
        };

        $text = match ($tone) {
            'ok' => 'QA ✓',
            'fixed' => 'QA · fixed',
            'reviewed' => 'QA · reviewed',
            default => 'QA · check',
        };

        // Which problems name the heading. A recovered re-roll persists the new
        // clean take's report (no problems), so it carries the original set as
        // `fixed_problems`; everything else names its current problems.
        $named = $problems;
        if ($fixed && is_array($report['fixed_problems'] ?? null) && $report['fixed_problems'] !== []) {
            $named = $report['fixed_problems'];
        }
        $heading = null;
        $lead = null;
        foreach (self::PROBLEM_HEADINGS as $code => $label) {
            if (in_array($code, $named, true)) {
                $heading = $label;
                $lead = self::PROBLEM_LEADS[$code];
                break;
            }
        }

        $fix = null;     // the bolded "Auto-fixed: …" / "Reviewed: …" clause
        $prompt = null;  // footer lead-in ("Not right?")
        $actions = [];   // footer buttons the popover wires to chunk actions

        if ($tone === 'ok') {
            $body = 'Passed the automatic quality check — the transcript matched the script, with a clean start and end.';
        } else {
            $body = $lead ?? 'The automatic quality check flagged this take.';

            if ($tone === 'fixed') {
                $fix = ['label' => 'Auto-fixed:', 'text' => self::asrFixText($report, $action, $attempts)];
                if ($action === 'trimmed') {
                    $prompt = 'Trimmed too much?';
                    $actions = [['act' => 'restore', 'label' => 'Restore full take']];
                } else {
                    $prompt = 'Not right?';
                    $actions = [
                        ['act' => 'reroll', 'label' => 'Re-roll again'],
                        ['act' => 'restore', 'label' => 'keep original'],
                    ];
                }
            } elseif ($tone === 'reviewed') {
                $fix = ['label' => 'Reviewed:', 'text' => "you marked this take fine, so it won't be flagged again."];
                $actions = [
                    ['act' => 'reroll', 'label' => 'Re-roll'],
                    ['act' => 'play', 'label' => 'Play'],
                ];
            } else { // bad — flagged, needs a human
                $fix = ['label' => '', 'text' => $action === 'rerolled_unrecovered'
                    ? 'Re-rolled '.self::asrTimes($attempts).", but it's still flagged — the audio is unchanged, so give it a listen."
                    : "Auto-fix can't safely change this one. The audio was left unchanged — give it a listen."];
                $actions = [
                    ['act' => 'reroll', 'label' => 'Re-roll'],
                    ['act' => 'play', 'label' => 'Play'],
                    ['act' => 'dismiss', 'label' => 'Dismiss'],
                ];
            }
        }

        // Plain-text fallback: the take pills' native title + the receipt's
        // asr_summary. Same words as the popover, minus the interactive footer.
        $titleParts = [];
        if ($heading !== null) {
            $titleParts[] = rtrim($heading, '.').'.';
        }
        $titleParts[] = $body;
        if ($fix !== null && $fix['text'] !== '') {
            $titleParts[] = trim(($fix['label'] !== '' ? $fix['label'].' ' : '').$fix['text']);
        }

        return [
            'tone' => $tone,
            'text' => $text,
            'heading' => $heading,
            'body' => $body,
            'fix' => $fix,
            'prompt' => $prompt,
            'actions' => $actions,
            'title' => trim(implode(' ', $titleParts)),
        ];
    }

    /** "once" / "twice" / "N times" for the re-roll narrative. */
    private static function asrTimes(int $n): string
    {
        return match (true) {
            $n <= 1 => 'once',
            $n === 2 => 'twice',
            default => $n.' times',
        };
    }

    /** The concrete fix sentence for an amber "fixed" badge (re-roll vs trim). */
    private static function asrFixText(array $report, ?string $action, int $attempts): string
    {
        if ($action === 'trimmed') {
            return isset($report['trail_s'])
                ? 'trimmed '.number_format((float) $report['trail_s'], 1).'s of trailing audio.'
                : 'trimmed the junk tail off the end.';
        }

        return 're-rolled '.self::asrTimes($attempts).' and the new take transcribed complete.';
    }
}
