<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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

    public function test_a_qwen_voice_with_a_preset_speaker_needs_no_clip(): void
    {
        $voice = app(VoiceService::class)->register(
            name: 'Qwen Preset', slug: 'qwen-preset', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Vivian',
        );

        $this->assertSame('qwen3-tts', $voice->model);
        $this->assertSame('replicate', $voice->provider);
        $this->assertSame('Vivian', $voice->settings['preset_voice']);
        $this->assertNull($voice->reference_audio_path);
    }

    public function test_a_qwen_voice_without_clip_or_preset_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('built-in');

        app(VoiceService::class)->register(
            name: 'Qwen Empty', slug: 'qwen-empty', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts',
        );
    }

    public function test_update_writes_and_clears_qwen_defaults_and_transcript(): void
    {
        $service = app(VoiceService::class);
        $voice = $service->register(
            name: 'Qwen Tunable', slug: 'qwen-tunable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Serena',
        );

        $voice = $service->update(
            voice: $voice, name: 'Qwen Tunable', slug: 'qwen-tunable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Serena',
            language: 'English', styleInstruction: '  speak slowly  ', referenceText: 'What the clip says.',
        );

        $this->assertSame('English', $voice->settings['language']);
        $this->assertSame('speak slowly', $voice->settings['style_instruction']);
        $this->assertSame('What the clip says.', $voice->settings['reference_text']);

        // `auto` language and blank strings clear back to inherit.
        $voice = $service->update(
            voice: $voice, name: 'Qwen Tunable', slug: 'qwen-tunable', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Serena',
            language: 'auto', styleInstruction: '   ', referenceText: null,
        );

        $this->assertArrayNotHasKey('language', $voice->settings);
        $this->assertArrayNotHasKey('style_instruction', $voice->settings);
        $this->assertArrayNotHasKey('reference_text', $voice->settings);
    }

    public function test_a_new_qwen_clip_auto_fills_the_transcript_via_asr(): void
    {
        config(['tts.asr.enabled' => true, 'tts.asr.url' => 'http://asr.test']);
        Http::fake(['asr.test/transcribe' => Http::response([
            'duration' => 4.0, 'text' => '  Hello from the clip.  ', 'words' => [], 'transcribe_ms' => 9,
        ])]);

        $voice = app(VoiceService::class)->register(
            name: 'Qwen Auto', slug: 'qwen-auto', audioBytes: $this->silentWav(4.0), ext: 'wav',
            normalize: false, seed: null, model: 'qwen3-tts',
        );

        $this->assertSame('Hello from the clip.', $voice->settings['reference_text']);
    }

    public function test_a_typed_transcript_beats_the_asr_auto_fill(): void
    {
        config(['tts.asr.enabled' => true, 'tts.asr.url' => 'http://asr.test']);
        Http::fake(['asr.test/transcribe' => Http::response([
            'duration' => 4.0, 'text' => 'Machine words.', 'words' => [], 'transcribe_ms' => 9,
        ])]);

        $service = app(VoiceService::class);
        $voice = $service->register(
            name: 'Qwen Typed', slug: 'qwen-typed', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Serena',
        );

        $voice = $service->update(
            voice: $voice, name: 'Qwen Typed', slug: 'qwen-typed',
            audioBytes: $this->silentWav(4.0), ext: 'wav',
            normalize: false, seed: null, model: 'qwen3-tts', presetVoice: 'Serena',
            referenceText: 'Human words.',
        );

        $this->assertSame('Human words.', $voice->settings['reference_text']);
        Http::assertNothingSent();
    }

    /** A qwen voice with a clip and a transcript, ready to have its clip swapped. */
    private function qwenVoiceWithTranscript(User $owner): Voice
    {
        // No ASR (nothing auto-fills the transcript) and no loudness pass
        // (ffmpeg isn't a given in tests) — this is about the transcript.
        config(['tts.asr.enabled' => false, 'tts.normalize_reference' => false]);

        $service = app(VoiceService::class);
        $voice = $service->register(
            name: 'Qwen Swap', slug: 'qwen-swap', audioBytes: $this->silentWav(6.0), ext: 'wav',
            normalize: false, seed: null, ownerId: $owner->id, model: 'qwen3-tts',
        );

        return $service->update(
            voice: $voice, name: 'Qwen Swap', slug: 'qwen-swap', audioBytes: null, ext: null,
            normalize: false, seed: null, model: 'qwen3-tts',
            referenceText: 'What the OLD clip said.',
        );
    }

    private function replaceClip(User $admin, Voice $voice, array $extra = []): TestResponse
    {
        return $this->actingAs($admin)->put(route('admin.voices.update', $voice), array_merge([
            'name' => 'Qwen Swap',
            'slug' => 'qwen-swap',
            'model' => 'qwen3-tts',
            'reference_text' => 'What the OLD clip said.',
            'audio' => UploadedFile::fake()->createWithContent('new.wav', $this->silentWav(6.0)),
            'clip_rights' => '1',
        ], $extra));
    }

    public function test_replacing_a_qwen_clip_warns_that_the_transcript_now_describes_the_old_take(): void
    {
        $admin = $this->admin();
        $voice = $this->qwenVoiceWithTranscript($admin);

        $this->replaceClip($admin, $voice)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error')
            ->assertSessionHas('warning', fn (?string $w) => str_contains((string) $w, 'still describes the old clip'));

        // Warned, not rewritten — the new take may well say the same words,
        // and the text is the user's.
        $this->assertSame('What the OLD clip said.', $voice->fresh()->settings['reference_text']);
    }

    public function test_no_warning_when_the_transcript_is_updated_in_the_same_save(): void
    {
        $admin = $this->admin();
        $voice = $this->qwenVoiceWithTranscript($admin);

        $this->replaceClip($admin, $voice, ['reference_text' => 'What the NEW clip says.'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertSame('What the NEW clip says.', $voice->fresh()->settings['reference_text']);
    }

    public function test_no_warning_without_a_clip_replacement_or_on_a_chatterbox_voice(): void
    {
        $admin = $this->admin();
        $voice = $this->qwenVoiceWithTranscript($admin);

        // Same transcript, no new clip: nothing has drifted.
        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'Qwen Swap', 'slug' => 'qwen-swap', 'model' => 'qwen3-tts',
            'reference_text' => 'What the OLD clip said.',
        ])->assertSessionMissing('warning');

        // Turbo never sends a transcript, so a swapped clip can't strand one.
        $this->replaceClip($admin, $voice->fresh(), ['model' => 'chatterbox-turbo'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');
    }

    public function test_no_auto_fill_when_asr_is_disabled_or_engine_is_chatterbox(): void
    {
        config(['tts.asr.enabled' => false]);
        Http::fake();

        $qwen = app(VoiceService::class)->register(
            name: 'Qwen NoAsr', slug: 'qwen-noasr', audioBytes: $this->silentWav(4.0), ext: 'wav',
            normalize: false, seed: null, model: 'qwen3-tts',
        );
        $this->assertArrayNotHasKey('reference_text', $qwen->settings ?? []);

        config(['tts.asr.enabled' => true, 'tts.asr.url' => 'http://asr.test']);
        $classic = app(VoiceService::class)->register(
            name: 'Classic NoFill', slug: 'classic-nofill', audioBytes: $this->silentWav(4.0), ext: 'wav',
            normalize: false, seed: null,
        );
        $this->assertArrayNotHasKey('reference_text', $classic->settings ?? []);
        Http::assertNothingSent();
    }

    public function test_presets_are_validated_against_the_selected_engine(): void
    {
        // A turbo preset name on a qwen voice — and vice versa — must fail:
        // each engine only accepts its OWN built-in list.
        $this->actingAs($this->admin())
            ->from(route('admin.voices.create'))
            ->post(route('admin.voices.store'), [
                'name' => 'Cross Preset',
                'model' => 'qwen3-tts',
                'preset_voice' => 'Laura',
            ])
            ->assertSessionHasErrors('preset_voice');

        $this->actingAs($this->admin())
            ->from(route('admin.voices.create'))
            ->post(route('admin.voices.store'), [
                'name' => 'Cross Preset 2',
                'model' => 'chatterbox-turbo',
                'preset_voice' => 'Vivian',
            ])
            ->assertSessionHasErrors('preset_voice');

        // The matching pair sails through.
        $this->actingAs($this->admin())
            ->post(route('admin.voices.store'), [
                'name' => 'Qwen Form',
                'model' => 'qwen3-tts',
                'preset_voice' => 'Serena',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $voice = Voice::where('name', 'Qwen Form')->firstOrFail();
        $this->assertSame('qwen3-tts', $voice->model);
        $this->assertSame('Serena', $voice->settings['preset_voice']);
    }
}
