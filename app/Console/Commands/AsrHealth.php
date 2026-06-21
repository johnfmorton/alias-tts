<?php

namespace App\Console\Commands;

use App\Services\Asr\AsrClient;
use App\Services\Asr\ChunkQualityScorer;
use Illuminate\Console\Command;

/**
 * Post-install / post-deploy check for the Whisper ASR sidecar. Confirms the
 * daemon is reachable and the model is loaded; with --deep it transcribes a
 * bundled fixture and runs the scorer over it, so a green run proves the whole
 * round-trip (sidecar → transcript → scorer) is wired up. Exits non-zero on
 * failure, so it's usable in a deploy script. See docs/ASR-SETUP.md.
 */
class AsrHealth extends Command
{
    protected $signature = 'tts:asr:health {--deep : Transcribe a bundled fixture and verify the verdict}';

    protected $description = 'Check the Whisper ASR sidecar: reachability, loaded model, and (with --deep) transcription accuracy';

    /** A known-clean clip and the text it should transcribe to. */
    private const SELFTEST_TEXT = 'What is a dark pattern?';

    public function handle(AsrClient $client, ChunkQualityScorer $scorer): int
    {
        if (! $client->enabled()) {
            $this->warn('ASR is disabled (TTS_ASR_ENABLED=false). Enable it once the sidecar is installed — see docs/ASR-SETUP.md.');

            return self::SUCCESS;
        }

        $url = (string) config('tts.asr.url');
        $this->line("Checking ASR sidecar at <options=bold>{$url}</> …");

        $health = $client->health();
        if (! $health['reachable']) {
            $this->error('UNREACHABLE — '.($health['error'] ?? 'unknown'));
            $this->line('Is the tts-asr daemon running? See docs/ASR-SETUP.md.');

            return self::FAILURE;
        }

        $body = $health['body'];
        if (($body['status'] ?? '') !== 'ok') {
            $this->error('Sidecar is up but the model is NOT loaded: '.($body['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'OK — model "%s" (%s / %s), faster-whisper %s, health %dms',
            $body['model'] ?? '?',
            $body['device'] ?? '?',
            $body['compute_type'] ?? '?',
            $body['faster_whisper_version'] ?? '?',
            $health['latency_ms'] ?? 0,
        ));

        if (! $this->option('deep')) {
            $this->line('Run with --deep to transcribe a fixture and verify the verdict.');

            return self::SUCCESS;
        }

        return $this->selfTest($client, $scorer);
    }

    private function selfTest(AsrClient $client, ChunkQualityScorer $scorer): int
    {
        $fixture = resource_path('asr/selftest.wav');
        if (! is_file($fixture)) {
            $this->error("Self-test fixture missing: {$fixture}");

            return self::FAILURE;
        }

        $this->line('Transcribing the self-test fixture …');
        $transcript = $client->transcribe((string) file_get_contents($fixture), 'selftest.wav');
        if ($transcript === null) {
            $this->error('Transcription failed (see the log for the sidecar error).');

            return self::FAILURE;
        }

        $this->line('  expected: "'.self::SELFTEST_TEXT.'"');
        $this->line('  heard:    "'.trim((string) ($transcript['text'] ?? '')).'"  ('.($transcript['transcribe_ms'] ?? '?').'ms)');

        $verdict = $scorer->score(self::SELFTEST_TEXT, $transcript);

        $this->table(['metric', 'value'], [
            ['coverage', $verdict->score],
            ['trail_s', $verdict->trailS],
            ['max_gap_s', $verdict->maxGapS],
            ['tail_cov', $verdict->tailCov],
            ['words', $verdict->wordCount],
            ['problems', $verdict->problems === [] ? '(none)' : implode(', ', $verdict->problems)],
        ]);

        // A clean fixture should pass cleanly and recover most of its words.
        if ($verdict->ok && $verdict->score >= 0.5) {
            $this->info('Self-test PASSED — the sidecar transcribes and the scorer agrees.');

            return self::SUCCESS;
        }

        $this->error('Self-test FAILED — the transcript did not match the fixture as expected.');

        return self::FAILURE;
    }
}
