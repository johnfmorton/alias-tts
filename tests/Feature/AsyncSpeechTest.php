<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Jobs\GenerateSpeechJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Mimic async extension: POST .../jobs -> poll -> fetch, so long text
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
}
