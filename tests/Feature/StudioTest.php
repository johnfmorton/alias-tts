<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioTest extends TestCase
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

    public function test_studio_page_requires_admin(): void
    {
        $this->get(route('admin.studio.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.studio.index'))
            ->assertForbidden();
    }

    public function test_admin_can_open_the_studio_page(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Normalized text');
    }

    public function test_advanced_tuning_toggle_persists_per_user(): void
    {
        $admin = $this->admin();
        $this->assertFalse($admin->fresh()->studio_advanced); // DB default



        $this->actingAs($admin)->post(route('admin.studio.advanced'), ['enabled' => '1'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue($admin->refresh()->studio_advanced);

        $this->actingAs($admin)->post(route('admin.studio.advanced'), ['enabled' => '0']);
        $this->assertFalse($admin->refresh()->studio_advanced);
    }

    public function test_save_voice_defaults_writes_tuning_to_the_voice(): void
    {
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.voice-defaults'), [
                'voice' => 'john',
                'stability' => 0.8,
                'style' => 0.3,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $settings = $voice->refresh()->settings;
        $this->assertSame(0.8, $settings['stability']);
        $this->assertSame(0.3, $settings['style']);
    }

    public function test_save_voice_defaults_validates_range_and_voice(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'nope', 'stability' => 0.5])
            ->assertStatus(422);

        Voice::create(['slug' => 'john', 'name' => 'John']);
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'john', 'stability' => 2])
            ->assertStatus(422);
    }

    public function test_tuning_preset_can_be_created_and_deleted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.studio.presets.store'), ['name' => 'Calm narration', 'stability' => 0.8, 'style' => 0.1])
            ->assertOk()
            ->assertJsonPath('preset.name', 'Calm narration')
            ->assertJsonPath('preset.stability', 0.8);

        $preset = \App\Models\TuningPreset::firstWhere('name', 'Calm narration');
        $this->assertSame(0.8, $preset->stability);
        $this->assertSame(0.1, $preset->style);

        $this->actingAs($this->admin())
            ->delete(route('admin.studio.presets.destroy', $preset))
            ->assertOk();
        $this->assertNull(\App\Models\TuningPreset::find($preset->id));
    }

    public function test_tuning_preset_rejects_duplicate_name_and_bad_range(): void
    {
        \App\Models\TuningPreset::create(['name' => 'Energetic', 'stability' => 0.3, 'style' => 0.7]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.presets.store'), ['name' => 'Energetic', 'stability' => 0.5])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.presets.store'), ['name' => 'Too loud', 'style' => 5])
            ->assertStatus(422);
    }

    public function test_studio_page_renders_existing_presets(): void
    {
        \App\Models\TuningPreset::create(['name' => 'Calm narration', 'stability' => 0.8, 'style' => 0.1]);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Calm narration');
    }

    public function test_preview_normalizes_and_makes_no_provider_call(): void
    {
        // Emoji stripped, "editor ." tidied to "editor." — and no audio is touched.
        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.preview'), ['text' => 'Hello 🍻 editor .']);

        $res->assertOk();
        $this->assertStringNotContainsString('🍻', $res->json('normalized'));
        $this->assertSame('Hello editor.', $res->json('chunks.0.text'));
        $this->assertCount(1, $res->json('chunks'));
    }

    public function test_preview_chunks_across_a_paragraph_break(): void
    {
        $text = "This is the first paragraph with plenty of words to stand on its own.\n\n".
                'This is the second paragraph, also long enough to be its own chunk.';

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.preview'), ['text' => $text]);

        $res->assertOk()
            ->assertJsonCount(2, 'chunks')
            ->assertJsonPath('chunks.0.breakAfter', 'paragraph')
            ->assertJsonPath('chunks.1.breakAfter', 'sentence');
    }

    public function test_preview_requires_text(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.preview'), ['text' => ''])
            ->assertStatus(422);
    }

    public function test_synthesize_returns_raw_audio(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())
            ->post(route('admin.studio.synthesize'), ['text' => 'A single chunk of text.']);

        $res->assertOk();
        $this->assertStringStartsWith('audio/wav', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_synthesize_without_a_voice_is_unprocessable(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.studio.synthesize'), ['text' => 'Hello.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No voice configured — add a voice first.');
    }

    public function test_stitch_returns_concatenated_audio(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())
            ->post(route('admin.studio.stitch'), ['text' => 'First sentence here. Second sentence here.']);

        $res->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_concat_stitches_uploaded_chunk_blobs(): void
    {
        // Stitch the EXACT bytes the client holds (uploaded), the way production
        // concatenation does — this is the dropped-word debugging path.
        $wav = app(TtsProvider::class)->synthesize('chunk', null, []);

        $res = $this->actingAs($this->admin())->post(route('admin.studio.concat'), [
            'files' => [
                UploadedFile::fake()->createWithContent('a.wav', $wav),
                UploadedFile::fake()->createWithContent('b.wav', $wav),
            ],
            'breaks' => ['sentence', 'sentence'],
        ]);

        $res->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_concat_accepts_a_single_chunk(): void
    {
        // One chunk alone exercises the per-chunk trim in isolation.
        $wav = app(TtsProvider::class)->synthesize('chunk', null, []);

        $this->actingAs($this->admin())->post(route('admin.studio.concat'), [
            'files' => [UploadedFile::fake()->createWithContent('a.wav', $wav)],
            'breaks' => ['sentence'],
        ])->assertOk();
    }

    public function test_concat_requires_at_least_one_file(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.concat'), ['files' => []])
            ->assertStatus(422);
    }
}
