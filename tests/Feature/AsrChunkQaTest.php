<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Models\TtsChunk;
use App\Models\User;
use App\Models\Voice;
use App\Services\Health\HealthReport;
use App\Services\ProjectService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wiring tests for the optional ASR transcript QA. The Whisper sidecar is faked
 * via Http::fake, so these exercise AsrClient + ChunkQualityScorer + the
 * generateChunk hook + the tts:asr:health command without a real model.
 */
class AsrChunkQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.asr.enabled' => true,
            'tts.asr.url' => 'http://asr.test',
        ]);
        Storage::fake('local');
    }

    private function firstChunk(): TtsChunk
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'QA project',
            voice: $voice,
            text: 'This is a clean first paragraph with plenty of words to stand on its own.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );

        return $project->chunks()->orderBy('position')->first();
    }

    /** Build a fake transcript payload from a slice of the chunk's own words. */
    private function fakeTranscript(string $text, float $duration, float $perWord = 0.4): array
    {
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $words = [];
        $t = 0.0;
        foreach ($tokens as $tok) {
            $words[] = ['word' => $tok, 'start' => $t, 'end' => $t + $perWord];
            $t += $perWord;
        }

        return ['duration' => $duration, 'text' => $text, 'words' => $words, 'transcribe_ms' => 12];
    }

    public function test_clean_chunk_is_scored_ok_and_request_is_multipart(): void
    {
        $chunk = $this->firstChunk();
        $endAt = str_word_count($chunk->text) * 0.4;

        Http::fake([
            'asr.test/transcribe' => Http::response($this->fakeTranscript($chunk->text, $endAt + 0.3)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(ChunkStatus::Completed, $chunk->status);
        $this->assertNotNull($chunk->asr_report);
        $this->assertTrue($chunk->asr_report['ok']);
        $this->assertSame([], $chunk->asr_report['problems']);
        $this->assertEqualsWithDelta(1.0, $chunk->asr_score, 0.05);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/transcribe')
                && $request->isMultipart();
        });
    }

    public function test_truncated_chunk_is_flagged(): void
    {
        $chunk = $this->firstChunk();

        // Transcript only covers the first three words → truncation, with a long
        // junk tail after the last recognized word.
        $partial = implode(' ', array_slice(preg_split('/\s+/', $chunk->text), 0, 3));

        Http::fake([
            'asr.test/transcribe' => Http::response($this->fakeTranscript($partial, 8.0)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertNotNull($chunk->asr_report);
        $this->assertFalse($chunk->asr_report['ok']);
        $this->assertContains('TRUNC', $chunk->asr_report['problems']);
    }

    public function test_chunk_is_still_completed_when_sidecar_is_unreachable(): void
    {
        $chunk = $this->firstChunk();

        Http::fake(['asr.test/*' => Http::response('boom', 500)]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        // QA must never break generation; the chunk completes, just unscored.
        $this->assertSame(ChunkStatus::Completed, $chunk->status);
        $this->assertNull($chunk->asr_report);
    }

    public function test_qa_is_skipped_entirely_when_disabled(): void
    {
        config(['tts.asr.enabled' => false]);
        Http::fake(); // any sidecar call would be unexpected

        $chunk = $this->firstChunk();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertNull($chunk->refresh()->asr_report);
        Http::assertNothingSent();
    }

    /** A FakeTtsProvider that counts synthesize() calls (each re-roll is a call). */
    private function countingProvider(): object
    {
        $provider = new class extends FakeTtsProvider
        {
            public int $calls = 0;

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->calls++;

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        return $provider;
    }

    public function test_auto_reroll_recovers_a_truncated_chunk(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $chunk->text), 0, 3));
        $endAt = str_word_count($chunk->text) * 0.4;

        // First take truncates; the re-roll comes back clean.
        Http::fake([
            'asr.test/transcribe' => Http::sequence()
                ->push($this->fakeTranscript($partial, 8.0))
                ->push($this->fakeTranscript($chunk->text, $endAt + 0.3)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(2, $provider->calls);                 // initial + 1 re-roll
        $this->assertTrue($chunk->asr_report['ok']);
        $this->assertSame('rerolled', $chunk->asr_report['action']);
        $this->assertSame(1, $chunk->asr_report['reroll_attempts']);
        // The clean re-take's own report is problem-free, so the original defect
        // is recorded separately for the badge to name ("fixed a possible cut-off").
        $this->assertContains('TRUNC', $chunk->asr_report['fixed_problems']);
    }

    public function test_auto_reroll_keeps_best_take_when_never_clean(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        $tokens = preg_split('/\s+/', $chunk->text);
        $take = fn (int $words) => $this->fakeTranscript(implode(' ', array_slice($tokens, 0, $words)), 8.0);

        // All three takes truncate, with increasing coverage — the last is best.
        Http::fake([
            'asr.test/transcribe' => Http::sequence()
                ->push($take(2))
                ->push($take(3))
                ->push($take(5)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(3, $provider->calls);                 // initial + 2 re-rolls (max)
        $this->assertFalse($chunk->asr_report['ok']);
        $this->assertSame('rerolled_unrecovered', $chunk->asr_report['action']);
        $this->assertSame(2, $chunk->asr_report['reroll_attempts']);
        // Best-by-coverage take (5 of N words) was kept.
        $this->assertEqualsWithDelta(5 / count($tokens), $chunk->asr_score, 0.01);
    }

    public function test_auto_reroll_that_never_improves_records_no_duplicate_take(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        $tokens = preg_split('/\s+/', $chunk->text);
        $take = fn (int $words) => $this->fakeTranscript(implode(' ', array_slice($tokens, 0, $words)), 8.0);

        // Every re-roll scores at or below the flagged take — keep-best falls
        // back to the original bytes, and recording them again would just put a
        // byte-identical "remediate" take in the history.
        Http::fake([
            'asr.test/transcribe' => Http::sequence()
                ->push($take(4))
                ->push($take(3))
                ->push($take(2)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(3, $provider->calls);              // initial + 2 losing re-rolls
        $this->assertSame(1, $chunk->takes()->count());      // the flagged take, no duplicate
        $this->assertFalse($chunk->asr_report['ok']);        // its badge stays honest
        $this->assertArrayNotHasKey('action', $chunk->asr_report);
        $this->assertEqualsWithDelta(4 / count($tokens), $chunk->asr_score, 0.01);
    }

    public function test_auto_trims_a_tail_only_chunk_without_rerolling(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        // Full coverage but the last word ends at 0.1s with 5s of audio after —
        // a junk tail. trimAtMs = (0.1 + 0.08s guard) * 1000 = 180ms, which is
        // shorter than the fake's 0.2s WAV, so the stored take really gets cut.
        $tokens = preg_split('/\s+/', trim($chunk->text));
        $words = [];
        $step = 0.1 / max(1, count($tokens));
        foreach ($tokens as $i => $tok) {
            $words[] = ['word' => $tok, 'start' => $i * $step, 'end' => ($i + 1) * $step];
        }
        $transcript = ['duration' => 5.0, 'text' => $chunk->text, 'words' => $words, 'transcribe_ms' => 7];

        Http::fake(['asr.test/transcribe' => Http::response($transcript)]);

        // The untrimmed take is the fake provider's deterministic 0.2s WAV.
        $fullLen = strlen((new FakeTtsProvider)->synthesize('', null, []));

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(1, $provider->calls);                 // trimmed, never re-rolled
        $this->assertSame('trimmed', $chunk->asr_report['action']);
        $this->assertSame(180, $chunk->asr_report['trimmed_to_ms']);
        // The stored audio was actually shortened (0.18s < 0.2s).
        $stored = strlen(Storage::disk('local')->get($chunk->audio_path));
        $this->assertGreaterThan(0, $stored);
        $this->assertLessThan($fullLen, $stored);
    }

    public function test_studio_action_log_does_not_remediate_even_when_api_action_is_auto(): void
    {
        // The split: the interactive Studio stays manual (badge only) while the API self-heals.
        config(['tts.asr.studio_action' => 'log', 'tts.asr.api_action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $chunk->text), 0, 3));
        Http::fake(['asr.test/transcribe' => Http::response($this->fakeTranscript($partial, 8.0))]);

        app(ProjectService::class)->generateChunk($chunk);  // normal generate, not a manual reroll
        $chunk->refresh();

        $this->assertSame(1, $provider->calls);                     // single take, no auto re-roll
        $this->assertFalse($chunk->asr_report['ok']);
        $this->assertArrayNotHasKey('action', $chunk->asr_report);  // scored + logged only
    }

    public function test_studio_action_auto_overrides_a_global_log(): void
    {
        // A scoped studio_action beats the shared `action` default.
        config(['tts.asr.action' => 'log', 'tts.asr.studio_action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $chunk = $this->firstChunk();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $chunk->text), 0, 3));
        $endAt = str_word_count($chunk->text) * 0.4;
        Http::fake([
            'asr.test/transcribe' => Http::sequence()
                ->push($this->fakeTranscript($partial, 8.0))
                ->push($this->fakeTranscript($chunk->text, $endAt + 0.3)),
        ]);

        app(ProjectService::class)->generateChunk($chunk);
        $chunk->refresh();

        $this->assertSame(2, $provider->calls);                     // re-rolled despite action=log
        $this->assertSame('rerolled', $chunk->asr_report['action']);
    }

    public function test_asr_badge_reflects_verdict_and_is_suppressed_when_not_completed(): void
    {
        $chunk = $this->firstChunk();
        $this->assertNull($chunk->asrBadge());  // pending + unscored → nothing

        // Flagged, not auto-fixed → red "check". The short label carries the
        // tone; the worst problem names the popover heading + body.
        $chunk->update([
            'status' => ChunkStatus::Completed,
            'asr_report' => ['ok' => false, 'problems' => ['TRUNC', 'TAIL'], 'score' => 0.4, 'tail_cov' => 0.4],
        ]);
        $badge = $chunk->refresh()->asrBadge();
        $this->assertSame('bad', $badge['tone']);
        $this->assertSame('QA · check', $badge['text']);
        $this->assertSame('Possible cut-off', $badge['heading']);   // TRUNC wins over TAIL
        $this->assertStringContainsString('ended before the last words', $badge['body']);
        $this->assertStringContainsString('give it a listen', $badge['title']);

        // Once the chunk is edited (stale), its old verdict no longer applies.
        $chunk->update(['status' => ChunkStatus::Stale]);
        $this->assertNull($chunk->refresh()->asrBadge());

        // A recovered re-roll changed the audio → amber "fixed"; it names the
        // original defect (from fixed_problems) and states the fix.
        $chunk->update([
            'status' => ChunkStatus::Completed,
            'asr_report' => ['ok' => true, 'problems' => [], 'score' => 1.0, 'action' => 'rerolled', 'reroll_attempts' => 1, 'fixed_problems' => ['TRUNC']],
        ]);
        $badge = $chunk->refresh()->asrBadge();
        $this->assertSame('fixed', $badge['tone']);
        $this->assertSame('QA · fixed', $badge['text']);
        $this->assertSame('Possible cut-off', $badge['heading']);
        $this->assertSame('Auto-fixed:', $badge['fix']['label']);
        $this->assertStringContainsString('re-rolled once', $badge['fix']['text']);
        $this->assertSame([['act' => 'reroll', 'label' => 'Regenerate again'], ['act' => 'restore', 'label' => 'keep original']], $badge['actions']);
    }

    public function test_asr_badge_amber_for_a_trimmed_tail_and_muted_when_dismissed(): void
    {
        $chunk = $this->firstChunk();

        // A lossless tail trim changed the audio → amber "fixed" (even though the
        // persisted verdict still lists the tail problem).
        $chunk->update([
            'status' => ChunkStatus::Completed,
            'asr_report' => ['ok' => false, 'problems' => ['TAILNOISE'], 'action' => 'trimmed', 'trimmed_to_ms' => 4200, 'trail_s' => 0.4],
        ]);
        $badge = $chunk->refresh()->asrBadge();
        $this->assertSame('fixed', $badge['tone']);
        $this->assertSame('Loud tail', $badge['heading']);
        $this->assertStringContainsString('trimmed 0.4s', $badge['fix']['text']);
        $this->assertSame([['act' => 'restore', 'label' => 'Restore full take']], $badge['actions']);

        // A dismissed flag reads as a muted "reviewed" pill.
        $chunk->update([
            'asr_report' => ['ok' => false, 'problems' => ['PAUSE'], 'qa_dismissed' => true],
        ]);
        $badge = $chunk->refresh()->asrBadge();
        $this->assertSame('reviewed', $badge['tone']);
        $this->assertSame('QA · reviewed', $badge['text']);
    }

    public function test_project_page_renders_the_asr_badge(): void
    {
        $chunk = $this->firstChunk();
        $chunk->update([
            'status' => ChunkStatus::Completed,
            'asr_report' => ['ok' => false, 'problems' => ['TRUNC'], 'score' => 0.4, 'tail_cov' => 0.4],
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.studio.projects.show', $chunk->project))
            ->assertOk()
            ->assertSee('chunk-asr-badge', escape: false)
            ->assertSee('QA · check')                 // the short pill label
            ->assertSee('Possible cut-off');          // the popover heading
    }

    public function test_dismiss_endpoint_quiets_the_badge_to_reviewed(): void
    {
        $chunk = $this->firstChunk();
        $chunk->update([
            'status' => ChunkStatus::Completed,
            'asr_report' => ['ok' => false, 'problems' => ['PAUSE'], 'max_gap_s' => 2.0],
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.qa-dismiss', [$chunk->project, $chunk]))
            ->assertOk()
            ->assertJsonPath('asr_badge.tone', 'reviewed')
            ->assertJsonPath('asr_badge.text', 'QA · reviewed');

        $this->assertTrue($chunk->refresh()->asr_report['qa_dismissed']);
    }

    public function test_generate_endpoint_returns_the_asr_badge(): void
    {
        config(['tts.asr.action' => 'log']);
        $chunk = $this->firstChunk();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $chunk->text), 0, 3));
        Http::fake(['asr.test/transcribe' => Http::response($this->fakeTranscript($partial, 8.0))]);

        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.generate', [$chunk->project, $chunk]))
            ->assertOk()
            ->assertJsonPath('asr_badge.tone', 'bad')
            ->assertJsonPath('asr_badge.text', 'QA · check')
            ->assertJsonPath('asr_badge.heading', 'Possible cut-off');
    }

    public function test_health_report_asr_failure_is_clean_and_links_the_setup_guide(): void
    {
        config(['tts.asr.url' => 'http://asr.test', 'tts.asr.docs_url' => 'https://example.test/asr-setup']);
        Http::fake(['asr.test/health' => Http::response('', 500)]);

        $asr = collect(app(HealthReport::class)->run())->firstWhere('key', 'asr');

        $this->assertSame('FAIL', $asr->status->value);
        $this->assertSame('https://example.test/asr-setup', $asr->helpUrl);
        // The noisy raw cURL/libcurl text is gone; the message is actionable.
        $this->assertStringNotContainsString('cURL', $asr->detail);
        $this->assertStringContainsString("isn't responding", $asr->detail);
    }

    public function test_health_command_reports_ok(): void
    {
        Http::fake([
            'asr.test/health' => Http::response([
                'status' => 'ok', 'model' => 'tiny', 'device' => 'cpu',
                'compute_type' => 'int8', 'faster_whisper_version' => '1.2.1',
            ]),
        ]);

        $this->artisan('tts:asr:health')->assertExitCode(0);
    }

    public function test_health_command_fails_when_unreachable(): void
    {
        Http::fake(['asr.test/health' => Http::response('', 503)]);

        $this->artisan('tts:asr:health')->assertExitCode(1);
    }

    public function test_health_command_deep_self_test_passes(): void
    {
        $expected = 'What is a dark pattern?';

        Http::fake([
            'asr.test/health' => Http::response([
                'status' => 'ok', 'model' => 'tiny', 'device' => 'cpu',
                'compute_type' => 'int8', 'faster_whisper_version' => '1.2.1',
            ]),
            'asr.test/transcribe' => Http::response($this->fakeTranscript($expected, 1.8)),
        ]);

        $this->artisan('tts:asr:health --deep')->assertExitCode(0);
    }
}
