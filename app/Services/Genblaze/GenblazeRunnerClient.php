<?php

namespace App\Services\Genblaze;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the Genblaze runner (the FastAPI orchestrator) over HTTP. The runner
 * owns the generate → QA-gated re-roll → stitch pipeline and the B2 provenance
 * sink; this client just hands it a job and returns the provenance it reports.
 */
class GenblazeRunnerClient
{
    public function configured(): bool
    {
        return $this->baseUrl() !== '';
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('tts.genblaze.runner_url', ''), '/');
    }

    /**
     * Liveness probe for the admin page (does NOT run a generation).
     *
     * @return array{reachable: bool, body?: array<string, mixed>, error?: string}
     */
    public function health(): array
    {
        if (! $this->configured()) {
            return ['reachable' => false, 'error' => 'TTS_GENBLAZE_RUNNER_URL is not set'];
        }

        try {
            $res = Http::timeout(5)->acceptJson()->get($this->baseUrl().'/health');

            return $res->successful()
                ? ['reachable' => true, 'body' => (array) $res->json()]
                : ['reachable' => false, 'error' => 'HTTP '.$res->status()];
        } catch (Throwable $e) {
            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run the Genblaze pipeline for the given text/voice and return the runner's
     * provenance payload (final_url, reroll_count, per-chunk attempts, etc.).
     * Long-running — bounded by tts.genblaze.timeout. Throws on failure.
     *
     * @return array<string, mixed>
     */
    public function run(string $text, string $voice, ?int $seed = null, ?string $outputFormat = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('The Genblaze runner URL is not configured (TTS_GENBLAZE_RUNNER_URL).');
        }

        $payload = array_filter([
            'text' => $text,
            'voice' => $voice,
            'seed' => $seed,
            'output_format' => $outputFormat,
        ], fn ($v) => $v !== null);

        $res = Http::timeout((int) config('tts.genblaze.timeout', 600))
            ->acceptJson()
            ->post($this->baseUrl().'/run', $payload);

        if (! $res->successful()) {
            throw new RuntimeException('the runner returned HTTP '.$res->status().': '.trim($res->body()));
        }

        return (array) $res->json();
    }
}
