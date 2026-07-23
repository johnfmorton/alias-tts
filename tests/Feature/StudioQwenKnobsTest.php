<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Tts\DeliveryPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Studio qwen-awareness: the string knobs (language/style_instruction) persist
 * per chunk, a qwen project renders the qwen control group with every numeric
 * knob, the seed row, the Delivery chips, and the sound tags hidden — and the
 * archetype table carries no qwen entries to match against.
 */
class StudioQwenKnobsTest extends TestCase
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

    private function qwenProject(): TtsProject
    {
        $voice = Voice::create([
            'slug' => 'qwen-v', 'name' => 'Qwen V', 'model' => 'qwen3-tts',
            'settings' => ['preset_voice' => 'Serena'],
        ]);

        return app(ProjectService::class)->createFromText(
            title: 'Qwen project',
            voice: $voice,
            text: 'A sentence long enough to stand as a chunk of its own.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_per_chunk_qwen_knobs_persist_via_regenerate(): void
    {
        $project = $this->qwenProject();
        $chunk = $project->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), [
                'language' => 'English',
                'style_instruction' => 'speak slowly and calmly',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $chunk->refresh();
        $this->assertSame('English', $chunk->settings['language']);
        $this->assertSame('speak slowly and calmly', $chunk->settings['style_instruction']);
    }

    public function test_blank_qwen_knobs_clear_back_to_inherit(): void
    {
        $project = $this->qwenProject();
        $chunk = $project->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), [
                'language' => 'English',
            ])->assertOk();
        $this->assertSame('English', $chunk->refresh()->settings['language']);

        // The panel always sends every knob key — null = clear back to inherit
        // (a body with NO panel keys leaves stored tuning untouched by design).
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['language' => null])
            ->assertOk();
        $this->assertArrayNotHasKey('language', $chunk->refresh()->settings ?? []);
    }

    public function test_qwen_knob_validation_rejects_bad_values(): void
    {
        $project = $this->qwenProject();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['language' => 'Klingon'])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['style_instruction' => str_repeat('a', 600)])
            ->assertStatus(422);
    }

    public function test_a_qwen_project_renders_the_qwen_control_group(): void
    {
        $project = $this->qwenProject();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->getContent();

        // Qwen's string controls render visible; every numeric knob — including
        // the shared temperature — renders hidden, and so does the seed row
        // (qwen's schema has no seed input).
        $this->assertStringContainsString('data-knob="language"', $html);
        $this->assertStringContainsString('data-knob="style_instruction"', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bflex\b[^"]*" data-knob="language"/', $html);
        $this->assertStringContainsString('hidden w-full" data-knob="exaggeration"', $html);
        $this->assertStringContainsString('hidden w-full" data-knob="top_p"', $html);
        $this->assertStringContainsString('hidden w-full" data-knob="temperature"', $html);
        $this->assertMatchesRegularExpression('/class="tuning-knob[^"]*\bhidden\b[^"]*" data-knob="seed"/', $html);

        // No Delivery archetypes and no sound tags for qwen.
        $this->assertMatchesRegularExpression('/class="chunk-delivery-wrap[^"]*\bhidden\b/', $html);
        $this->assertStringContainsString('data-engine-help="chatterbox-turbo" class="chunk-sound-tags hidden"', $html);
    }

    public function test_delivery_archetypes_are_empty_for_qwen(): void
    {
        $this->assertSame([], DeliveryPresets::forEngine('qwen3-tts'));
        $this->assertArrayHasKey('qwen3-tts', DeliveryPresets::all());
        $this->assertNotEmpty(DeliveryPresets::all()['chatterbox']);
    }

    public function test_bench_save_writes_a_style_note_as_voice_default(): void
    {
        $admin = $this->admin();
        $voice = Voice::create([
            'slug' => 'qwen-bench', 'name' => 'Qwen Bench', 'model' => 'qwen3-tts',
            'settings' => ['preset_voice' => 'Serena'], 'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson(route('admin.studio.voice-defaults'), [
            'voice' => 'qwen-bench',
            'style_instruction' => 'warm and unhurried',
        ])->assertOk();

        $this->assertSame('warm and unhurried', $voice->refresh()->settings['style_instruction']);
    }
}
