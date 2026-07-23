<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\SpeechService;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The engine choice (voices.model) must reach the provider as the reserved
 * settings key `model` on every path — and, just as critically, must stay
 * ABSENT for classic-chatterbox voices so their stored settings JSON and
 * cache hashes remain byte-identical to before the catalog existed.
 */
class ModelStampingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    /** @return TtsProvider&object{lastSettings: array} */
    private function capturingProvider(): TtsProvider
    {
        $provider = new class implements TtsProvider
        {
            /** @var array<string, mixed> */
            public array $lastSettings = [];

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->lastSettings = $settings;

                return 'RIFFfake';
            }

            public function outputContainer(?string $model = null): string
            {
                return 'wav';
            }
        };

        $this->app->instance(TtsProvider::class, $provider);

        return $provider;
    }

    private function projectFor(Voice $voice): TtsProject
    {
        return app(ProjectService::class)->createFromText(
            title: 'Stamping',
            voice: $voice,
            text: 'One sentence long enough to be a chunk on its own here.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_a_turbo_voice_stamps_the_engine_through_generate_chunk(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('chatterbox-turbo', $provider->lastSettings['model']);
    }

    public function test_a_classic_voice_produces_settings_without_a_model_key(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create(['slug' => 'classic-v', 'name' => 'Classic V']);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        // Cache-hash stability lock: stamping the default engine would change
        // every stored settings JSON and invalidate every cached speech.
        $this->assertArrayNotHasKey('model', $provider->lastSettings);
        $this->assertArrayNotHasKey('voice_preset', $provider->lastSettings);
    }

    public function test_a_preset_voice_name_rides_with_the_stamp(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create([
            'slug' => 'preset-v',
            'name' => 'Preset V',
            'model' => 'chatterbox-turbo',
            'settings' => ['preset_voice' => 'Laura'],
        ]);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('Laura', $provider->lastSettings['voice_preset']);
    }

    public function test_an_unknown_preset_name_is_not_stamped(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create([
            'slug' => 'bad-preset-v',
            'name' => 'Bad Preset V',
            'model' => 'chatterbox-turbo',
            'settings' => ['preset_voice' => 'NotARealPreset'],
        ]);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('chatterbox-turbo', $provider->lastSettings['model']);
        $this->assertArrayNotHasKey('voice_preset', $provider->lastSettings);
    }

    public function test_a_qwen_voice_stamps_the_engine_and_preset_speaker(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create([
            'slug' => 'qwen-v',
            'name' => 'Qwen V',
            'model' => 'qwen3-tts',
            'settings' => ['preset_voice' => 'Vivian', 'reference_text' => 'Ignored without a clip.'],
        ]);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('qwen3-tts', $provider->lastSettings['model']);
        $this->assertSame('Vivian', $provider->lastSettings['voice_preset']);
        // reference_text only matters (and only stamps) when a clip exists.
        $this->assertArrayNotHasKey('reference_text', $provider->lastSettings);
    }

    public function test_a_qwen_voice_with_a_clip_stamps_its_reference_text(): void
    {
        $provider = $this->capturingProvider();
        Storage::disk('local')->put('voices/qwen-clip.wav', 'RIFFfake');
        $voice = Voice::create([
            'slug' => 'qwen-clip-v',
            'name' => 'Qwen Clip V',
            'model' => 'qwen3-tts',
            'reference_audio_path' => 'voices/qwen-clip.wav',
            'settings' => ['reference_text' => 'What the clip says.'],
        ]);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('What the clip says.', $provider->lastSettings['reference_text']);
    }

    public function test_a_classic_voice_never_stamps_reference_text(): void
    {
        $provider = $this->capturingProvider();
        Storage::disk('local')->put('voices/classic-clip.wav', 'RIFFfake');
        $voice = Voice::create([
            'slug' => 'classic-clip-v',
            'name' => 'Classic Clip V',
            'reference_audio_path' => 'voices/classic-clip.wav',
            'settings' => ['reference_text' => 'Chatterbox has no such input.'],
        ]);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        // The settings map must stay byte-identical for classic voices.
        $this->assertArrayNotHasKey('reference_text', $provider->lastSettings);
    }

    public function test_a_per_chunk_voice_override_switches_the_engine_for_that_chunk(): void
    {
        $provider = $this->capturingProvider();
        $classic = Voice::create(['slug' => 'proj-v', 'name' => 'Proj V']);
        $turbo = Voice::create(['slug' => 'chunk-turbo', 'name' => 'Chunk Turbo', 'model' => 'chatterbox-turbo']);

        $chunk = $this->projectFor($classic)->chunks()->first();
        $chunk->update(['voice_id' => $turbo->id]);

        app(ProjectService::class)->generateChunk($chunk->refresh());

        $this->assertSame('chatterbox-turbo', $provider->lastSettings['model']);
    }

    public function test_an_unknown_voice_model_falls_back_to_classic(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create(['slug' => 'stale-v', 'name' => 'Stale V', 'model' => 'discontinued-model']);

        $chunk = $this->projectFor($voice)->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->assertArrayNotHasKey('model', $provider->lastSettings);
    }

    public function test_the_engine_separates_the_speech_cache(): void
    {
        $key = ApiKey::generate('stamp-test');
        $voice = Voice::create(['slug' => 'cache-v', 'name' => 'Cache V']);

        $service = app(SpeechService::class);
        $args = [$key, $voice, 'Cache me if you can.', [], 'chatterbox', 'mp3_44100_128'];

        $classic = $service->synthesize(...$args);
        $classicAgain = $service->synthesize(...$args);
        $turbo = $service->synthesize(...[...$args, null, false, 'chatterbox-turbo']);

        // Identical request -> cached; same request on another engine -> a new record.
        $this->assertSame($classic->id, $classicAgain->id);
        $this->assertNotSame($classic->id, $turbo->id);
        $this->assertSame('chatterbox-turbo', $turbo->settings['model'] ?? null);
    }

    public function test_the_resolver_passes_and_casts_the_turbo_knobs(): void
    {
        $voice = Voice::create(['slug' => 'knob-v', 'name' => 'Knob V', 'model' => 'chatterbox-turbo']);

        $resolved = app(VoiceSettingsResolver::class)->resolve($voice, [
            'top_p' => '0.9',
            'top_k' => '500',
            'repetition_penalty' => '1.4',
        ]);

        $this->assertSame(0.9, $resolved['top_p']);
        $this->assertSame(500, $resolved['top_k']);
        $this->assertSame(1.4, $resolved['repetition_penalty']);
    }
}
