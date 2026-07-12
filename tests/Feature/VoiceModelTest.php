<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Per-voice engine choice: voices.model picks the catalog model (null =
 * classic chatterbox), turbo voices need a >5s reference clip OR a built-in
 * preset voice, and the choice survives duplicate/clone/export/import.
 */
class VoiceModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A parseable PCM WAV of roughly $seconds of silence. */
    private function silentWav(float $seconds): string
    {
        $sampleRate = 8000;
        $dataSize = (int) ($sampleRate * $seconds) * 2;

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $sampleRate).pack('V', $sampleRate * 2)
            .pack('v', 2).pack('v', 16)
            .'data'.pack('V', $dataSize).str_repeat("\x00", $dataSize);
    }

    public function test_a_turbo_voice_with_a_preset_needs_no_clip(): void
    {
        $voice = app(VoiceService::class)->register(
            name: 'Turbo Preset', slug: 'turbo-preset', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo', presetVoice: 'Laura',
        );

        $this->assertSame('chatterbox-turbo', $voice->model);
        $this->assertSame('replicate', $voice->provider);
        $this->assertSame('Laura', $voice->settings['preset_voice']);
        $this->assertNull($voice->reference_audio_path);
    }

    public function test_a_turbo_voice_without_clip_or_preset_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('built-in');

        app(VoiceService::class)->register(
            name: 'Turbo Empty', slug: 'turbo-empty', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo',
        );
    }

    public function test_a_turbo_voice_rejects_a_short_clip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('longer than 5 seconds');

        app(VoiceService::class)->register(
            name: 'Turbo Short', slug: 'turbo-short', audioBytes: $this->silentWav(2.0), ext: 'wav',
            normalize: false, seed: null, model: 'chatterbox-turbo',
        );
    }

    public function test_a_turbo_voice_accepts_a_long_clip(): void
    {
        $voice = app(VoiceService::class)->register(
            name: 'Turbo Long', slug: 'turbo-long', audioBytes: $this->silentWav(8.0), ext: 'wav',
            normalize: false, seed: null, model: 'chatterbox-turbo',
        );

        $this->assertSame('chatterbox-turbo', $voice->model);
        $this->assertNotNull($voice->reference_audio_path);
    }

    public function test_switching_an_existing_voice_to_turbo_rechecks_its_stored_clip(): void
    {
        $service = app(VoiceService::class);
        $voice = $service->register(
            name: 'Was Classic', slug: 'was-classic', audioBytes: $this->silentWav(2.0), ext: 'wav',
            normalize: false, seed: null,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('longer than 5 seconds');

        $service->update(
            voice: $voice, name: 'Was Classic', slug: 'was-classic', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo',
        );
    }

    public function test_a_classic_voice_stores_a_null_model_and_no_preset(): void
    {
        $voice = app(VoiceService::class)->register(
            name: 'Classic', slug: 'classic', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox', presetVoice: 'Laura',
        );

        // The default engine stores as NULL (the pre-catalog shape), and a
        // preset is meaningless for an engine without built-in voices.
        $this->assertNull($voice->model);
        $this->assertNull($voice->settings['preset_voice'] ?? null);
    }

    public function test_update_writes_turbo_knob_defaults_and_preset(): void
    {
        $service = app(VoiceService::class);
        $voice = $service->register(
            name: 'Tunable', slug: 'tunable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo', presetVoice: 'Andy',
        );

        $voice = $service->update(
            voice: $voice, name: 'Tunable', slug: 'tunable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo', presetVoice: 'Meera',
            topP: 0.9, topK: 800, repetitionPenalty: 1.4,
        );

        $this->assertSame('Meera', $voice->settings['preset_voice']);
        $this->assertSame(0.9, $voice->settings['top_p']);
        $this->assertSame(800, $voice->settings['top_k']);
        $this->assertSame(1.4, $voice->settings['repetition_penalty']);
    }

    public function test_duplicate_and_clone_carry_the_engine_and_preset(): void
    {
        $owner = $this->admin();
        $other = User::factory()->create();
        $source = app(VoiceService::class)->register(
            name: 'Origin', slug: 'origin', audioBytes: null, ext: null,
            normalize: false, seed: null, ownerId: $owner->id,
            model: 'chatterbox-turbo', presetVoice: 'Gordon',
        );

        $copy = app(VoiceService::class)->duplicate($source, $owner->id);
        $clone = app(VoiceService::class)->cloneTo($source, $other->id);

        foreach ([$copy, $clone] as $derived) {
            $this->assertSame('chatterbox-turbo', $derived->model);
            $this->assertSame('Gordon', $derived->settings['preset_voice']);
        }
    }

    public function test_export_import_round_trip_keeps_the_engine(): void
    {
        $service = app(VoiceService::class);
        $source = $service->register(
            name: 'Portable', slug: 'portable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'chatterbox-turbo', presetVoice: 'Evelyn',
        );

        $zip = $service->export($source);
        $source->delete();

        $imported = $service->import($zip);

        $this->assertSame('chatterbox-turbo', $imported->model);
        $this->assertSame('Evelyn', $imported->settings['preset_voice']);
    }

    public function test_the_create_form_accepts_an_engine_and_preset(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.voices.store'), [
                'name' => 'Form Turbo',
                'model' => 'chatterbox-turbo',
                'preset_voice' => 'Chloe',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $voice = Voice::where('name', 'Form Turbo')->firstOrFail();
        $this->assertSame('chatterbox-turbo', $voice->model);
        $this->assertSame('Chloe', $voice->settings['preset_voice']);
    }

    public function test_the_form_rejects_a_preset_for_a_classic_voice(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.voices.create'))
            ->post(route('admin.voices.store'), [
                'name' => 'Bad Combo',
                'model' => 'chatterbox',
                'preset_voice' => 'Chloe',
            ])
            ->assertSessionHasErrors('preset_voice');
    }

    public function test_the_form_rejects_an_unknown_model(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.voices.create'))
            ->post(route('admin.voices.store'), [
                'name' => 'Bad Model',
                'model' => 'gpt-voice-9000',
            ])
            ->assertSessionHasErrors('model');
    }
}
