<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVoiceRequest;
use App\Http\Requests\UpdateVoiceRequest;
use App\Models\ApiKey;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\VoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            audioBytes: $file ? (string) file_get_contents($file->getRealPath()) : null,
            ext: $file?->getClientOriginalExtension(),
            normalize: (bool) config('tts.normalize_reference') && ! $request->boolean('raw'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : null,
            stability: $request->filled('stability') ? (float) $request->input('stability') : null,
            style: $request->filled('style') ? (float) $request->input('style') : null,
        );

        return redirect()->route('admin.voices.index')
            ->with('success', "Voice '{$voice->slug}' saved.");
    }

    public function edit(Voice $voice): View
    {
        return view('admin.voices.edit', compact('voice'));
    }

    public function update(UpdateVoiceRequest $request, Voice $voice): RedirectResponse
    {
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

    public function export(Voice $voice): Response
    {
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
            $voice = $this->voices->import((string) file_get_contents($request->file('archive')->getRealPath()));
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('admin.voices.index')->with('error', 'Import failed: '.$e->getMessage());
        }

        return redirect()->route('admin.voices.index')->with('success', "Voice '{$voice->slug}' imported.");
    }

    public function destroy(Voice $voice): RedirectResponse
    {
        if ($voice->isDefault()) {
            return redirect()->route('admin.voices.index')
                ->with('error', 'The built-in default voice can’t be deleted.');
        }

        $this->voices->delete($voice);

        return redirect()->route('admin.voices.index')
            ->with('success', 'Voice deleted.');
    }
}
