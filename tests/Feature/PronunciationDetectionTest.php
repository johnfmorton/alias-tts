<?php

namespace Tests\Feature;

use App\Models\PronunciationEntry;
use App\Models\User;
use App\Services\Pronunciation\PronunciationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The PHP side of detection: PronunciationDetector + GenblazeRunnerClient talking
 * to the runner's /pronounce. The runner is HTTP-faked; nothing here calls an LLM.
 */
class PronunciationDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.pronunciation.enabled' => true,
            'tts.pronunciation.llm_provider' => 'replicate',
        ]);
    }

    private function detector(): PronunciationDetector
    {
        return app(PronunciationDetector::class);
    }

    public function test_detect_returns_parsed_substitutions(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
            'provenance' => ['provider' => 'replicate', 'model' => 'meta/meta-llama-3.1-8b-instruct'],
        ])]);

        $out = $this->detector()->detect('Install DDEV first.');

        $this->assertTrue($out['available']);
        $this->assertSame('DDEV', $out['substitutions'][0]['term']);
        $this->assertSame('replicate', $out['provenance']['provider']);
    }

    public function test_detect_sends_known_terms_from_the_dictionary(): void
    {
        $user = User::factory()->create();
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => true, 'source' => 'user']);
        Http::fake(['runner.test/pronounce' => Http::response(['available' => true, 'substitutions' => []])]);

        $this->detector()->detect('serve with nginx', $user->id);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'runner.test/pronounce')
            && in_array('nginx', $req['known_terms'], true)
            && $req['provider'] === 'replicate');
    }

    public function test_detect_degrades_safely_on_a_runner_error(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response('boom', 500)]);

        $out = $this->detector()->detect('text');

        $this->assertFalse($out['available']);
        $this->assertSame([], $out['substitutions']);
    }

    public function test_detect_is_unavailable_and_silent_when_disabled(): void
    {
        config(['tts.pronunciation.enabled' => false]);
        Http::fake();

        $out = $this->detector()->detect('text');

        $this->assertFalse($out['available']);
        Http::assertNothingSent();
    }

    public function test_force_runs_detection_even_when_globally_disabled(): void
    {
        // The Genblaze page forces this on so judges always see the CHAT step.
        config(['tts.pronunciation.enabled' => false]);
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [['term' => 'B2', 'phonetic' => 'bee two']],
        ])]);

        $out = $this->detector()->detect('Upload to B2.', null, force: true);

        $this->assertTrue($out['available']);
        $this->assertSame('B2', $out['substitutions'][0]['term']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'runner.test/pronounce'));
    }
}
