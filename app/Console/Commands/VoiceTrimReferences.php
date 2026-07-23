<?php

namespace App\Console\Commands;

use App\Console\Concerns\GuardsSharedStorage;
use App\Models\Voice;
use App\Services\Asr\AsrClient;
use App\Services\Audio\AudioConverter;
use App\Services\Tts\ModelCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-time backfill for clips stored before the save-time length cap
 * (TTS_REFERENCE_MAX_SECONDS) existed: apply the same pause-aware trim to
 * every stored reference clip that exceeds it. New clips are capped at save,
 * so this only ever has work on rows from before v0.82.0 (or after raising
 * the cap and lowering it again).
 *
 * The trim is the identical {@see AudioConverter::trimReference} used at
 * save: cut at a natural pause with a short fade, never mid-word. Trimming
 * changes the stored bytes, so each trimmed voice's cached /v1 speech
 * regenerates on its next request (the cache key fingerprints the clip);
 * Studio project takes are untouched. Engines only read a clip's head, so
 * the clone itself should sound identical — worth a ▶ Test per voice even so.
 *
 * A trim also invalidates the voice's stored transcript, which qwen's clone
 * mode reads ALONG with the audio ({@see refreshTranscript}) — so a trimmed
 * voice's transcript is re-read from the bytes we just wrote.
 */
class VoiceTrimReferences extends Command
{
    use GuardsSharedStorage;

    protected $signature = 'voices:trim-references
                            {--dry-run : Report what would be trimmed without writing anything}
                            {--retranscribe : Re-read the stored clip transcript even for clips already within the cap (for voices trimmed before this command refreshed transcripts)}
                            {--force : Allow the run when tts.storage_disk is a remote bucket (see GuardsSharedStorage)}
                            {--voice=* : Only these voices (slug or UUID); default is every voice with a clip}';

    protected $description = 'Trim stored reference clips over TTS_REFERENCE_MAX_SECONDS at a natural pause (clips ship with every render; engines only read the head)';

    public function handle(AudioConverter $converter, AsrClient $asr): int
    {
        $cap = (float) config('tts.reference_max_seconds', 25);
        if ($cap <= 0) {
            $this->warn('TTS_REFERENCE_MAX_SECONDS is 0 — the length cap is disabled, nothing to trim.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $retranscribe = (bool) $this->option('retranscribe');

        // A dry run reads only, so it's always allowed to look.
        if (! $dryRun && $this->sharedStorageBlocked('rewrite reference clips')) {
            return self::FAILURE;
        }
        $disk = Storage::disk(config('tts.storage_disk'));

        $voices = Voice::whereNotNull('reference_audio_path')->orderBy('slug')->get();
        if ($only = (array) $this->option('voice')) {
            $voices = $voices->filter(fn (Voice $v) => in_array($v->slug, $only, true) || in_array($v->id, $only, true));
        }

        $trimmed = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;
        $refreshed = 0;
        $stale = 0;
        // true = transcript re-read, false = it's now stale and needs a human,
        // null = this voice has no transcript to keep in sync.
        $countTranscript = function (?bool $ok) use (&$refreshed, &$stale): void {
            if ($ok === true) {
                $refreshed++;
            } elseif ($ok === false) {
                $stale++;
            }
        };

        foreach ($voices as $voice) {
            $label = sprintf('%s%s', $voice->slug, $voice->user_id ? " (user {$voice->user_id})" : ' (shared)');
            $path = $voice->reference_audio_path;

            try {
                if (! $disk->exists($path)) {
                    // Not fatal: built-in clips self-heal from seed assets at
                    // synthesis time (see VoiceReference), and a dead custom
                    // path is a pre-existing problem this command can't cause.
                    $this->warn("  ! {$label} — clip missing at {$path}");
                    $missing++;

                    continue;
                }

                $bytes = (string) $disk->get($path);
                // Non-WAV clips (raw uploads) decode first; a trimmed one is
                // stored as WAV — length matters more than container, and
                // within-cap clips are never touched.
                $wav = strncmp($bytes, 'RIFF', 4) === 0 ? $bytes : $converter->decodeToWav($bytes);
                $before = $converter->wavDurationSeconds($wav);

                $result = $converter->trimReference($wav, $cap);
                if ($result === null) {
                    $this->line(sprintf('  · %s — %.1fs, within the cap', $label, $before ?? 0));
                    $skipped++;

                    // --retranscribe repairs voices trimmed by an earlier run
                    // of this command, before it refreshed transcripts.
                    if ($retranscribe) {
                        $countTranscript($this->refreshTranscript($voice, $wav, $asr, $dryRun, $label));
                    }

                    continue;
                }

                $after = $converter->wavDurationSeconds($result);

                if ($dryRun) {
                    $this->info(sprintf('  ~ %s — would trim %.1fs → %.1fs', $label, $before, $after));
                    $trimmed++;
                    $countTranscript($this->refreshTranscript($voice, $result, $asr, true, $label));

                    continue;
                }

                $newPath = preg_replace('/\.[A-Za-z0-9]+$/', '.wav', $path) ?: $path;
                $disk->put($newPath, $result);
                if ($newPath !== $path) {
                    $voice->update(['reference_audio_path' => $newPath]);
                    $disk->delete($path);
                }

                $this->info(sprintf('  ✓ %s — trimmed %.1fs → %.1fs at a natural pause', $label, $before, $after));
                $trimmed++;
                $countTranscript($this->refreshTranscript($voice, $result, $asr, false, $label));
            } catch (Throwable $e) {
                $this->error("  ✗ {$label} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%s%d trimmed · %d already within the %.0fs cap · %d missing · %d failed',
            $dryRun ? '[dry-run] ' : '', $trimmed, $skipped, $cap, $missing, $failed,
        ));
        if ($refreshed > 0 || $stale > 0) {
            $this->line(sprintf(
                '%s%d clip transcript(s) re-read · %d left stale (fix by hand on the voice\'s edit page)',
                $dryRun ? '[dry-run] ' : '', $refreshed, $stale,
            ));
        }
        if ($trimmed > 0 && ! $dryRun) {
            $this->line('Trimmed voices: cached /v1 speech regenerates on next request (project takes untouched). Give each a ▶ Test listen.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Keep a voice's clip transcript in sync with the bytes just stored.
     *
     * Qwen's voice_clone mode sends `reference_text` ALONG with the clip, so a
     * transcript written against the untrimmed take asks the model to hear
     * words that are no longer there. Re-read it from the trimmed audio with
     * the same ASR that wrote it at save time.
     *
     * Only for engines that read a transcript, and only when one is already
     * set: an empty field stays empty (save-time auto-transcription owns that
     * case, and a user who deliberately cleared it shouldn't get it back).
     *
     * @return bool|null true = re-read, false = now stale and needs a human,
     *                   null = nothing to keep in sync
     */
    private function refreshTranscript(Voice $voice, string $wav, AsrClient $asr, bool $dryRun, string $label): ?bool
    {
        if (! ModelCatalog::acceptsReferenceText(ModelCatalog::forVoice($voice))) {
            return null;
        }

        $settings = is_array($voice->settings) ? $voice->settings : [];
        if (trim((string) ($settings['reference_text'] ?? '')) === '') {
            return null;
        }

        if ($dryRun) {
            $this->line('      · transcript would be re-read from the trimmed clip');

            return true;
        }

        if (! $asr->enabled()) {
            $this->warn("      ! {$label} — transcript still describes the untrimmed clip (ASR is off); edit it by hand");

            return false;
        }

        $payload = $asr->transcribe($wav, 'reference.wav');
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            $this->warn("      ! {$label} — transcription failed; the transcript still describes the untrimmed clip");

            return false;
        }

        $settings['reference_text'] = mb_substr($text, 0, 2000);
        $voice->update(['settings' => $settings]);
        $this->line('      · transcript re-read from the trimmed clip');

        return true;
    }
}
