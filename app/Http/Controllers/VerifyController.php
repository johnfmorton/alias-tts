<?php

namespace App\Http\Controllers;

use App\Models\TtsProject;
use App\Services\ProjectExportService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public "is this the approved final?" verifier. The primary path is LOCAL: the
 * page hashes the visitor's file in the browser (Web Crypto) and redirects to
 * `?sha=<hash>&local=1` carrying only the 64-char fingerprint — the file is never
 * uploaded, so no large file can exhaust the server. show() then looks up a
 * sealed project whose final_sha256 matches and renders the verdict.
 *
 * A `?sha=` link WITHOUT `local=1` opens the authoritative record for a known
 * fingerprint (what a receipt links to) and re-hashes our own frozen snapshot to
 * prove the stored copy is intact.
 *
 * check() is an OPT-IN upload fallback for non-secure-context browsers (no
 * crypto.subtle): the server SHA-256s the uploaded bytes instead. It is gated by
 * tts.verify_allow_upload — OFF by default, in which case the POST 404s and the
 * page renders no upload form (local hashing or the receipt only).
 *
 * Verification reuses {@see ProjectExportService::receiptData()} so the live page
 * shows the same seal panel + per-chunk provenance table (incl. each take's text)
 * as the receipt.
 */
class VerifyController extends Controller
{
    public function __construct(
        private readonly ProjectExportService $export,
        private readonly ProjectService $projects,
    ) {}

    /** GET /verify — the verify form, or a result/record view for a `?sha=` fingerprint. */
    public function show(Request $request): View
    {
        $sha = $this->normalizeSha($request->query('sha'));

        if ($sha === null) {
            return view('verify.show', $this->base('idle'));
        }

        $project = $this->lookup($sha);

        // `local=1` means the browser hashed the visitor's OWN file with Web
        // Crypto and redirected here carrying only the fingerprint — the file
        // was never uploaded (so no large upload can choke the server). A hash
        // of their file matching a sealed record IS proof, so render a verdict
        // (match / no match), not the bare "a record exists" lookup below.
        if ($request->boolean('local')) {
            $state = $project === null ? 'nomatch' : 'match';

            return view('verify.show', $this->base($state, [
                'uploadedHash' => $sha,
                'hashedLocally' => true,
            ] + ($project ? $this->provenance($project) : [])));
        }

        if ($project === null) {
            return view('verify.show', $this->base('record_missing', ['querySha' => $sha]));
        }

        return view('verify.show', $this->base('record', [
            'querySha' => $sha,
        ] + $this->provenance($project)));
    }

    /** POST /verify — hash the uploaded bytes server-side and match a sealed record. */
    public function check(Request $request): View
    {
        // Uploads are an opt-in fallback. When disabled the endpoint does not
        // exist at all — no upload attack surface (the page hashes locally).
        abort_unless($this->allowUpload(), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$this->maxUploadKb()],
        ], [
            'file.max' => 'That file is larger than the verifier accepts. Verify it from the receipt instead.',
            'file.required' => 'Choose an audio file to verify.',
        ]);

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $name = $file->getClientOriginalName();

        $project = $this->lookup($hash);
        $state = $project === null ? 'nomatch' : 'match';

        return view('verify.show', $this->base($state, [
            'uploadedHash' => $hash,
            'uploadedName' => $name,
        ] + ($project ? $this->provenance($project) : [])));
    }

    /**
     * Default view payload; callers override the parts a given state populates.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function base(string $state, array $overrides = []): array
    {
        // array_merge so $overrides WIN on shared keys — the `+` union operator
        // keeps the left (default) value, which would blank out project/chunks/….
        return array_merge([
            'state' => $state,           // idle | match | nomatch | record | record_missing
            'querySha' => null,
            'uploadedHash' => null,
            'uploadedName' => null,
            'project' => null,
            'chunks' => [],
            'engines' => [],
            'finalName' => null,
            'snapshotVerified' => null,  // did our stored snapshot still hash to final_sha256?
            'hashedLocally' => false,    // did the browser hash the file (no upload)?
            'allowUpload' => $this->allowUpload(),
            'maxUploadKb' => $this->maxUploadKb(),
            'maxUploadMb' => (int) round($this->maxUploadKb() / 1024),
        ], $overrides);
    }

    /**
     * The seal panel + per-chunk provenance for a matched project, plus a server
     * re-hash of our frozen snapshot (integrity of the record itself).
     *
     * @return array<string, mixed>
     */
    private function provenance(TtsProject $project): array
    {
        $data = $this->export->receiptData($project);

        return [
            'project' => $data['project'],
            'chunks' => $data['chunks'],
            'engines' => $data['engines'],
            'finalName' => $data['finalName'],
            'snapshotVerified' => $this->snapshotVerified($project),
        ];
    }

    private function lookup(string $sha): ?TtsProject
    {
        return TtsProject::query()
            ->whereNotNull('sealed_at')
            ->where('final_sha256', $sha)
            ->first();
    }

    /** Re-hash the frozen snapshot on disk; null when it is missing (unknown). */
    private function snapshotVerified(TtsProject $project): ?bool
    {
        $bytes = $this->projects->sealedAudioBytes($project);
        if ($bytes === null) {
            return null;
        }

        return hash('sha256', $bytes) === $project->final_sha256;
    }

    /** Lowercase a candidate hash and accept it only if it's a full SHA-256 hex. */
    private function normalizeSha(mixed $raw): ?string
    {
        $raw = strtolower(trim((string) $raw));

        return preg_match('/^[0-9a-f]{64}$/', $raw) === 1 ? $raw : null;
    }

    private function maxUploadKb(): int
    {
        return (int) config('tts.verify_max_upload_kb', 204800);
    }

    private function allowUpload(): bool
    {
        return (bool) config('tts.verify_allow_upload', false);
    }
}
