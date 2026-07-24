<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ChecksCredit;
use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoiceRequest;
use App\Http\Requests\UpdateVoiceRequest;
use App\Models\ApiKey;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\SpeechService;
use App\Services\Tts\ModelCatalog;
use App\Services\VoiceClipService;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VoiceController extends Controller
{
    use ChecksCredit, ServesRangedAudio;

    public function __construct(
        private VoiceService $voices,
        private SpeechService $speechService,
        private VoiceClipService $voiceClips,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Everyone sees the shared built-ins plus their own voices, in their
        // own drag order. A SuperAdmin gets the same owner filter as Studio:
        // the list lands on their own voices, ?owner=<id> shows what that user
        // sees (theirs plus the shared built-ins), ?owner=all shows every
        // voice. Regular users ignore the param — it must never widen their view.
        $ownerId = null;
        if ($user->isSuperAdmin()) {
            $owner = (string) $request->query('owner', '');
            $ownerId = ctype_digit($owner) ? (int) $owner : ($owner === 'all' ? null : $user->id);
        }

        $query = match (true) {
            ! $user->isSuperAdmin() => Voice::orderedFor($user->id),
            $ownerId === null => Voice::orderedQuery($user->id)->with('user'),
            default => Voice::orderedQuery($user->id)->visibleTo($ownerId)->with('user'),
        };

        return view('admin.voices.index', [
            'voices' => $query->withCount('speeches')->get(),
            'showOwner' => $user->isSuperAdmin(),
            // The owner-filter dropdown's tail: users who actually own a voice,
            // minus the signed-in admin (rendered first, then "All owners").
            'owners' => $user->isSuperAdmin()
                ? User::whereIn('id', Voice::whereNotNull('user_id')->select('user_id'))
                    ->whereKeyNot($user->id)
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
            'ownerId' => $ownerId,
        ]);
    }

    /**
     * Save the signed-in user's personal voice order (the Voices page drag).
     * This order drives every voice dropdown, and its first entry is what the
     * New Project form pre-selects.
     */
    public function order(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ]);

        // Rank only voices this user may see — unknown or foreign ids are
        // silently dropped rather than ranked (SuperAdmins may rank any, since
        // their Voices page lists everyone's).
        $visible = ($user->isSuperAdmin() ? Voice::query() : Voice::visibleTo($user->id))
            ->whereIn('id', $data['order'])->pluck('id')->all();
        $ranked = array_values(array_intersect($data['order'], $visible));

        $now = now();
        DB::table('voice_orders')->upsert(
            collect($ranked)->map(fn (string $id, int $i) => [
                'user_id' => $user->id,
                'voice_id' => $id,
                'position' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['user_id', 'voice_id'],
            ['position', 'updated_at'],
        );

        return response()->json(['ok' => true, 'ranked' => count($ranked)]);
    }

    public function create(): View
    {
        return view('admin.voices.create');
    }

    public function store(StoreVoiceRequest $request): RedirectResponse
    {
        $file = $request->file('audio');
        $token = $request->input('clip_token');
        $warning = null;

        try {
            [$audioBytes, $ext, $warning] = $this->resolveClip($request, $file, $token);

            $voice = $this->voices->register(
                name: $request->input('name'),
                slug: $request->input('slug') ?: null,
                audioBytes: $audioBytes,
                ext: $ext,
                normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
                seed: $request->filled('seed') ? (int) $request->input('seed') : null,
                ownerId: $request->user()->id,
                model: $request->input('model') ?: null,
                presetVoice: $request->input('preset_voice') ?: null,
            );
        } catch (Throwable $e) {
            return redirect()->route('admin.voices.create')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        // Single-use: drop the staged clip only after a successful save.
        if ($token) {
            $this->voiceClips->discard($token);
        }

        // Land on the edit page: tuning lives there now, so the natural next
        // step after saving a clip is to hear it and dial in the defaults.
        return redirect()->route('admin.voices.edit', $voice)
            ->with('success', "Voice '{$voice->slug}' saved — tune it by ear below.")
            ->with($warning ? ['warning' => $warning] : []);
    }

    /**
     * Resolve the reference-clip bytes for a save. A prepared-clip token wins
     * (its exact previewed bytes, already decoded/enhanced); otherwise the raw
     * upload, cleaned up synchronously when the no-JS "clean up" box is on
     * (degrade-safe — a failed enhance keeps the original + a warning).
     *
     * @return array{0: string|null, 1: string|null, 2: string|null} [bytes, ext, warning]
     */
    private function resolveClip(Request $request, $file, ?string $token): array
    {
        if ($token) {
            $claimed = $this->voiceClips->claim($token, (string) $request->input('clip_choice'), $request->user()->id);

            return [$claimed['bytes'], $claimed['ext'], null];
        }

        $bytes = $file ? (string) file_get_contents($file->getRealPath()) : null;
        $ext = $file?->getClientOriginalExtension();

        if ($bytes !== null && $request->boolean('enhance') && config('tts.enhance.enabled')) {
            $result = $this->voiceClips->enhanceUploadedClip($bytes);

            return [$result['bytes'], 'wav', $result['error']];
        }

        return [$bytes, $ext, null];
    }

    public function edit(Request $request, Voice $voice): View
    {
        abort_unless($voice->isManagedBy($request->user()), 403);

        return view('admin.voices.edit', [
            'voice' => $voice,
            'presets' => TuningPreset::forUser($request->user()->id)->orderBy('name')->get(),
            'clipMeta' => $this->clipMeta($voice),
        ]);
    }

    /**
     * Stream the voice's stored reference clip so the Voice source step can play
     * it inline (`?download=1` saves it instead). Ranged — iOS Safari range-probes
     * any URL it loads into an <audio> and shows "Live Broadcast" without a 206.
     */
    public function clip(Request $request, Voice $voice): Response
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);
        abort_unless((bool) $voice->reference_audio_path, 404);

        $disk = Storage::disk(config('tts.storage_disk'));
        abort_unless($disk->exists($voice->reference_audio_path), 404);

        $ext = strtolower(pathinfo($voice->reference_audio_path, PATHINFO_EXTENSION)) ?: 'wav';
        $headers = $request->boolean('download')
            ? ['Content-Disposition' => 'attachment; filename="'.$voice->slug.'-reference.'.$ext.'"']
            : [];

        return $this->rangedAudio(
            (string) $disk->get($voice->reference_audio_path),
            self::CLIP_MIME[$ext] ?? 'application/octet-stream',
            $request,
            $headers,
        );
    }

    /** Content types for the containers a reference clip can be stored in. */
    private const CLIP_MIME = [
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
    ];

    /**
     * What the Voice source step can honestly say about the stored clip:
     * duration, channels and sample rate, all read straight from the WAV header
     * (null for a container we can't parse — the step then names the format and
     * size only). Cached against `updated_at`, which moves whenever the clip is
     * replaced, so the edit page doesn't re-fetch the clip from object storage
     * on every render.
     *
     * @return array{seconds: float|null, channels: int, sample_rate: int, ext: string, bytes: int}|null
     */
    private function clipMeta(Voice $voice): ?array
    {
        if (! $voice->reference_audio_path) {
            return null;
        }

        return Cache::remember(
            'voice-clip-meta:'.$voice->id.':'.($voice->updated_at?->getTimestamp() ?? 0),
            now()->addDay(),
            function () use ($voice) {
                $disk = Storage::disk(config('tts.storage_disk'));
                if (! $disk->exists($voice->reference_audio_path)) {
                    return null;
                }

                $bytes = (string) $disk->get($voice->reference_audio_path);
                $info = app(AudioConverter::class)->wavInfo($bytes);

                return [
                    'seconds' => $info['seconds'] ?? null,
                    'channels' => $info['channels'] ?? 0,
                    'sample_rate' => $info['sample_rate'] ?? 0,
                    'ext' => strtolower(pathinfo($voice->reference_audio_path, PATHINFO_EXTENSION)) ?: 'wav',
                    'bytes' => strlen($bytes),
                ];
            },
        );
    }

    public function update(UpdateVoiceRequest $request, Voice $voice): RedirectResponse
    {
        abort_unless($voice->isManagedBy($request->user()), 403);

        $file = $request->file('audio');
        $token = $request->input('clip_token');
        $warning = null;
        $transcriptBefore = trim((string) (((array) $voice->settings)['reference_text'] ?? ''));

        try {
            [$audioBytes, $ext, $warning] = $this->resolveClip($request, $file, $token);

            $voice = $this->voices->update(
                voice: $voice,
                name: $request->input('name'),
                slug: $request->input('slug'),
                audioBytes: $audioBytes,
                ext: $ext,
                normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
                seed: $request->filled('seed') ? (int) $request->input('seed') : null,
                exaggeration: $request->filled('exaggeration') ? (float) $request->input('exaggeration') : null,
                cfgWeight: $request->filled('cfg_weight') ? (float) $request->input('cfg_weight') : null,
                temperature: $request->filled('temperature') ? (float) $request->input('temperature') : null,
                model: $request->input('model') ?: null,
                presetVoice: $request->input('preset_voice') ?: null,
                topP: $request->filled('top_p') ? (float) $request->input('top_p') : null,
                topK: $request->filled('top_k') ? (int) $request->input('top_k') : null,
                repetitionPenalty: $request->filled('repetition_penalty') ? (float) $request->input('repetition_penalty') : null,
                language: $request->input('language') ?: null,
                styleInstruction: $request->input('style_instruction') ?: null,
                referenceText: $request->input('reference_text') ?: null,
            );
        } catch (Throwable $e) {
            return redirect()->route('admin.voices.edit', $voice)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($token) {
            $this->voiceClips->discard($token);
        }

        $warning = $this->appendStaleTranscriptWarning($warning, $voice, $audioBytes !== null, $transcriptBefore);

        return redirect()->route('admin.voices.index')
            ->with('success', "Voice '{$voice->slug}' updated.")
            ->with($warning ? ['warning' => $warning] : []);
    }

    /**
     * A replaced clip can leave the voice's transcript describing the take
     * that's gone. Qwen's clone mode sends `reference_text` ALONG with the
     * clip, so a stale one asks the model to hear words that aren't in the
     * audio — and unlike the clip itself, nothing else will catch it.
     *
     * We don't rewrite it: the transcript is the user's text (typed, or
     * ASR-filled and then kept), and the new clip may well say the same
     * words. So say so and let them decide. Silent when they updated the
     * transcript in the same save, or when it was empty (save-time
     * auto-transcription fills that case from the new clip).
     */
    private function appendStaleTranscriptWarning(?string $warning, Voice $voice, bool $clipReplaced, string $transcriptBefore): ?string
    {
        if (! $clipReplaced || $transcriptBefore === '' || ! ModelCatalog::acceptsReferenceText(ModelCatalog::forVoice($voice))) {
            return $warning;
        }

        if (trim((string) (((array) $voice->settings)['reference_text'] ?? '')) !== $transcriptBefore) {
            return $warning; // Changed in this same save — already in step.
        }

        $note = "The clip transcript still describes the old clip. Qwen reads it along with the audio, so update or clear it on {$voice->name}'s edit page.";

        return $warning ? $warning.' '.$note : $note;
    }

    /**
     * Generate a short preview through the real backend (spends provider credit).
     * Returns raw audio bytes for the dashboard's inline player.
     */
    public function test(Request $request, Voice $voice): Response
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);

        if ($error = $this->creditError($request->user())) {
            return $error;
        }

        $apiKey = ApiKey::dashboardFor($request->user()->id);

        try {
            $speech = $this->speechService->synthesize(
                apiKey: $apiKey,
                voice: $voice,
                text: "This is a preview of the {$voice->name} voice on my self hosted text to speech service.",
                settings: config('tts.default_voice_settings'),
                modelId: config('tts.default_model_id'),
                outputFormat: config('tts.default_output_format'),
                seed: null, // resolves to the voice's default seed if set
                // Always generate live: a "Test" must reflect the voice's CURRENT
                // reference/settings, never replay a cached preview. Without this a
                // stale cached preview (e.g. one captured while the voice briefly
                // had a different reference) would play back indefinitely.
                forceRefresh: true,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Preview failed: '.$e->getMessage()], 502);
        }

        return response($this->speechService->audioBytes($speech), 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg');
    }

    /**
     * Clone a visible voice (typically a shared built-in) into one the signed-in
     * user owns and can rename/retune freely. Lands on the copy's edit page.
     */
    public function duplicate(Request $request, Voice $voice): RedirectResponse
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);

        $copy = $this->voices->duplicate($voice, $request->user()->id);

        return redirect()->route('admin.voices.edit', $copy)
            ->with('success', "Voice '{$copy->slug}' created — it's yours to rename and tune.");
    }

    public function export(Request $request, Voice $voice): Response
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);

        return response($this->voices->export($voice), 200)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="'.$voice->slug.'.alias-voice.zip"');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            // Cap shared with tts:doctor's "Upload size limit" check (config).
            'archive' => ['required', 'file', 'mimes:zip', 'max:'.((int) config('tts.max_upload_size_mb', 50) * 1024)],
        ]);

        try {
            $voice = $this->voices->import(
                (string) file_get_contents($request->file('archive')->getRealPath()),
                ownerId: $request->user()->id,
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('admin.voices.index')->with('error', 'Import failed: '.$e->getMessage());
        }

        return redirect()->route('admin.voices.edit', $voice)
            ->with('success', "Voice '{$voice->slug}' imported — clip and tuning restored. Rename or retune it here.");
    }

    public function destroy(Request $request, Voice $voice): RedirectResponse
    {
        abort_unless($voice->isManagedBy($request->user()), 403);

        if ($voice->isBuiltin()) {
            return redirect()->route('admin.voices.index')
                ->with('error', 'The built-in default voice can’t be deleted.');
        }

        $this->voices->delete($voice);

        return redirect()->route('admin.voices.index')
            ->with('success', 'Voice deleted.');
    }
}
