<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DefaultVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local', 'cache.default' => 'array']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_default_voices_are_seeded_with_bundled_references(): void
    {
        $male = Voice::resolve(Voice::defaultSlug());
        $female = Voice::resolve(Voice::femaleDefaultSlug());

        $this->assertNotNull($male, 'The built-in male default voice should be seeded by migration.');
        $this->assertNotNull($female, 'The built-in female default voice should be seeded by migration.');

        // Both ship with a bundled reference clip (no longer reference-less).
        $this->assertNotNull($male->reference_audio_path, 'The male default has a bundled reference clip.');
        $this->assertNotNull($female->reference_audio_path, 'The female default has a bundled reference clip.');

        // The primary default is the male voice; both are protected built-ins.
        $this->assertTrue($male->isDefault());
        $this->assertFalse($female->isDefault());
        $this->assertTrue($male->isBuiltin());
        $this->assertTrue($female->isBuiltin());
    }

    public function test_default_voice_generates_audio(): void
    {
        $key = ApiKey::generate('test');

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/'.Voice::defaultSlug(), ['text' => 'Hello from the built-in voice.']);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_voice_test_button_always_regenerates_and_never_replays_cache(): void
    {
        // Regression: the Voices "Test" button must reflect the voice's CURRENT
        // state on every click, not replay a cached preview. (On production a
        // stale cached default-voice preview kept playing another voice's clip.)
        // Two identical Test clicks must therefore create two Speech rows — with
        // the old cache-reusing behavior the second click reused the first row.
        $voice = Voice::resolve(Voice::defaultSlug());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();
        $this->actingAs($admin)->post(route('admin.voices.test', $voice))->assertOk();

        $this->assertSame(2, Speech::where('voice_id', $voice->id)->count());
    }

    public function test_default_voice_cannot_be_deleted(): void
    {
        $voice = Voice::resolve(Voice::defaultSlug());

        $this->actingAs($this->admin())
            ->delete(route('admin.voices.destroy', $voice))
            ->assertRedirect(route('admin.voices.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Voice::resolve(Voice::defaultSlug()), 'The default voice must survive a delete attempt.');
    }

    public function test_female_default_voice_cannot_be_deleted(): void
    {
        $voice = Voice::resolve(Voice::femaleDefaultSlug());

        $this->actingAs($this->admin())
            ->delete(route('admin.voices.destroy', $voice))
            ->assertRedirect(route('admin.voices.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Voice::resolve(Voice::femaleDefaultSlug()), 'The female default voice must survive a delete attempt.');
    }

    public function test_voices_index_marks_the_default_voices_builtin_and_undeletable(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.voices.index'));

        $res->assertOk()->assertSee('built-in')->assertSee('Default voice');
        // Neither default voice row exposes a delete action (their edit/export
        // links share the URL prefix, so match each form's action attribute).
        $this->assertStringNotContainsString(
            'action="'.route('admin.voices.destroy', Voice::resolve(Voice::defaultSlug())).'"',
            $res->getContent(),
        );
        $this->assertStringNotContainsString(
            'action="'.route('admin.voices.destroy', Voice::resolve(Voice::femaleDefaultSlug())).'"',
            $res->getContent(),
        );
    }

    public function test_studio_inspector_lists_the_default_voice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Default voice');
    }

    public function test_admin_can_create_a_voice_without_a_reference_clip(): void
    {
        $res = $this->actingAs($this->admin())
            ->post(route('admin.voices.store'), ['name' => 'No Clip']);

        $voice = Voice::firstWhere('name', 'No Clip');
        $this->assertNotNull($voice);
        $this->assertNull($voice->reference_audio_path);
        $res->assertRedirect(route('admin.voices.edit', $voice));
    }

    public function test_admin_can_still_create_a_voice_with_a_reference_clip(): void
    {
        $res = $this->actingAs($this->admin())
            ->post(route('admin.voices.store'), [
                'name' => 'With Clip',
                'audio' => UploadedFile::fake()->create('ref.wav', 64, 'audio/wav'),
                'clip_rights' => '1',
                'raw' => '1', // skip ffmpeg normalization in the test
            ]);

        $voice = Voice::firstWhere('name', 'With Clip');
        $this->assertNotNull($voice);
        $this->assertNotNull($voice->reference_audio_path);
        $res->assertRedirect(route('admin.voices.edit', $voice));
    }
}
