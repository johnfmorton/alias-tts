<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PronunciationEntry;
use App\Services\Pronunciation\PronunciationDictionary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

    public function __construct(private readonly PronunciationDictionary $dictionary) {}

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
