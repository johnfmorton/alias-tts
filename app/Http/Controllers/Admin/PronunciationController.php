<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use App\Models\Voice;
use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\SpeechService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Manage the signed-in writer's pronunciation dictionary — the per-user lexicon
 * the pre-processor applies to their text before it reaches the voice. Strictly
 * private: every action is scoped to $request->user()->id, and a writer can
 * neither see nor edit another writer's terms.
 */
class PronunciationController extends Controller
{
    /** @var list<string> */
    private const CATEGORIES = ['initialism', 'acronym', 'tech_name', 'proper_noun', 'symbol_version', 'jargon'];

    /** @var list<string> */
    private const CONFIDENCES = ['high', 'medium', 'low'];

    public function __construct(
        private readonly PronunciationDictionary $dictionary,
        private readonly SpeechService $speech,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.pronunciations.index', [
            'entries' => $this->dictionary->owned($request->user()->id),
        ]);
    }

    public function create(): View
    {
        return view('admin.pronunciations.create', [
            'categories' => self::CATEGORIES,
            'confidences' => self::CONFIDENCES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $data = $this->validateEntry($request, $userId);

        $this->dictionary->upsertManual($userId, $data);

        return redirect()->route('admin.pronunciations.index')
            ->with('success', 'Pronunciation added.');
    }

    public function edit(Request $request, PronunciationEntry $entry): View
    {
        $this->authorizeEntry($request, $entry);

        return view('admin.pronunciations.edit', [
            'entry' => $entry,
            'categories' => self::CATEGORIES,
            'confidences' => self::CONFIDENCES,
        ]);
    }

    public function update(Request $request, PronunciationEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);
        $data = $this->validateEntry($request, $request->user()->id, $entry->id);

        $this->dictionary->updateEntry($entry, $data + ['approved' => $request->boolean('approved')]);

        return redirect()->route('admin.pronunciations.index')
            ->with('success', 'Pronunciation updated.');
    }

    public function destroy(Request $request, PronunciationEntry $entry): RedirectResponse
    {
        $this->authorizeEntry($request, $entry);
        $this->dictionary->forget($entry);

        return redirect()->route('admin.pronunciations.index')
            ->with('success', 'Pronunciation removed.');
    }

    /**
     * Speak a respelling out loud (AJAX) so the writer can audition it before
     * approving. Sits BELOW the pronunciation pre-processor, so the dictionary
     * can never re-substitute the very text being tested. With no voice given,
     * falls back to the writer's default (first in their picker order).
     */
    public function test(Request $request): Response|JsonResponse
    {
        // Explicit JSON 422s — admin paths don't auto-render validation as JSON
        // (only api/*, v1/* do), and this endpoint is only ever called via fetch.
        $validator = Validator::make($request->all(), [
            'phonetic' => ['required', 'string', 'max:255'],
            'voice' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $data = $validator->validated();

        $userId = $request->user()->id;
        $voice = filled($data['voice'] ?? null)
            ? Voice::resolveFor($data['voice'], $userId)
            : Voice::orderedFor($userId)->first();

        if (! $voice) {
            return response()->json(['message' => 'No voice available to test with.'], 422);
        }

        // Embed the respelling in a fixed carrier sentence. Respellings are
        // usually a word or two, and Chatterbox hard-fails (CUDA assert) on very
        // short inputs — the same hazard min_chunk_chars guards in the chunker.
        // The carrier also mirrors production, where a respelling is always
        // heard inside a sentence, never bare.
        $text = 'Your pronunciation will sound like this: '.trim($data['phonetic']);
        if (! preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        try {
            // Cached (no forceRefresh): re-testing an unchanged respelling
            // replays instantly instead of paying for another generation.
            $speech = $this->speech->synthesize(
                apiKey: ApiKey::dashboardFor($userId),
                voice: $voice,
                text: $text,
                settings: config('tts.default_voice_settings'),
                modelId: config('tts.default_model_id'),
                outputFormat: config('tts.default_output_format'),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Test failed: '.$e->getMessage()], 502);
        }

        return response($this->speech->audioBytes($speech), 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg');
    }

    /** A writer may only touch their own entries. */
    private function authorizeEntry(Request $request, PronunciationEntry $entry): void
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
    }

    /**
     * Validate a create/edit form. `term` is unique per user (matching the
     * `unique(user_id, term)` constraint) so editing into an existing term
     * returns a clean 422 rather than a DB error; pass $ignoreId on update.
     *
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request, int $userId, ?string $ignoreId = null): array
    {
        return $request->validate([
            'term' => [
                'required', 'string', 'max:255',
                Rule::unique('pronunciation_entries', 'term')->where('user_id', $userId)->ignore($ignoreId),
            ],
            'phonetic' => ['required', 'string', 'max:255'],
            'match_mode' => ['required', Rule::in(['case_sensitive', 'case_insensitive'])],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'confidence' => ['nullable', Rule::in(self::CONFIDENCES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
