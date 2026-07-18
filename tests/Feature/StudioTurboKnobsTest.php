<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Tts\ParalinguisticTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Studio model-awareness: turbo knobs persist per chunk, presets carry an
 * engine and the turbo knob set, and a turbo project renders the turbo knob
 * group (with the classic pair hidden).
 */
class StudioTurboKnobsTest extends TestCase
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

    private function turboProject(): TtsProject
    {
        $voice = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);

        return app(ProjectService::class)->createFromText(
            title: 'Turbo project',
            voice: $voice,
            text: 'A sentence long enough to stand as a chunk of its own.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_a_preset_with_turbo_knobs_round_trips(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('admin.studio.presets.store'), [
            'name' => 'Turbo calm',
            'model' => 'chatterbox-turbo',
            'top_p' => 0.85,
            'top_k' => 300,
            'repetition_penalty' => 1.35,
            'temperature' => 0.7,
        ]);

        $response->assertOk()
            ->assertJsonPath('preset.top_p', 0.85)
            ->assertJsonPath('preset.top_k', 300)
            ->assertJsonPath('preset.repetition_penalty', 1.35)
            ->assertJsonPath('preset.model', 'chatterbox-turbo');

        $preset = TuningPreset::firstOrFail();
        $this->assertSame('chatterbox-turbo', $preset->model);
        $this->assertSame(0.85, $preset->top_p);
    }

    public function test_a_classic_preset_stores_a_null_model(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.studio.presets.store'), [
            'name' => 'Classic calm',
            'exaggeration' => 0.4,
        ])->assertOk()->assertJsonPath('preset.model', 'chatterbox');

        $this->assertNull(TuningPreset::firstOrFail()->model);
    }

    public function test_preset_rejects_out_of_range_turbo_knobs(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.studio.presets.store'), [
            'name' => 'Bad', 'top_p' => 0.2,
        ])->assertStatus(422);

        $this->actingAs($this->admin())->postJson(route('admin.studio.presets.store'), [
            'name' => 'Bad2', 'top_k' => 5000,
        ])->assertStatus(422);
    }

    public function test_per_chunk_turbo_knobs_persist_and_mark_the_chunk_stale(): void
    {
        $project = $this->turboProject();
        $chunk = $project->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.tuning', [$project, $chunk]), [
                'top_p' => 0.9,
                'top_k' => 500,
                'repetition_penalty' => 1.5,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stale');

        $chunk->refresh();
        $this->assertSame(0.9, $chunk->settings['top_p']);
        $this->assertSame(500, $chunk->settings['top_k']);
        $this->assertSame(1.5, $chunk->settings['repetition_penalty']);
    }

    public function test_turbo_knob_validation_rejects_out_of_range(): void
    {
        $project = $this->turboProject();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.tuning', [$project, $chunk]), ['top_k' => 0])
            ->assertStatus(422);
    }

    public function test_a_turbo_project_renders_the_turbo_knob_group(): void
    {
        $project = $this->turboProject();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->getContent();

        // Turbo knobs render visible (flex); the classic pair renders hidden.
        // The knob root gets EITHER flex OR hidden, never both (the JS then
        // toggles them as a pair on voice changes).
        $this->assertStringContainsString('data-knob="top_p"', $html);
        $this->assertStringContainsString('data-knob="repetition_penalty"', $html);
        $this->assertStringContainsString('data-model="chatterbox-turbo"', $html);
        $this->assertStringContainsString('hidden w-full" data-knob="exaggeration"', $html);
        $this->assertStringContainsString('flex w-full" data-knob="top_p"', $html);

        // Each knob now carries its own explanation — a one-line hint plus an ⓘ
        // popover — in place of the old shared help paragraph. Turbo's knobs
        // render visible (the flex check above); the classic pair renders hidden.
        $this->assertStringContainsString('nudge up if syllables stutter', $html);  // Rep. penalty hint
        $this->assertStringContainsString('Discourages repeated sounds', $html);     // Rep. penalty ⓘ popover

        // The sound-tag chips render visible on a turbo chunk, one per
        // supported tag, sourced from ParalinguisticTags::TAGS. A single
        // @class emits the one class attribute — a duplicate class attr would
        // be silently dropped by the browser and break the hidden state.
        $this->assertStringContainsString('data-engine-help="chatterbox-turbo" class="chunk-sound-tags"', $html);
        foreach (ParalinguisticTags::TAGS as $tag) {
            $this->assertStringContainsString('data-tag="['.$tag.']"', $html);
        }
    }

    public function test_a_classic_project_renders_the_classic_knob_help(): void
    {
        $voice = Voice::create(['slug' => 'classic-help', 'name' => 'Classic Help']);
        $project = app(ProjectService::class)->createFromText(
            title: 'Classic help',
            voice: $voice,
            text: 'A sentence long enough to stand as a chunk of its own.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );

        $html = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->getContent();

        // Classic knobs render visible; the turbo trio renders hidden. The
        // per-knob hint + ⓘ popover replace the old shared help paragraph.
        $this->assertStringContainsString('flex w-full" data-knob="exaggeration"', $html);
        $this->assertStringContainsString('hidden w-full" data-knob="top_p"', $html);
        $this->assertStringContainsString('higher = measured read, lower = quicker', $html); // CFG / Pace hint

        // The sound-tag chips row renders hidden for a classic chunk (classic
        // payloads strip the tags — they'd be read aloud).
        $this->assertStringContainsString('data-engine-help="chatterbox-turbo" class="chunk-sound-tags hidden"', $html);

        // The Delivery archetypes (the everyday control) render as chips, and the
        // per-engine value table is stashed for the JS to apply/match against.
        $this->assertStringContainsString('data-delivery="steady"', $html);
        $this->assertStringContainsString('data-delivery="balanced"', $html);
        $this->assertStringContainsString('data-delivery="expressive"', $html);
        $this->assertStringContainsString('data-delivery-presets=', $html);
        // Raw sliders sit behind the Fine-tune disclosure.
        $this->assertStringContainsString('finetune-toggle', $html);
    }

    public function test_a_new_project_delivery_preset_applies_turbo_knobs(): void
    {
        $admin = $this->admin();
        $voice = Voice::create(['slug' => 'turbo-d', 'name' => 'Turbo D', 'model' => 'chatterbox-turbo']);
        $preset = TuningPreset::create([
            'user_id' => $admin->id, 'name' => 'Snappy', 'model' => 'chatterbox-turbo',
            'top_p' => 0.8, 'top_k' => 200, 'repetition_penalty' => 1.6,
        ]);

        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'With delivery',
            'text' => 'A sentence long enough to stand as a chunk of its own.',
            'voice' => 'turbo-d',
            'preset' => $preset->id,
        ])->assertRedirect();

        $project = TtsProject::where('title', 'With delivery')->firstOrFail();
        $this->assertSame(0.8, $project->settings['top_p']);
        $this->assertSame(200, $project->settings['top_k']);
        $this->assertSame(1.6, $project->settings['repetition_penalty']);
    }
}
