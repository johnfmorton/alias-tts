<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\VoicePreview;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Voices page keeps each voice's last "Test" sample so later visits replay
 * it instead of paying for a new render — but only while the sample still
 * represents the voice (see VoicePreview).
 */
class VoicePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function voice(?int $userId = null, string $slug = 'v'): Voice
    {
        return Voice::create(['slug' => $slug, 'name' => 'Sample Voice', 'user_id' => $userId]);
    }

    public function test_a_test_keeps_its_sample_and_the_page_offers_it_for_replay(): void
    {
        $admin = $this->admin();
        $voice = $this->voice($admin->id);

        $this->actingAs($admin)->get(route('admin.voices.index'))
            ->assertOk()
            ->assertSee('>Test</button>', false)
            ->assertDontSee(route('admin.voices.preview', $voice));
        $this->actingAs($admin)->get(route('admin.voices.preview', $voice))->assertNotFound();

        $updatedAt = $voice->fresh()->updated_at;
        $rendered = $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();

        $voice->refresh();
        $this->assertNotNull($voice->preview_audio_path);
        Storage::disk('local')->assertExists($voice->preview_audio_path);
        // Recording the sample must not look like an edit of the voice.
        $this->assertTrue($updatedAt->equalTo($voice->updated_at));
        $this->assertTrue(app(VoicePreview::class)->isCurrent($voice));

        $this->actingAs($admin)->get(route('admin.voices.index'))
            ->assertOk()
            ->assertSee('>Re-test</button>', false)
            ->assertSee('src="'.route('admin.voices.preview', $voice).'"', false);

        $replay = $this->actingAs($admin)->get(route('admin.voices.preview', $voice))->assertOk();
        $this->assertSame($rendered->getContent(), $replay->getContent());
    }

    public function test_the_replay_honors_range_requests(): void
    {
        $admin = $this->admin();
        $voice = $this->voice($admin->id);
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();

        // iOS Safari range-probes an <audio src>; a plain 200 breaks its player.
        $this->actingAs($admin)
            ->get(route('admin.voices.preview', $voice), ['Range' => 'bytes=0-1'])
            ->assertStatus(206);
    }

    public function test_a_sample_from_before_the_voice_changed_is_not_replayed(): void
    {
        $admin = $this->admin();
        $voice = $this->voice($admin->id);
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();

        // Any edit of the voice (new clip, tuning, rename) moves it on.
        $this->travel(5)->seconds();
        $voice->refresh()->update(['settings' => ['exaggeration' => 0.9]]);

        $this->assertFalse(app(VoicePreview::class)->isCurrent($voice->fresh()));
        $this->actingAs($admin)->get(route('admin.voices.preview', $voice))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.voices.index'))
            ->assertSee('>Test</button>', false)
            ->assertDontSee(route('admin.voices.preview', $voice));
    }

    public function test_a_re_test_replaces_the_stored_sample(): void
    {
        $admin = $this->admin();
        $voice = $this->voice($admin->id);
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();
        $first = $voice->refresh()->preview_audio_path;

        $this->travel(5)->seconds();
        $voice->update(['name' => 'Renamed Voice']);
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();

        $second = $voice->refresh()->preview_audio_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->assertTrue(app(VoicePreview::class)->isCurrent($voice));
    }

    public function test_another_users_voice_sample_is_not_served(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $voice = $this->voice($owner->id);
        $this->actingAs($owner)->post(route('admin.voices.test', $voice))->assertOk();

        $this->actingAs($other)->get(route('admin.voices.preview', $voice))->assertNotFound();
    }

    public function test_deleting_a_voice_removes_its_sample_and_a_copy_starts_without_one(): void
    {
        $admin = $this->admin();
        $voice = $this->voice($admin->id);
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();
        $path = $voice->refresh()->preview_audio_path;

        $copy = app(VoiceService::class)->duplicate($voice, $admin->id);
        $this->assertNull($copy->fresh()->preview_audio_path);

        app(VoiceService::class)->delete($voice);
        Storage::disk('local')->assertMissing($path);
    }
}
