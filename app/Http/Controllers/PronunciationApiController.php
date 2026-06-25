<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only sync surface for the pronunciation dictionary. The Craft Bespoken
 * plugin polls this to keep its local find-and-replace lexicon current — the
 * actual substitution stays in the plugin (upstream of any TTS backend, so it
 * works even with a black-box engine). This service owns the canonical data.
 *
 *   GET /v1/pronunciations
 *   header: xi-api-key: <key>
 *
 * Scope: API keys are not user-bound yet, so this returns every approved entry
 * (the single-admin lexicon). TODO: scope to the key's owner once keys gain one.
 */
class PronunciationApiController extends Controller
{
    public function index(Request $request): Response
    {
        // Belt-and-suspenders: ValidateApiKey already enforces this.
        if (! $request->attributes->get('api_key') instanceof ApiKey) {
            return response()->json(['message' => 'An API key is required.'], 401);
        }

        $entries = PronunciationEntry::query()
            ->where('approved', true)
            ->orderBy('term')
            ->get();

        $etag = '"'.md5($entries->count().':'.(optional($entries->max('updated_at'))->timestamp ?? '0')).'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json([
            'version' => 1,
            'entries' => $entries->map(fn (PronunciationEntry $e) => [
                'term' => $e->term,
                'phonetic' => $e->phonetic,
                'match' => $e->match_mode,
                'category' => $e->category,
                'source' => $e->source,
                'approved' => $e->approved,
                'added' => optional($e->updated_at)->toDateString(),
            ])->all(),
        ])->header('ETag', $etag);
    }
}
