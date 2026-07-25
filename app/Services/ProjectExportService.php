<?php

namespace App\Services;

use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Services\Tts\ModelCatalog;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Builds the "approved final" receipt: a portable .zip containing the frozen
 * approved audio, a human-readable provenance receipt (seal panel + per-chunk
 * table, with each take's text), and a machine-readable manifest. The receipt is
 * a self-contained record that links out to the hosted, server-side verifier at
 * /verify — upload the audio there and the server re-hashes it and matches it
 * against the sealed approval.
 *
 * The zips are STREAMED (ZipStream writing to php://output via streamDownload):
 * a big project's archive is hundreds of clip downloads, which used to run in
 * full — and buffer in full — before the first response byte, the recipe for a
 * gateway 504 and a memory spike. Streaming sends the sealed audio immediately
 * and keeps bytes flowing as each clip is fetched, so the gateway timer resets
 * and no more than one clip is ever held in memory.
 *
 * The load-bearing value is the byte SHA-256 of the SEALED snapshot (computed at
 * seal time, persisted on the project); the export ships those exact bytes so the
 * printed hash always matches the file in the zip. The receiptData() view-data is
 * shared with the /verify page.
 */
class ProjectExportService
{
    public function __construct(private readonly ProjectService $projects) {}

    /**
     * The cheap preflight for both downloads, run BEFORE the response starts
     * streaming (a refusal must be a JSON 422, not a truncated zip).
     */
    public function assertSealed(TtsProject $project): void
    {
        if (! $project->isSealed()) {
            throw new RuntimeException('This project has not been sealed yet.');
        }

        if (! $project->sealed_audio_path
            || ! Storage::disk(config('tts.storage_disk'))->exists($project->sealed_audio_path)) {
            throw new RuntimeException('The sealed audio is missing — re-seal the project.');
        }
    }

    /**
     * Stream the receipt .zip to php://output. Entry order is deliberate: the
     * sealed audio goes out first so bytes flow immediately; the provenance
     * pass (which may still need to hash legacy clips) runs after.
     */
    public function streamReceiptZip(TtsProject $project): void
    {
        $bytes = $this->projects->sealedAudioBytes($project);
        if ($bytes === null) {
            throw new RuntimeException('The sealed audio is missing — re-seal the project.');
        }

        $zip = $this->openZip();
        $this->addAudio($zip, $this->finalName($project), $bytes);
        unset($bytes);

        $data = $this->receiptData($project);
        $zip->addFile(fileName: 'receipt.html', data: view('admin.studio.projects.receipt', $data)->render());
        $zip->addFile(fileName: 'manifest.json', data: json_encode($data['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->finish();
    }

    /**
     * Stream the everything-zip: the receipt package plus a clips/ directory
     * holding EVERY saved take in the project — the local record a user
     * downloads before deleting a project from the site. The manifest gains a
     * `clips` listing (one row per clip, with its SHA-256) so the alternates
     * are provable, not just present.
     *
     * Clips stream one at a time as they download; their hashes are collected
     * along the way and reused for the receipt's per-chunk provenance, so no
     * clip is fetched twice.
     */
    public function streamArchiveZip(TtsProject $project): void
    {
        $bytes = $this->projects->sealedAudioBytes($project);
        if ($bytes === null) {
            throw new RuntimeException('The sealed audio is missing — re-seal the project.');
        }

        $project->loadMissing('voice', 'chunks.takes.voice', 'chunks.voice');

        $zip = $this->openZip();
        $this->addAudio($zip, $this->finalName($project), $bytes);
        unset($bytes);

        [$clipRows, $sourceShas] = $this->streamClips($zip, $project);

        $data = $this->receiptData($project, $sourceShas);

        $manifest = $data['manifest'];
        $manifest['clips'] = $clipRows;
        $manifest['note'] .= ' The clips/ directory archives every saved take still in the project ("selected" marks '
            .'the one in the final); a take whose audio file has gone missing is listed with "file": null.';

        $zip->addFile(fileName: 'receipt.html', data: view('admin.studio.projects.receipt', $data)->render());
        $zip->addFile(fileName: 'manifest.json', data: json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->finish();
    }

    /**
     * Stream every saved clip into clips/ and return [manifest rows, chunk id
     * => selected clip sha]. One clip per distinct audio file — a legacy
     * in-place set is many take rows sharing one file — numbered oldest-first
     * within each chunk, the selected one suffixed `-selected`. A take whose
     * file is gone from storage is still listed (file/sha256 null) so the
     * archive never silently under-reports what existed.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function streamClips(ZipStream $zip, TtsProject $project): array
    {
        $disk = Storage::disk(config('tts.storage_disk'));
        $rows = [];
        $sourceShas = [];

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
                $sha = null;
                if ($bytes !== null) {
                    $ext = pathinfo((string) $take->audio_path, PATHINFO_EXTENSION) ?: 'wav';
                    $name = sprintf('clips/chunk-%02d/take-%02d%s.%s', $chunk->position + 1, $i + 1, $selected ? '-selected' : '', $ext);
                    $sha = hash('sha256', $bytes);
                    $this->addAudio($zip, $name, $bytes);
                    unset($bytes);

                    if ($selected) {
                        $sourceShas[$chunk->id] = $sha;
                    }
                }

                $rows[] = [
                    'file' => $name,
                    'chunk_position' => $chunk->position,
                    'selected' => $selected,
                    'source' => $take->source,
                    // The engine this take rendered on — the key frozen at
                    // record time, derived from its snapshotted voice (then
                    // chunk/project voice) only for legacy takes.
                    'model' => $take->model
                        ?? ModelCatalog::forVoice($take->voice ?? $chunk->voice ?? $project->voice),
                    'seed' => $take->seed,
                    'duration_ms' => $take->duration_ms,
                    'text' => $take->text,
                    'sha256' => $sha,
                ];
            }
        }

        return [$rows, $sourceShas];
    }

    /**
     * A ZipStream writing to php://output, headers left to streamDownload().
     * flushOutput pushes each entry through PHP's output buffer as it lands —
     * the gateway timer feeds on those bytes — but its ob_flush() would leak
     * the capture buffer TestResponse::streamedContent() reads, so it stays
     * off under PHPUnit.
     */
    private function openZip(): ZipStream
    {
        return new ZipStream(sendHttpHeaders: false, flushOutput: ! app()->runningUnitTests());
    }

    /** Audio bytes are already compressed (MP3) or raw speed-sensitive (WAV) — STORE, don't deflate. */
    private function addAudio(ZipStream $zip, string $name, string $bytes): void
    {
        $zip->addFile(fileName: $name, data: $bytes, compressionMethod: CompressionMethod::STORE);
    }

    /**
     * The in-zip audio name, matching the .zip and the folder it unzips to
     * (e.g. love-what-you-do-sealed-bbe2014e.mp3) rather than a bare
     * "final.mp3". This name flows into receipt.html, the manifest, and the
     * verify page.
     */
    private function finalName(TtsProject $project): string
    {
        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';

        return $project->sealedBaseName().'.'.$ext;
    }

    /**
     * The provenance view-data for a sealed project — shared by the receipt .zip
     * (offline record) and the hosted /verify result so both render the identical
     * seal panel + per-chunk provenance table (including the selected take's text).
     *
     * @param  array<string, string>  $sourceShas  chunk id => already-computed sha of its
     *                                             selected audio (the archive stream
     *                                             collects these while zipping clips)
     * @return array{project: TtsProject, chunks: list<array<string, mixed>>, engines: list<string>, manifest: array<string, mixed>, finalName: string}
     */
    public function receiptData(TtsProject $project, array $sourceShas = []): array
    {
        $project->loadMissing('voice', 'chunks.takes.voice', 'chunks.voice');

        $finalName = $this->finalName($project);

        $chunks = $this->chunkRows($project, $sourceShas);

        return [
            'project' => $project,
            'chunks' => $chunks,
            // Distinct engine labels across the chunks, in chunk order — the
            // seal panel's "Engine" line ("Chatterbox Turbo", or several when
            // chunks were rendered on different engines).
            'engines' => array_values(array_unique(array_column($chunks, 'model_label'))),
            'manifest' => $this->manifest($project, $finalName, $chunks),
            'finalName' => $finalName,
        ];
    }

    /**
     * Per-chunk provenance from the app's own records. source_audio_sha256 is the
     * hash of each chunk's SELECTED take audio — input provenance only, since
     * rebuild() trims/stitches/re-encodes before the final (see the manifest note).
     *
     * The hash comes from the caller's already-computed set, else the take's
     * stored audio_sha256 (stamped at record time); only a legacy take from
     * before that column falls back to downloading and hashing the clip —
     * the O(chunks) fetch pass that used to make receipts and /verify crawl.
     *
     * @param  array<string, string>  $sourceShas
     * @return list<array<string, mixed>>
     */
    private function chunkRows(TtsProject $project, array $sourceShas = []): array
    {
        $disk = Storage::disk(config('tts.storage_disk'));
        $rows = [];

        foreach ($project->chunks as $chunk) {
            $selected = $chunk->takes->firstWhere('audio_path', $chunk->audio_path);

            $sha = $sourceShas[$chunk->id] ?? $selected?->audio_sha256;
            if ($sha === null && $chunk->audio_path && $disk->exists($chunk->audio_path)) {
                $sha = hash('sha256', $disk->get($chunk->audio_path));
            }

            $badge = TtsChunk::asrBadgeFrom($chunk->asr_report);

            // The engine that rendered the selected audio: the key frozen on the
            // take at record time (a voice's engine can be edited later, which
            // must not rewrite old receipts). Takes predating that column fall
            // back to deriving it from the take's snapshotted voice, then the
            // chunk's, then the project's — best-effort input provenance.
            $model = $selected?->model
                ?? ModelCatalog::forVoice($selected?->voice ?? $chunk->voice ?? $project->voice);

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
                // Catalog key (stable identifier) for the manifest, label for the
                // human-readable receipt/verify table. A key retired from the
                // catalog prints as itself rather than borrowing the default
                // engine's label — the receipt must not misname what rendered.
                'model' => $model,
                'model_label' => ModelCatalog::isKnown($model) ? ModelCatalog::label($model) : $model,
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
                // Distinct TTS engines (tts.models catalog keys) that rendered
                // the chunks below — each chunk row names its own in `model`.
                'models' => array_values(array_unique(array_column($chunks, 'model'))),
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
