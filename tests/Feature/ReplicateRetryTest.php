<?php

namespace Tests\Feature;

use App\Services\Tts\ReplicateChatterboxProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Replicate throttles prediction creation with a burst limit (429 + retry_after).
 * The provider must retry through that instead of failing the whole article.
 */
class ReplicateRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Don't actually wait during backoff.
        Sleep::fake();
    }

    private function provider(array $overrides = []): ReplicateChatterboxProvider
    {
        return new ReplicateChatterboxProvider(array_merge([
            'token' => 'r8_test',
            'version' => 'test-version',
            'text_field' => 'prompt',
            'reference_field' => 'audio_prompt',
            'max_retries' => 5,
            'retry_base_ms' => 10,
            'retry_max_ms' => 1000,
            'min_request_gap_ms' => 0,
        ], $overrides), 300);
    }

    public function test_it_retries_a_429_then_succeeds(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::sequence()
                ->push(['status' => 429, 'retry_after' => 1, 'detail' => 'rate limited'], 429)
                ->push(['id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav'], 200),
            'replicate.delivery/*' => Http::response('AUDIO-BYTES', 200),
        ]);

        $audio = $this->provider()->synthesize('Hello there.', null, []);

        $this->assertSame('AUDIO-BYTES', $audio);

        // One backoff sleep, honoring the 1s retry_after hint (no polling sleep,
        // since the prediction came back already "succeeded").
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 1000.0, 1);
    }

    public function test_it_gives_up_after_the_retry_cap(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response(['status' => 429, 'retry_after' => 1], 429),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            $this->provider(['max_retries' => 2])->synthesize('Hello there.', null, []);
        } finally {
            // 3 attempts total => 2 retries => 2 sleeps before giving up.
            Sleep::assertSleptTimes(2);
        }
    }

    public function test_it_does_not_retry_a_non_429_failure(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response(['detail' => 'bad input'], 422),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', null, []);
            $this->fail('Expected a RuntimeException for a non-429 failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('422', $e->getMessage());
        }

        // A 422 is terminal — no backoff, single attempt.
        Sleep::assertNeverSlept();
        Http::assertSentCount(1);
    }

    public function test_it_honors_the_retry_after_header_when_body_omits_it(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::sequence()
                ->push('Too Many Requests', 429, ['Retry-After' => '2'])
                ->push(['id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav'], 200),
            'replicate.delivery/*' => Http::response('AUDIO-BYTES', 200),
        ]);

        // retry_max_ms must exceed the 2s hint, or the wait would be clamped.
        $audio = $this->provider(['retry_max_ms' => 5000])->synthesize('Hello there.', null, []);

        $this->assertSame('AUDIO-BYTES', $audio);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 2000.0, 1);
    }

    public function test_min_request_gap_spaces_out_predictions(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response(
                ['id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav'],
                200,
            ),
            'replicate.delivery/*' => Http::response('AUDIO-BYTES', 200),
        ]);

        $provider = $this->provider(['min_request_gap_ms' => 5000]);

        // First call: no prior timestamp, so no spacing sleep.
        $provider->synthesize('First.', null, []);
        Sleep::assertNeverSlept();

        // Second call: almost no wall-clock elapsed, so ~the full gap is owed.
        // (Real microtime advances a few ms between calls, hence the tolerance.)
        $provider->synthesize('Second.', null, []);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds > 4000.0, 1);
    }
}
