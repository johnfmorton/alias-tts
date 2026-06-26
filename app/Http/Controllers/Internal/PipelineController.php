<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Voice;
use App\Services\Asr\AsrClient;
use App\Services\Asr\ChunkRemediator;
use App\Services\Audio\AudioConverter;
use App\Services\TextChunker;
use App\Services\TextNormalizer;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceReference;
use App\Services\Tts\VoiceSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stateless pipeline primitives for the Genblaze orchestrator.
 *
 * Each endpoint exposes ONE stage of the existing TTS pipeline so the external
 * Genblaze runner can own the orchestration (chunk -> generate-one-seed -> ASR
 * score -> trim -> stitch) while every heavy operation still runs here, reusing
 * the exact services the public /v1 and Studio paths use. No DB writes, no
 * caching: these are pure functions over their inputs.
 *
 * Audio crosses the wire as raw bytes (request: multipart file upload; response:
 * the audio body), so the runner can buffer to a local temp file and hand a
 * file:// asset to the Genblaze ObjectStorageSink — no public/signed audio URL
 * is needed on this side.
 *
 * Mounted at /v1/internal/* behind ValidateInternalSecret (routes/internal.php).
 */
class PipelineController extends Controller
{
    public function __construct(
        private TtsProvider $provider,
        private AudioConverter $converter,
        private TextChunker $chunker,
        private TextNormalizer $normalizer,
        private ChunkRemediator $remediator,
        private AsrClient $asr,
        private VoiceSettingsResolver $settingsResolver,
    ) {}

    /** Normalize + chunk text into the pieces the pipeline will synthesize. */
    public function chunk(Request $request): Response
    {
        $data = $request->validate([
            'text' => ['required', 'string'],
        ]);

        $normalized = $this->normalizer->normalize($data['text']);

        $segments = $this->chunker->segment(
            $normalized,
            (int) config('tts.chunk_chars', 280),
            (int) config('tts.block_space_run', 4),
            (int) config('tts.min_chunk_chars', 30),
            (int) config('tts.short_trailer_words', 3),
        );

        $chunks = [];
        foreach ($segments as $i => $segment) {
            $chunks[] = [
                'position' => $i,
                'text' => $segment['text'],
                'break_after' => $segment['breakAfter'],
                'characters' => mb_strlen($segment['text']),
            ];
        }

        return response()->json([
            'normalized_text' => $normalized,
            'chunks' => $chunks,
        ]);
    }

    /**
     * Synthesize ONE chunk with a single seed (no internal ASR re-roll — the
     * orchestrator owns that). Returns the provider's raw container bytes.
     */
    public function generate(Request $request): Response
    {
        $data = $request->validate([
            'voice_id' => ['required', 'string'],
            'text' => ['required', 'string'],
            'settings' => ['sometimes', 'array'],
            'seed' => ['sometimes', 'nullable', 'integer'],
        ]);

        $voice = Voice::resolve($data['voice_id']);
        if (! $voice) {
            return $this->error("A voice with voice_id '{$data['voice_id']}' could not be found.", 404);
        }

        $settings = $this->settingsResolver->resolve($voice, $data['settings'] ?? []);
        if (array_key_exists('seed', $data) && $data['seed'] !== null) {
            $settings['seed'] = (int) $data['seed'];
        }

        try {
            $bytes = $this->provider->synthesize($data['text'], $this->referencePath($voice), $settings);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Chunk synthesis failed: '.$e->getMessage(), 502);
        }

        $container = $this->provider->outputContainer();

        return response($bytes, 200)
            ->header('Content-Type', $this->containerMime($container))
            ->header('X-Tts-Container', $container);
    }

    /**
     * ASR-score one generated chunk against its source text. Degrades safely:
     * when the sidecar is disabled/unreachable it returns {"available": false}
     * so the orchestrator can skip the QA gate rather than fail the run.
     */
    public function score(Request $request): Response
    {
        $request->validate([
            'text' => ['required', 'string'],
            'audio' => ['required', 'file'],
        ]);

        if (! $this->asr->enabled()) {
            return response()->json(['available' => false]);
        }

        $bytes = $this->fileBytes($request->file('audio'));
        $verdict = $this->remediator->score($request->string('text')->value(), $bytes, 'genblaze-chunk');

        if ($verdict === null) {
            return response()->json(['available' => false]);
        }

        return response()->json(['available' => true] + $verdict->toArray());
    }

    /** Hard-cut a raw chunk to its first trim_at_ms milliseconds (lossless). */
    public function trim(Request $request): Response
    {
        $data = $request->validate([
            'audio' => ['required', 'file'],
            'trim_at_ms' => ['required', 'integer', 'min:1'],
        ]);

        $bytes = $this->fileBytes($request->file('audio'));
        $trimmed = $this->converter->truncateToMs($bytes, (int) $data['trim_at_ms']);

        if ($trimmed === null) {
            return $this->error('Trim failed (ffmpeg error or non-positive cut point).', 422);
        }

        return response($trimmed, 200)->header('Content-Type', 'audio/wav');
    }

    /**
     * Concatenate ordered chunk audio into the final output, inserting a
     * sentence/paragraph silence at each seam. Returns the encoded final audio.
     */
    public function stitch(Request $request): Response
    {
        $data = $request->validate([
            'chunks' => ['required', 'array', 'min:1'],
            'chunks.*' => ['required', 'file'],
            'break_after' => ['sometimes', 'array'],
            'break_after.*' => ['string', 'in:sentence,paragraph'],
            'output_format' => ['sometimes', 'string'],
        ]);

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('chunks');
        $breaks = $data['break_after'] ?? [];
        $outputFormat = $data['output_format'] ?? (string) config('tts.default_output_format', 'mp3_44100_128');

        $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
        $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);

        $rawParts = [];
        $seamGapsMs = [];
        foreach ($files as $i => $file) {
            $rawParts[] = $this->fileBytes($file);
            $break = $breaks[$i] ?? 'sentence';
            $seamGapsMs[] = $break === 'paragraph' ? $paragraphGap : $sentenceGap;
        }

        try {
            [$bytes, $mime, $ext] = $this->converter->concatenate($rawParts, $outputFormat, 'wav', $seamGapsMs);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Stitch failed: '.$e->getMessage(), 502);
        }

        return response($bytes, 200)
            ->header('Content-Type', $mime)
            ->header('X-Tts-Ext', $ext);
    }

    private function fileBytes(UploadedFile $file): string
    {
        return (string) file_get_contents($file->getRealPath());
    }

    private function referencePath(Voice $voice): ?string
    {
        return VoiceReference::localPath($voice);
    }

    private function containerMime(string $container): string
    {
        return match (strtolower($container)) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };
    }

    private function error(string $message, int $status): Response
    {
        return response()->json(['detail' => ['message' => $message, 'status' => $status]], $status);
    }
}
