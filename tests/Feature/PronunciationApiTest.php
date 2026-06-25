<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PronunciationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->getJson('/v1/pronunciations')->assertStatus(401);
    }

    public function test_it_returns_only_approved_entries_in_the_spec_shape(): void
    {
        $key = ApiKey::generate('test');
        PronunciationEntry::create([
            'user_id' => null, 'term' => 'DDEV', 'phonetic' => 'dee dev',
            'match_mode' => 'case_insensitive', 'category' => 'initialism', 'source' => 'user', 'approved' => true,
        ]);
        PronunciationEntry::create([
            'user_id' => null, 'term' => 'nginx', 'phonetic' => 'engine ex', 'source' => 'llm', 'approved' => false,
        ]);

        $res = $this->withHeaders(['xi-api-key' => $key->key])->getJson('/v1/pronunciations');

        $res->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('entries.0.term', 'DDEV')
            ->assertJsonPath('entries.0.match', 'case_insensitive');
        $this->assertCount(1, $res->json('entries')); // the unapproved nginx is excluded
    }

    public function test_it_supports_conditional_requests_via_etag(): void
    {
        $key = ApiKey::generate('test');
        PronunciationEntry::create([
            'user_id' => null, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user',
        ]);

        $res = $this->withHeaders(['xi-api-key' => $key->key])->getJson('/v1/pronunciations')->assertOk();
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders(['xi-api-key' => $key->key, 'If-None-Match' => $etag])
            ->get('/v1/pronunciations')
            ->assertStatus(304);
    }
}
