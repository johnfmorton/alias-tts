<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs Chatterbox (or any compatible model) on Replicate.
 *
 * Uses the predictions API with `Prefer: wait` for a near-synchronous call,
 * and polls as a fallback if the prediction is still processing when the wait
 * window closes.
 *
 * NOTE: confirm the exact model slug and input field names from the model's
 * schema page (config: tts.providers.replicate.text_field / reference_field).
 */
class ReplicateChatterboxProvider implements TtsProvider
{
    private const BASE = 'https://api.replicate.com/v1';

    public function __construct(
        private array $config,
        private int $timeout = 300,
    ) {}

    public function outputContainer(): string
    {
        return $this->config['output_container'] ?? 'wav';
    }

    public function synthesize(string $text, ?string $referenceAudio, array $settings): string
    {
        $token = $this->config['token'] ?? null;
        if (! $token) {
            throw new RuntimeException('REPLICATE_API_TOKEN is not configured.');
        }

        $input = [
            ($this->config['text_field'] ?? 'prompt') => $text,
        ];

        if ($referenceAudio !== null) {
            $input[$this->config['reference_field'] ?? 'audio_prompt'] = $this->toDataUri($referenceAudio);
        }

        // Map ElevenLabs-style settings (0..1) onto Chatterbox knobs, staying
        // within the model's documented bounds and keeping the EL defaults
        // (stability 0.5, style 0) aligned to Chatterbox defaults
        // (cfg_weight 0.5, exaggeration 0.5). Worth tuning by ear.
        $stability = (float) ($settings['stability'] ?? 0.5);
        $style = (float) ($settings['style'] ?? 0.0);

        // cfg_weight in [0.2, 1.0]: higher stability -> steadier pacing.
        $input['cfg_weight'] = max(0.2, min(1.0, $stability));

        // exaggeration in [0.25, 2.0]: style 0 -> 0.5 (neutral), style 1 -> 2.0.
        $input['exaggeration'] = max(0.25, min(2.0, 0.5 + ($style * 1.5)));

        // Pin the seed for reproducible output when provided; otherwise
        // Chatterbox uses a random seed on each call.
        if (isset($settings['seed'])) {
            $input['seed'] = (int) $settings['seed'];
        }

        $prediction = $this->createPrediction($token, $input);
        $prediction = $this->awaitCompletion($token, $prediction);

        $output = $prediction['output'] ?? null;
        $url = is_array($output) ? ($output[0] ?? null) : $output;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Replicate returned no audio output.');
        }

        $audio = Http::withToken($token)->timeout($this->timeout)->get($url);
        if (! $audio->successful()) {
            throw new RuntimeException('Failed to download generated audio from Replicate.');
        }

        return $audio->body();
    }

    private function createPrediction(string $token, array $input): array
    {
        $http = Http::withToken($token)
            ->timeout($this->timeout)
            ->withHeaders(['Prefer' => 'wait']);

        // Pinned version -> /predictions with `version`; otherwise the model endpoint.
        if (! empty($this->config['version'])) {
            $response = $http->post(self::BASE.'/predictions', [
                'version' => $this->config['version'],
                'input' => $input,
            ]);
        } else {
            $model = $this->config['model'] ?? 'resemble-ai/chatterbox';
            $response = $http->post(self::BASE."/models/{$model}/predictions", [
                'input' => $input,
            ]);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Replicate request failed: '.$response->body());
        }

        return $response->json();
    }

    private function awaitCompletion(string $token, array $prediction): array
    {
        $deadline = time() + $this->timeout;

        while (in_array($prediction['status'] ?? '', ['starting', 'processing'], true)) {
            if (time() >= $deadline) {
                throw new RuntimeException('Replicate prediction timed out.');
            }

            usleep(750_000);

            $get = $prediction['urls']['get'] ?? (self::BASE.'/predictions/'.($prediction['id'] ?? ''));
            $response = Http::withToken($token)->timeout($this->timeout)->get($get);
            if (! $response->successful()) {
                throw new RuntimeException('Replicate polling failed: '.$response->body());
            }
            $prediction = $response->json();
        }

        if (($prediction['status'] ?? '') !== 'succeeded') {
            $err = $prediction['error'] ?? 'unknown error';
            throw new RuntimeException('Replicate prediction '.($prediction['status'] ?? 'failed').': '.(is_string($err) ? $err : json_encode($err)));
        }

        return $prediction;
    }

    private function toDataUri(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Could not read reference audio at {$path}.");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            default => 'audio/wav',
        };

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
