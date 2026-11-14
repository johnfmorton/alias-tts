<?php

namespace App\Services\Asr;

/**
 * End-to-end proof that transcript QA works: send the bundled known-clean clip
 * through the sidecar's /transcribe and score the result. The sidecar's /health
 * only says the model loaded — it stays green when decoding itself is broken
 * (e.g. an incompatible PyAV in the sidecar's venv makes every /transcribe 500),
 * and because a failed transcription just skips QA, renders then go unscored
 * silently. Shared by `tts:doctor` / the Health page and `tts:asr:health --deep`.
 */
class AsrSelfTest
{
    /** The text the bundled fixture speaks. */
    public const TEXT = 'What is a dark pattern?';

    public function __construct(
        private AsrClient $client,
        private ChunkQualityScorer $scorer,
    ) {}

    public function fixturePath(): string
    {
        return resource_path('asr/selftest.wav');
    }

    /**
     * @return array{ok: bool, error: string|null, heard: string|null, transcribe_ms: int|null, verdict: ChunkQualityVerdict|null}
     */
    public function run(): array
    {
        $fail = fn (string $error): array => [
            'ok' => false, 'error' => $error, 'heard' => null, 'transcribe_ms' => null, 'verdict' => null,
        ];

        $fixture = $this->fixturePath();
        if (! is_file($fixture)) {
            return $fail("self-test fixture missing: {$fixture}");
        }

        $transcript = $this->client->transcribe((string) file_get_contents($fixture), 'selftest.wav');
        if ($transcript === null) {
            return $fail($this->client->lastError() ?? 'transcription failed');
        }

        $verdict = $this->scorer->score(self::TEXT, $transcript);
        $heard = trim((string) ($transcript['text'] ?? ''));

        // A clean fixture should pass cleanly and recover most of its words.
        $ok = $verdict->ok && $verdict->score >= 0.5;

        return [
            'ok' => $ok,
            'error' => $ok ? null : 'the transcript did not match the fixture (heard "'.$heard.'")',
            'heard' => $heard,
            'transcribe_ms' => isset($transcript['transcribe_ms']) ? (int) $transcript['transcribe_ms'] : null,
            'verdict' => $verdict,
        ];
    }
}
