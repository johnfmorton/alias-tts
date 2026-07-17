<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Jobs\GenerateSpeechJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\SpeechProgressStore;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The Alias async extension: POST .../jobs -> poll -> fetch, so long text
 * isn't bound by the synchronous ~300s ceiling.
 */
class AsyncSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'cache.default' => 'array',
        ]);

        Storage::fake('local');
    }

    private function makeKey(?int $rateLimit = null): ApiKey
    {
        return ApiKey::generate('test', $rateLimit);
    }

    private function makeVoice(string $slug = 'my-voice'): Voice
    {
        return Voice::create(['slug' => $slug, 'name' => 'My Voice']);
    }

    public function test_queue_returns_202_and_dispatches_a_job(): void
    {
        Queue::fake();

        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'A long article.']);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('id', fn ($id) => is_string($id) && $id !== '')
            ->assertJsonStructure(['id', 'status', 'status_url', 'audio_url']);

        $this->assertSame('MISS', $response->headers->get('x-cache'));

        $speechId = $response->json('id');
        Queue::assertPushed(GenerateSpeechJob::class, fn ($job) => $job->speechId === $speechId);

        // Record exists and is still Processing (the faked job hasn't run).
        $this->assertSame(SpeechStatus::Processing, Speech::find($speechId)->status);
    }

    public function test_full_lifecycle_queue_poll_then_fetch_audio(): void
    {
        // QUEUE_CONNECTION=sync in phpunit, so the job runs inline on dispatch.
        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $queued = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Hello async world.']);

        // Ran inline -> already completed -> 200.
        $queued->assertStatus(200)->assertJsonPath('status', 'completed');
        $id = $queued->json('id');

        $status = $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}");
        $status->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('id', $id);

        $audio = $this->withHeaders($headers)->get("/v1/text-to-speech/jobs/{$id}/audio");
        $audio->assertStatus(200);
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
        $this->assertNotEmpty($audio->getContent());
        $this->assertSame($id, $audio->headers->get('request-id'));
    }

    public function test_audio_is_409_while_still_processing(): void
    {
        Queue::fake(); // job never runs -> stays Processing

        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $id = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Still going.'])
            ->json('id');

        $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}")
            ->assertStatus(200)->assertJsonPath('status', 'processing');

        $this->withHeaders($headers)->get("/v1/text-to-speech/jobs/{$id}/audio")
            ->assertStatus(409)->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_jobs_are_scoped_to_the_calling_api_key(): void
    {
        Queue::fake();

        $owner = $this->makeKey();
        $other = $this->makeKey();
        $this->makeVoice();

        $id = $this->withHeaders(['xi-api-key' => $owner->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Private.'])
            ->json('id');

        // A different key can't see it.
        $this->withHeaders(['xi-api-key' => $other->key])
            ->getJson("/v1/text-to-speech/jobs/{$id}")
            ->assertStatus(404);

        $this->withHeaders(['xi-api-key' => $other->key])
            ->get("/v1/text-to-speech/jobs/{$id}/audio")
            ->assertStatus(404);
    }

    public function test_cache_hit_returns_completed_without_dispatching(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];
        $payload = ['text' => 'Cache this async.'];

        // Prime the cache synchronously (process() runs inline, no queue).
        $this->withHeaders($headers)->postJson('/v1/text-to-speech/my-voice', $payload)->assertStatus(200);
        $this->assertSame(1, Speech::count());

        Queue::fake();

        $response = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', $payload);

        $response->assertStatus(200)->assertJsonPath('status', 'completed');
        $this->assertSame('HIT', $response->headers->get('x-cache'));
        $this->assertSame(1, Speech::count(), 'Cache hit should not create a new record.');
        Queue::assertNothingPushed();
    }

    public function test_jobs_endpoint_accepts_text_longer_than_the_sync_cap(): void
    {
        Queue::fake();

        // Tiny sync cap, larger async cap. Text between the two must be accepted by
        // the async endpoint (it generates in a background worker, unbounded by the
        // ~300s synchronous budget) even though the sync endpoint would 422 it.
        config(['tts.max_text_length' => 10, 'tts.max_async_text_length' => 100]);

        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];
        $text = str_repeat('a', 50); // > sync cap (10), < async cap (100)

        // Sync endpoint rejects it...
        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice', ['text' => $text])
            ->assertStatus(422);

        // ...but the async jobs endpoint accepts it and queues generation.
        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => $text])
            ->assertStatus(202)
            ->assertJsonPath('status', 'processing');

        Queue::assertPushed(GenerateSpeechJob::class);
    }

    public function test_jobs_endpoint_still_enforces_its_own_max(): void
    {
        config(['tts.max_async_text_length' => 100]);

        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_polling_is_not_rate_limited(): void
    {
        Queue::fake();

        // rate_limit of 1: the single generating POST consumes the whole quota.
        $key = $this->makeKey(rateLimit: 1);
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $id = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Poll me.'])
            ->assertStatus(202)->json('id');

        // A second generating POST is blocked by the limiter...
        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Another.'])
            ->assertStatus(429);

        // ...but status polling keeps working past the limit.
        foreach (range(1, 3) as $i) {
            $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}")
                ->assertStatus(200)->assertJsonPath('status', 'processing');
        }
    }

    public function test_status_reports_progress_while_processing(): void
    {
        Queue::fake(); // record stays Processing; we play the worker below

        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $id = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'A long article.'])
            ->json('id');

        $store = app(SpeechProgressStore::class);
        $store->begin($id, 50, 'chatterbox');
        $store->advance($id, 24, 50);

        $res = $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('progress.stage', 'generating')
            ->assertJsonPath('progress.chunks_total', 50)
            ->assertJsonPath('progress.chunks_done', 24)
            ->assertJsonPath('progress.percent', 48);
        // The clip line stays; the ETA is appended (exact value is timing-based).
        $this->assertStringStartsWith('Creating clip 25 of 50', $res->json('progress.message'));
        $this->assertStringContainsString('left', $res->json('progress.message'));
        $this->assertNotNull($res->json('progress.eta_human'));

        $store->stitching($id, 50);

        // Stitching means every clip is rendered — nothing left to estimate.
        $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}")
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('progress.stage', 'stitching')
            ->assertJsonPath('progress.chunks_done', 50)
            ->assertJsonPath('progress.percent', 100)
            ->assertJsonPath('progress.message', 'Stitching 50 clips together')
            ->assertJsonPath('progress.eta_human', null);
    }

    public function test_progress_is_null_before_the_worker_writes(): void
    {
        Queue::fake(); // nothing has written a snapshot -> cache miss

        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $queued = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Not started yet.']);

        $queued->assertStatus(202)->assertJsonPath('progress', null);
        $this->assertArrayHasKey('progress', $queued->json());

        $this->withHeaders($headers)
            ->getJson('/v1/text-to-speech/jobs/'.$queued->json('id'))
            ->assertStatus(200)
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('progress', null);
    }

    public function test_progress_is_cleared_and_null_once_completed(): void
    {
        // QUEUE_CONNECTION=sync: the job runs inline, start to finish.
        $key = $this->makeKey();
        $this->makeVoice();

        $queued = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Run to completion.']);

        $queued->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress', null);

        // The snapshot itself was cleared, not just gated off by the status.
        $this->assertNull(app(SpeechProgressStore::class)->get($queued->json('id')));
    }

    public function test_process_reports_each_chunk_then_stitching_then_clears(): void
    {
        config(['tts.chunk_chars' => 120]); // force several chunks (cf. ChunkingTest)

        // Recording subclass: process() clears the snapshot on exit, so the
        // intermediate writes can only be asserted by capturing the calls.
        $store = new class extends SpeechProgressStore
        {
            /** @var list<array{string, int}> */
            public array $calls = [];

            public function begin(string $speechId, int $chunksTotal, ?string $model = null): void
            {
                $this->calls[] = ['begin', $chunksTotal];
                parent::begin($speechId, $chunksTotal, $model);
            }

            public function advance(string $speechId, int $chunksDone, int $chunksTotal): void
            {
                $this->calls[] = ['advance', $chunksDone];
                parent::advance($speechId, $chunksDone, $chunksTotal);
            }

            public function stitching(string $speechId, int $chunksTotal): void
            {
                $this->calls[] = ['stitching', $chunksTotal];
                parent::stitching($speechId, $chunksTotal);
            }

            public function clear(string $speechId): void
            {
                $this->calls[] = ['clear', 0];
                parent::clear($speechId);
            }
        };
        $this->app->instance(SpeechProgressStore::class, $store);

        $key = $this->makeKey();
        $this->makeVoice();
        $text = str_repeat('This sentence pads the article well past a single chunk. ', 8);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => $text])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $this->assertSame('begin', $store->calls[0][0]);
        $total = $store->calls[0][1];
        $this->assertGreaterThan(1, $total, 'Text was expected to split into multiple chunks.');

        // Exactly: begin, advance 1..N in order, stitching, clear.
        $this->assertSame(
            array_merge(['begin'], array_fill(0, $total, 'advance'), ['stitching', 'clear']),
            array_column($store->calls, 0),
        );
        $this->assertSame(range(1, $total), array_column(array_slice($store->calls, 1, $total), 1));
    }

    public function test_progress_hidden_after_failure(): void
    {
        $this->app->instance(TtsProvider::class, new class extends FakeTtsProvider
        {
            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                throw new RuntimeException('provider exploded');
            }
        });

        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        // Inline sync queue: the failure propagates out of dispatch as a 502.
        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Doomed.'])
            ->assertStatus(502);

        $speech = Speech::first();
        $this->assertSame(SpeechStatus::Failed, $speech->status);

        $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$speech->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('progress', null)
            ->assertJsonPath('error', fn ($e) => is_string($e) && $e !== '');

        // The catch-block cleanup dropped the snapshot itself.
        $this->assertNull(app(SpeechProgressStore::class)->get($speech->id));
    }
}
