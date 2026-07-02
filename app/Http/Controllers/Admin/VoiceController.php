<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoiceRequest;
use App\Http\Requests\UpdateVoiceRequest;
use App\Models\ApiKey;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VoiceController extends Controller
{
    public function __construct(
        private VoiceService $voices,
        private SpeechService $speechService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Everyone sees the shared built-ins plus their own voices, in their
        // own drag order; a SuperAdmin sees every user's voices, owner-labeled.
        $query = $user->isSuperAdmin()
            ? Voice::orderedQuery($user->id)->with('user')
            : Voice::orderedFor($user->id);

        return view('admin.voices.index', [
            'voices' => $query->withCount('speeches')->get(),
            'showOwner' => $user->isSuperAdmin(),
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

        try {
            $voice = $this->voices->register(
                name: $request->input('name'),
                slug: $request->input('slug') ?: null,
                audioBytes: $file ? (string) file_get_contents($file->getRealPath()) : null,
                ext: $file?->getClientOriginalExtension(),
                normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
                seed: $request->filled('seed') ? (int) $request->input('seed') : null,
                stability: $request->filled('stability') ? (float) $request->input('stability') : null,
                style: $request->filled('style') ? (float) $request->input('style') : null,
                ownerId: $request->user()->id,
            );
        } catch (Throwable $e) {
            return redirect()->route('admin.voices.create')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()->route('admin.voices.index')
            ->with('success', "Voice '{$voice->slug}' saved.");
    }

    public function edit(Request $request, Voice $voice): View
    {
        abort_unless($voice->isManagedBy($request->user()), 403);

        return view('admin.voices.edit', compact('voice'));
    }

    public function update(UpdateVoiceRequest $request, Voice $voice): RedirectResponse
    {
        abort_unless($voice->isManagedBy($request->user()), 403);

        $file = $request->file('audio');

        $voice = $this->voices->update(
            voice: $voice,
            name: $request->input('name'),
            slug: $request->input('slug'),
            audioBytes: $file ? (string) file_get_contents($file->getRealPath()) : null,
            ext: $file?->getClientOriginalExtension(),
            normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : null,
            stability: $request->filled('stability') ? (float) $request->input('stability') : null,
            style: $request->filled('style') ? (float) $request->input('style') : null,
        );

        return redirect()->route('admin.voices.index')
            ->with('success', "Voice '{$voice->slug}' updated.");
    }

    /**
     * Generate a short preview through the real backend (spends provider credit).
     * Returns raw audio bytes for the dashboard's inline player.
     */
    public function test(Request $request, Voice $voice): Response
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);

        $apiKey = ApiKey::firstWhere('name', 'dashboard') ?? ApiKey::generate('dashboard');

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

    public function export(Request $request, Voice $voice): Response
    {
        abort_unless($voice->isVisibleTo($request->user()), 404);

        return response($this->voices->export($voice), 200)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="'.$voice->slug.'.bespoken-voice.zip"');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'archive' => ['required', 'file', 'mimes:zip', 'max:51200'], // 50 MB
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

        return redirect()->route('admin.voices.index')->with('success', "Voice '{$voice->slug}' imported.");
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
