<?php

namespace App\Services;

use App\Models\TtsChunk;
use App\Models\TtsProject;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the "approved final" receipt: a portable .zip containing the frozen
 * approved audio, a human-readable provenance receipt with an embedded offline
 * verifier (the approved hash is baked into the page — no #expect= link needed),
 * and a machine-readable manifest. Unzip it, open receipt.html with no network,
 * drop the audio on it, and it confirms the bytes are untouched.
 *
 * Modeled on {@see VoiceService::export()}. The load-bearing value is the byte
 * SHA-256 of the SEALED snapshot (computed at seal time, persisted on the
 * project); the export ships those exact bytes so the printed hash always matches
 * the file in the zip.
 */
class ProjectExportService
{
    public function __construct(private readonly ProjectService $projects) {}

    public function buildReceiptZip(TtsProject $project): string
    {
        if (! $project->isSealed()) {
            throw new RuntimeException('This project has not been sealed yet.');
        }

        $bytes = $this->projects->sealedAudioBytes($project);
        if ($bytes === null) {
            throw new RuntimeException('The sealed audio is missing — re-seal the project.');
        }

        $project->loadMissing('voice', 'chunks.takes', 'chunks.voice');

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        // Name the audio to match the .zip and the folder it unzips to (e.g.
        // love-what-you-do-sealed-bbe2014e.mp3) rather than a bare "final.mp3".
        // This name flows into receipt.html and the manifest too.
        $finalName = $project->sealedBaseName().'.'.$ext;

        $chunks = $this->chunkRows($project);
        $manifest = $this->manifest($project, $finalName, $chunks);

        $receiptHtml = view('admin.studio.projects.receipt', [
            'project' => $project,
            'chunks' => $chunks,
            'manifest' => $manifest,
            'finalName' => $finalName,
        ])->render();

        $tmp = tempnam(sys_get_temp_dir(), 'receipt_').'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the receipt archive.');
            }
            $zip->addFromString($finalName, $bytes);
            $zip->addFromString('receipt.html', $receiptHtml);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Per-chunk provenance from the app's own records. source_audio_sha256 is the
     * hash of each chunk's SELECTED take audio — input provenance only, since
     * rebuild() trims/stitches/re-encodes before the final (see the manifest note).
     *
     * @return list<array<string, mixed>>
     */
    private function chunkRows(TtsProject $project): array
    {
        $disk = Storage::disk(config('tts.storage_disk'));
        $rows = [];

        foreach ($project->chunks as $chunk) {
            $selected = $chunk->takes->firstWhere('audio_path', $chunk->audio_path);

            $sha = null;
            if ($chunk->audio_path && $disk->exists($chunk->audio_path)) {
                $sha = hash('sha256', $disk->get($chunk->audio_path));
            }

            $badge = TtsChunk::asrBadgeFrom($chunk->asr_report);

            $rows[] = [
                'position' => $chunk->position,
                // The text the SELECTED take actually read — which can differ from
                // the chunk's current text if an earlier take was re-selected after
                // an edit. Falls back to the chunk text for legacy takes (no
                // snapshot) so the receipt always shows the words tied to the audio.
                'text' => $selected->text ?? $chunk->text,
                // The chunk's own voice if it overrides, else the inherited project
                // voice — a chunk can be voiced differently from the project.
                'voice' => $chunk->voice?->name ?? $project->voice?->name,
                'voice_inherited' => $chunk->voice_id === null,
                'seed' => $selected->seed ?? ($chunk->settings['seed'] ?? $project->seed),
                'source' => $selected->source ?? null,
                'attempts' => $chunk->takes->count(),
                'characters' => $chunk->characters,
                'asr_score' => $chunk->asr_score,
                'asr_summary' => $badge
                    ? trim($badge['text'].($badge['title'] !== '' ? ' ('.$badge['title'].')' : ''))
                    : null,
                'source_audio_sha256' => $sha,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return array<string, mixed>
     */
    private function manifest(TtsProject $project, string $finalName, array $chunks): array
    {
        return [
            'version' => 1,
            'kind' => 'mimic-seal-receipt',
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'voice' => $project->voice?->name,
                'seed' => $project->seed,
                'output_format' => $project->output_format,
                'model_id' => $project->model_id,
                'created_at' => optional($project->created_at)->toIso8601String(),
            ],
            'seal' => [
                'algorithm' => 'sha256',
                'final_sha256' => $project->final_sha256,
                'final_bytes' => $project->final_bytes,
                'mime_type' => $project->mime_type,
                'final_filename' => $finalName,
                'sealed_at' => optional($project->sealed_at)->toIso8601String(),
                'approver' => [
                    'id' => $project->sealed_by_id,
                    'name' => $project->sealed_by_name,
                    'email' => $project->sealed_by_email,
                ],
            ],
            'chunks' => $chunks,
            'note' => 'final_sha256 is the SHA-256 of the bytes in '.$finalName.'. Each chunk\'s '
                .'source_audio_sha256 covers its source take audio BEFORE trim/stitch/encode, so it '
                .'is input provenance and does not reconstruct the final file.',
        ];
    }
}
