<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoiceRequest;
use App\Models\ApiKey;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\VoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VoiceController extends Controller
{
    public function __construct(
        private VoiceService $voices,
        private SpeechService $speechService,
    ) {}

    public function index(): View
    {
        $voices = Voice::withCount('speeches')->orderBy('name')->get();

        return view('admin.voices.index', compact('voices'));
    }

    public function create(): View
    {
        return view('admin.voices.create');
    }

    public function store(StoreVoiceRequest $request): RedirectResponse
    {
        $file = $request->file('audio');

        $voice = $this->voices->register(
            name: $request->input('name'),
            slug: $request->input('slug') ?: null,
            audioBytes: (string) file_get_contents($file->getRealPath()),
            ext: $file->getClientOriginalExtension(),
            normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : null,
        );

        return redirect()->route('admin.voices.index')
            ->with('success', "Voice '{$voice->slug}' saved.");
    }

    /**
     * Generate a short preview through the real backend (spends provider credit).
     * Returns raw audio bytes for the dashboard's inline player.
     */
    public function test(Voice $voice): Response
    {
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
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Preview failed: '.$e->getMessage()], 502);
        }

        return response($this->speechService->audioBytes($speech), 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg');
    }

    public function destroy(Voice $voice): RedirectResponse
    {
        $this->voices->delete($voice);

        return redirect()->route('admin.voices.index')
            ->with('success', 'Voice deleted.');
    }
}
