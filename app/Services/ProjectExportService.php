<?php

namespace App\Services;

use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds the "approved final" receipt: a portable .zip containing the frozen
 * approved audio, a human-readable provenance receipt (seal panel + per-chunk
 * table, with each take's text), and a machine-readable manifest. The receipt is
 * a self-contained record that links out to the hosted, server-side verifier at
 * /verify — upload the audio there and the server re-hashes it and matches it
 * against the sealed approval.
 *
 * Modeled on {@see VoiceService::export()}. The load-bearing value is the byte
 * SHA-256 of the SEALED snapshot (computed at seal time, persisted on the
 * project); the export ships those exact bytes so the printed hash always matches
 * the file in the zip. The receiptData() view-data is shared with the /verify page.
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

        $data = $this->receiptData($project);

        return $this->zipUp([
            $data['finalName'] => $bytes,
            'receipt.html' => view('admin.studio.projects.receipt', $data)->render(),
            'manifest.json' => json_encode($data['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * The receipt zip plus a clips/ directory holding EVERY saved take in the
     * project — the local record a user downloads before deleting a project
     * from the site. The manifest gains a `clips` listing (one row per clip,
     * with its SHA-256) so the alternates are provable, not just present.
     */
    public function buildArchiveZip(TtsProject $project): string
    {
        if (! $project->isSealed()) {
            throw new RuntimeException('Approve the project as final first — the archive packages the approved audio, its receipt, and every clip.');
        }

        $bytes = $this->projects->sealedAudioBytes($project);
        if ($bytes === null) {
            throw new RuntimeException('The sealed audio is missing — re-seal the project.');
        }

        $data = $this->receiptData($project);
        [$clips, $clipRows] = $this->clipEntries($project);

        $manifest = $data['manifest'];
        $manifest['clips'] = $clipRows;
        $manifest['note'] .= ' The clips/ directory archives every saved take still in the project ("selected" marks '
            .'the one in the final); a take whose audio file has gone missing is listed with "file": null.';

        return $this->zipUp(array_merge([
            $data['finalName'] => $bytes,
            'receipt.html' => view('admin.studio.projects.receipt', $data)->render(),
            'manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ], $clips));
    }

    /**
     * Every saved clip for the archive: [zip entries (name => bytes), manifest
     * rows]. One clip per distinct audio file — a legacy in-place set is many
     * take rows sharing one file — numbered oldest-first within each chunk, the
     * selected one suffixed `-selected`. A take whose file is gone from storage
     * is still listed (file/sha256 null) so the archive never silently
     * under-reports what existed.
     *
     * @return array{0: array<string, string>, 1: list<array<string, mixed>>}
     */
    private function clipEntries(TtsProject $project): array
    {
        $disk = Storage::disk(config('tts.storage_disk'));
        $files = [];
        $rows = [];

        foreach ($project->chunks as $chunk) {
            // Oldest first (the relation is newest-first) so clip numbers are stable.
            $takes = $chunk->takes->reverse()->unique('audio_path')->values();

            // A pre-takes-era chunk has audio but no take row for it — synthesize
            // one so its selected clip isn't left out (duplicate() does the same).
            if ($chunk->audio_path !== null && ! $takes->contains('audio_path', $chunk->audio_path)) {
                $takes->push(new TtsChunkTake([
                    'audio_path' => $chunk->audio_path,
                    'text' => $chunk->text,
                    'seed' => $chunk->settings['seed'] ?? $project->seed,
                ]));
            }

            foreach ($takes as $i => $take) {
                $selected = $take->audio_path === $chunk->audio_path;
                $bytes = $take->audio_path && $disk->exists($take->audio_path) ? $disk->get($take->audio_path) : null;

                $name = null;
                if ($bytes !== null) {
                    $ext = pathinfo((string) $take->audio_path, PATHINFO_EXTENSION) ?: 'wav';
                    $name = sprintf('clips/chunk-%02d/take-%02d%s.%s', $chunk->position + 1, $i + 1, $selected ? '-selected' : '', $ext);
                    $files[$name] = $bytes;
                }

                $rows[] = [
                    'file' => $name,
                    'chunk_position' => $chunk->position,
                    'selected' => $selected,
                    'source' => $take->source,
                    'seed' => $take->seed,
                    'duration_ms' => $take->duration_ms,
                    'text' => $take->text,
                    'sha256' => $bytes !== null ? hash('sha256', $bytes) : null,
                ];
            }
        }

        return [$files, $rows];
    }

    /** Zip the given name => bytes entries and return the archive's bytes. */
    private function zipUp(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'receipt_').'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the archive.');
            }
            foreach ($entries as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The provenance view-data for a sealed project — shared by the receipt .zip
     * (offline record) and the hosted /verify result so both render the identical
     * seal panel + per-chunk provenance table (including the selected take's text).
     *
     * @return array{project: TtsProject, chunks: list<array<string, mixed>>, manifest: array<string, mixed>, finalName: string}
     */
    public function receiptData(TtsProject $project): array
    {
        $project->loadMissing('voice', 'chunks.takes', 'chunks.voice');

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        // Name the audio to match the .zip and the folder it unzips to (e.g.
        // love-what-you-do-sealed-bbe2014e.mp3) rather than a bare "final.mp3".
        // This name flows into receipt.html, the manifest, and the verify page.
        $finalName = $project->sealedBaseName().'.'.$ext;

        $chunks = $this->chunkRows($project);

        return [
            'project' => $project,
            'chunks' => $chunks,
            'manifest' => $this->manifest($project, $finalName, $chunks),
            'finalName' => $finalName,
        ];
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
                // Listed even when skipped — the receipt shows the whole project,
                // with skipped chunks labeled rather than silently omitted.
                'skipped' => (bool) $chunk->skipped,
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
            'kind' => 'alias-seal-receipt',
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
                .'is input provenance and does not reconstruct the final file. Chunks marked '
                .'"skipped": true are listed for provenance but are not part of the final audio.',
        ];
    }
}
