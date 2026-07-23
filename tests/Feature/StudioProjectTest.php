<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Models\PronunciationEntry;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\ProjectService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudioProjectTest extends TestCase
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

    /** A 2-chunk project (two paragraphs, each long enough to stand alone). */
    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'My project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    /** A TtsProvider spy that records the reference path passed to each call. */
    private function spyProvider(): TtsProvider
    {
        $spy = new class extends FakeTtsProvider
        {
            /** @var list<?string> */
            public array $refs = [];

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->refs[] = $referenceAudio;

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $spy);

        return $spy;
    }

    /** Swap in an AudioConverter that records each concatenate() call's seam gaps. */
    private function recordingConverter(): AudioConverter
    {
        $recorder = new class extends AudioConverter
        {
            /** @var list<array<int, int>> one entry per concatenate() call */
            public array $seamGaps = [];

            public function concatenate(array $inputChunks, string $outputFormat, string $inputContainer = 'wav', array $seamGapsMs = [], array $preserveTails = [], array $metadata = []): array
            {
                $this->seamGaps[] = $seamGapsMs;

                return ['stitched', 'audio/mpeg', 'mp3'];
            }
        };
        $this->app->instance(AudioConverter::class, $recorder);

        return $recorder;
    }

    /**
     * A 3-chunk project shaped like a paragraph split across A+B followed by C:
     * A ends on a sentence break, B carries the paragraph break, all generated.
     *
     * @return array{0: TtsProject, 1: TtsChunk, 2: TtsChunk, 3: TtsChunk}
     */
    private function paragraphSplitProject(User $admin): array
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->orderBy('position')->get();
        $first->update(['break_after' => 'sentence']);
        $second->update(['break_after' => 'paragraph']);
        $third = TtsChunk::create([
            'tts_project_id' => $project->id,
            'position' => 2,
            'text' => 'A closing paragraph long enough to be its own chunk.',
            'break_after' => 'sentence',
            'status' => ChunkStatus::Pending,
            'characters' => 52,
        ]);

        foreach ([$first, $second, $third] as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        return [$project, $first, $second, $third];
    }

    /** A 2-chunk project bound to the given (or a fresh cloning) voice. */
    private function projectWithVoice(Voice $voice): TtsProject
    {
        return app(ProjectService::class)->createFromText(
            title: 'Testing voices',
            voice: $voice,
            text: "First paragraph long enough to stand alone as its own chunk here.\n\n".
                  'Second paragraph also long enough to be its own separate chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_create_page_requires_admin(): void
    {
        $this->get(route('admin.studio.projects.create'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.studio.projects.create'))
            ->assertOk();
    }

    public function test_create_page_lists_the_default_voice_first_and_preselects_it(): void
    {
        // The pre-selected option must also be the FIRST option — a picker whose
        // first item is not the selected one reads as a mistake. pickerOrder pins
        // the built-in default to the top even when a cloning voice sorts before
        // it alphabetically, and the form still pre-selects it explicitly.
        Voice::create(['slug' => 'aaa', 'name' => 'Aardvark', 'reference_audio_path' => 'voices/aaa.wav']);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSee('value="default" selected', false)
            ->assertDontSee('value="aaa" selected', false)
            ->assertSeeInOrder(['value="default"', 'value="aaa"'], false);
    }

    public function test_create_page_links_to_the_voices_panel(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSee('Manage voices')
            ->assertSee(route('admin.voices.index'));
    }

    public function test_store_creates_project_with_chunks(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())->post(route('admin.studio.projects.store'), [
            'title' => 'Doc',
            'voice' => 'v',
            'text' => "First paragraph that is comfortably long enough.\n\nSecond paragraph that is also long enough.",
        ]);

        $project = TtsProject::firstWhere('title', 'Doc');
        $this->assertNotNull($project);
        $res->assertRedirect(route('admin.studio.projects.show', $project));
        $this->assertCount(2, $project->chunks);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame(ChunkStatus::Pending, $project->chunks->first()->status);
    }

    public function test_store_uses_the_users_project_output_format_setting(): void
    {
        // End-to-end through the ApplyUserSettings middleware: the per-user
        // "Final audio format" setting decides a new project's output_format.
        $admin = $this->admin();
        Voice::create(['slug' => 'v', 'name' => 'V']);
        UserSetting::create(['user_id' => $admin->id, 'key' => 'tts.project_output_format', 'value' => 'wav_44100']);

        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Wav doc',
            'voice' => 'v',
            'text' => 'A single paragraph that is comfortably long enough to chunk.',
        ]);

        $this->assertSame('wav_44100', TtsProject::firstWhere('title', 'Wav doc')->output_format);
    }

    public function test_store_defaults_to_mp3_without_a_format_setting(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($this->admin())->post(route('admin.studio.projects.store'), [
            'title' => 'Mp3 doc',
            'voice' => 'v',
            'text' => 'A single paragraph that is comfortably long enough to chunk.',
        ]);

        $this->assertSame('mp3_44100_128', TtsProject::firstWhere('title', 'Mp3 doc')->output_format);
    }

    public function test_store_voices_quotes_when_the_setting_is_on(): void
    {
        // End-to-end through the ApplyUserSettings middleware: the per-user
        // "Spoken quote marks" setting rewrites paired quotes in the chunked
        // text while source_text keeps what the writer actually typed.
        $admin = $this->admin();
        Voice::create(['slug' => 'v', 'name' => 'V']);
        UserSetting::create(['user_id' => $admin->id, 'key' => 'tts.spoken_quotes', 'value' => 'open_close']);

        $text = 'He said, "Hello there." Then he left without another word.';
        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Quoted doc',
            'voice' => 'v',
            'text' => $text,
        ]);

        $project = TtsProject::firstWhere('title', 'Quoted doc');
        $this->assertSame(
            'He said, open quote, Hello there, close quote. Then he left without another word.',
            $project->normalized_text
        );
        $this->assertSame($text, $project->source_text);
    }

    public function test_store_leaves_quotes_alone_by_default(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $text = 'He said, "Hello there." Then he left without another word.';
        $this->actingAs($this->admin())->post(route('admin.studio.projects.store'), [
            'title' => 'Plain doc',
            'voice' => 'v',
            'text' => $text,
        ]);

        $this->assertSame($text, TtsProject::firstWhere('title', 'Plain doc')->normalized_text);
    }

    public function test_reset_applies_the_spoken_quotes_setting(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        UserSetting::create(['user_id' => $admin->id, 'key' => 'tts.spoken_quotes', 'value' => 'open_close']);

        $newText = 'She replied, "On my way." And that settled the whole matter nicely.';
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.reset', $project), ['text' => $newText])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $project->refresh();
        $this->assertSame(
            'She replied, open quote, On my way, close quote. And that settled the whole matter nicely.',
            $project->normalized_text
        );
        $this->assertSame($newText, $project->source_text);
    }

    public function test_a_delivery_preset_seeds_the_projects_tuning(): void
    {
        $admin = $this->admin();
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $preset = TuningPreset::create(['user_id' => $admin->id, 'name' => 'Excited', 'exaggeration' => 1.6, 'cfg_weight' => 0.9]);

        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Doc',
            'voice' => 'v',
            'preset' => $preset->id,
            'text' => 'A single paragraph that is comfortably long enough to chunk.',
        ]);

        $settings = TtsProject::firstWhere('title', 'Doc')->settings;
        $this->assertSame(1.6, $settings['exaggeration']);
        $this->assertSame(0.9, $settings['cfg_weight']);
    }

    public function test_another_users_preset_id_is_ignored(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['is_super_admin' => false]);
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $preset = TuningPreset::create(['user_id' => $other->id, 'name' => 'Theirs', 'exaggeration' => 1.6]);

        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Doc',
            'voice' => 'v',
            'preset' => $preset->id,
            'text' => 'A single paragraph that is comfortably long enough to chunk.',
        ]);

        $this->assertArrayNotHasKey('exaggeration', TtsProject::firstWhere('title', 'Doc')->settings);
    }

    public function test_editor_page_renders(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee($project->title)
            ->assertSee('Build final')
            ->assertSee($project->chunks()->first()->text);
    }

    public function test_update_renames_project(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.update', $project), ['title' => '  Renamed project  '])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('title', 'Renamed project'); // trimmed

        $this->assertSame('Renamed project', $project->refresh()->title);
    }

    public function test_update_rejects_blank_title(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.update', $project), ['title' => '   '])
            ->assertStatus(422);

        $this->assertSame('My project', $project->refresh()->title);
    }

    public function test_generate_chunk_persists_audio_and_is_selective(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get()->all();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $first->refresh();
        $second->refresh();

        // Only the targeted chunk was generated.
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertNotNull($first->audio_path);
        $this->assertTrue(Storage::disk('local')->exists($first->audio_path));
        $this->assertSame(ChunkStatus::Pending, $second->status);
        $this->assertNull($second->audio_path);
    }

    public function test_changing_project_voice_marks_generated_chunks_stale(): void
    {
        $project = $this->project();
        $first = $project->chunks()->first();
        $other = Voice::create(['slug' => 'other', 'name' => 'Other']);

        // Generate one chunk so there is audio tied to the old voice.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'other'])
            ->assertOk()
            ->assertJsonPath('voice', 'other');

        $project->refresh();
        $this->assertSame($other->id, $project->voice_id);
        // The generated chunk no longer matches the new voice -> Stale; the
        // ungenerated one stays Pending.
        $this->assertSame(ChunkStatus::Stale, $first->refresh()->status);
        $this->assertSame(ChunkStatus::Pending, $project->chunks()->orderBy('position')->get()->last()->status);
    }

    public function test_chunk_generated_after_voice_change_uses_the_new_voice_reference(): void
    {
        // Reproduces the reported bug: generate a chunk with a cloning voice,
        // switch the project to a reference-less voice, then generate another
        // chunk — it must be synthesized with a NULL reference (native voice),
        // not the previous voice's clip. (The built-in defaults now ship with a
        // bundled clip, so a freshly created reference-less voice exercises the
        // null-reference path here.)
        $spy = $this->spyProvider();

        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $narrator = Voice::create(['slug' => 'narrator', 'name' => 'Narrator', 'reference_audio_path' => null]);
        $project = $this->projectWithVoice($john);
        [$first, $second] = $project->chunks()->orderBy('position')->get()->all();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => $narrator->slug])
            ->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.chunks.generate', [$project, $second]))
            ->assertOk();

        $this->assertStringContainsString('john.wav', (string) $spy->refs[0], 'First chunk used the John reference.');
        $this->assertNull($spy->refs[array_key_last($spy->refs)], 'After switching to a reference-less voice, the chunk must be generated with a null (native) reference.');
    }

    public function test_default_voice_chunk_uses_the_bundled_reference(): void
    {
        // The built-in default is no longer reference-less: a chunk on the default
        // voice must be synthesized WITH the bundled reference clip, not a null
        // (native) reference. This is the fix for the reported bug where a
        // "Default voice" chunk came out sounding like a cloned voice.
        $spy = $this->spyProvider();
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $first = $project->chunks()->orderBy('position')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk();

        $this->assertStringContainsString(
            'default.wav',
            (string) $spy->refs[array_key_last($spy->refs)],
            'The default voice chunk must use the bundled default reference clip.',
        );
    }

    public function test_per_chunk_voice_override_is_used_instead_of_the_project_voice(): void
    {
        // A chunk pinned to its own voice must generate with THAT voice's clip,
        // even though the project voice is the reference-less default.
        $spy = $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $first = $project->chunks()->orderBy('position')->first();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $first]), ['voice' => 'john'])
            ->assertOk()
            ->assertJsonPath('inherits', false)
            ->assertJsonPath('voice', 'john');

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk();

        $this->assertStringContainsString('john.wav', (string) $spy->refs[array_key_last($spy->refs)]);
    }

    public function test_changing_project_voice_leaves_explicitly_voiced_chunks_untouched(): void
    {
        $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice($john); // both chunks inherit John
        [$inherited, $pinned] = $project->chunks()->orderBy('position')->get()->all();
        $admin = $this->admin();

        // Pin the second chunk explicitly to John, then generate both.
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $pinned]), ['voice' => 'john'])
            ->assertOk();
        foreach ([$inherited, $pinned] as $c) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $c]))->assertOk();
        }

        // Switch the project to the default voice.
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => Voice::defaultSlug()])
            ->assertOk();

        // The inheriting chunk is now Stale; the explicitly-pinned one is untouched.
        $this->assertSame(ChunkStatus::Stale, $inherited->refresh()->status);
        $this->assertSame(ChunkStatus::Completed, $pinned->refresh()->status);
        $this->assertSame($john->id, $pinned->voice_id);
    }

    public function test_clearing_a_chunk_voice_restores_project_inheritance(): void
    {
        $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $first = $project->chunks()->orderBy('position')->first();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $first]), ['voice' => 'john'])
            ->assertOk();
        $this->assertSame($john->id, $first->refresh()->voice_id);

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $first]), ['voice' => ''])
            ->assertOk()
            ->assertJsonPath('inherits', true);
        $this->assertNull($first->refresh()->voice_id);
    }

    public function test_show_page_renders_a_per_chunk_voice_picker_mirroring_the_project_voice(): void
    {
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice($john);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('class="chunk-voice', false)
            // An un-overridden chunk inherits, so its picker mirrors the project voice.
            ->assertSee('data-inherits="1"', false);
    }

    public function test_show_page_exposes_the_background_run_endpoints(): void
    {
        // Pacing moved server-side with the background run (GenerateProjectChunksJob);
        // the page instead carries the dispatch/poll URLs and the resume flag.
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('data-generate-remaining-url="'.route('admin.studio.projects.generate-remaining', $project).'"', false)
            ->assertSee('data-generation-status-url="'.route('admin.studio.projects.generation-status', $project).'"', false)
            ->assertSee('data-active-run="0"', false)
            ->assertSee('id="project-generate-stop"', false)
            // Per-chunk Regenerate joins an active run through this endpoint.
            ->assertSee('data-queue-url="'.route('admin.studio.projects.chunks.queue', [$project, $project->chunks()->first()]).'"', false);
    }

    public function test_show_page_flags_an_active_background_run(): void
    {
        $project = $this->project();
        TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'chunk_ids' => $project->chunks()->pluck('id')->all(),
            'chunks_total' => 2,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('data-active-run="1"', false);
    }

    public function test_show_page_renders_the_voice_picker(): void
    {
        $project = $this->project();
        Voice::create(['slug' => 'other', 'name' => 'Other']);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('id="project-voice"', false)
            ->assertSee('Other');
    }

    public function test_changing_project_voice_rejects_unknown_voice(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown voice.');
    }

    public function test_show_page_renders_the_format_picker(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('id="project-format"', false)
            ->assertSee('value="mp3_44100_128"', false)
            ->assertSee('value="wav_44100"', false);
    }

    public function test_changing_project_format_updates_it_and_stales_the_built_final(): void
    {
        // Build a final (Ready, mp3) first, then switch the format. The chunk audio
        // is untouched, but the built final no longer matches — so the project goes
        // Stale to prompt a rebuild, and the column carries the new format.
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->assertSame(ProjectStatus::Ready, $project->refresh()->status);
        $this->assertSame('mp3_44100_128', $project->output_format);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.format', $project), ['output_format' => 'wav_44100'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('output_format', 'wav_44100')
            ->assertJsonPath('project_status', ProjectStatus::Stale->value);

        $project->refresh();
        $this->assertSame('wav_44100', $project->output_format);
        $this->assertSame(ProjectStatus::Stale, $project->status);
    }

    public function test_changing_format_to_the_same_value_is_a_noop(): void
    {
        // No change → no restale: a Ready project stays Ready when the picker is
        // "set" to the format it already has.
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.format', $project), ['output_format' => 'mp3_44100_128'])
            ->assertOk()
            ->assertJsonPath('project_status', ProjectStatus::Ready->value);

        $this->assertSame(ProjectStatus::Ready, $project->refresh()->status);
    }

    public function test_changing_project_format_rejects_an_unknown_format(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.format', $project), ['output_format' => 'ogg_48000'])
            ->assertStatus(422);

        $this->assertSame('mp3_44100_128', $project->refresh()->output_format);
    }

    public function test_chunk_audio_is_served_after_generation(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));

        $res = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.chunks.audio', [$project, $chunk]));

        $res->assertOk();
        $this->assertStringStartsWith('audio/wav', (string) $res->headers->get('content-type'));
    }

    public function test_rebuild_requires_all_chunks_generated(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $project->chunks()->first()]));

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 chunk(s) still need to be generated before rebuilding.');
    }

    public function test_rebuild_stitches_and_serves_final(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $project->refresh();
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertNotNull($project->final_audio_path);
        $this->assertTrue(Storage::disk('local')->exists($project->final_audio_path));

        $audio = $this->actingAs($this->admin())->get(route('admin.studio.projects.audio', $project));
        $audio->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
    }

    public function test_rebuild_stamps_title_date_and_voice_into_mp3_metadata(): void
    {
        // project() is titled "My project" and uses the single voice "V".
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $project->refresh();

        $tags = $this->id3Tags(Storage::disk('local')->get($project->final_audio_path));
        $this->assertSame('My project', $tags['title'] ?? null);
        $this->assertSame('Created with Alias TTS · Voice: V', $tags['comment'] ?? null);
        $this->assertStringStartsWith((string) now()->year, $tags['date'] ?? '');
    }

    public function test_rebuild_lists_every_distinct_voice_in_the_comment(): void
    {
        $project = $this->project();
        // Give the second chunk an override voice so the final mixes two voices.
        $other = Voice::create(['slug' => 'w', 'name' => 'Wren']);
        [$first, $second] = $project->chunks()->orderBy('position')->get();
        $second->update(['voice_id' => $other->id]);

        foreach ([$first, $second] as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $project->refresh();

        $tags = $this->id3Tags(Storage::disk('local')->get($project->final_audio_path));
        $this->assertSame('Created with Alias TTS · Voices: V, Wren', $tags['comment'] ?? null);
    }

    /**
     * Read a media file's container-level tags via ffprobe.
     *
     * @return array<string, string> lower-cased tag name => value
     */
    private function id3Tags(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'tts_probe_');
        try {
            file_put_contents($file, $bytes);

            $process = new Process([
                'ffprobe', '-hide_banner', '-loglevel', 'error',
                '-show_entries', 'format_tags', '-of', 'json', $file,
            ]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'ffprobe failed: '.$process->getErrorOutput());

            $tags = json_decode($process->getOutput(), true)['format']['tags'] ?? [];

            return array_change_key_case(is_array($tags) ? $tags : [], CASE_LOWER);
        } finally {
            @unlink($file);
        }
    }

    public function test_audio_endpoints_honor_range_requests(): void
    {
        // Without range support iOS Safari can't seek the player (it shows
        // "Live Broadcast" for the MP3 final and errors on a WAV chunk).
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $project->refresh();

        // Final MP3: a full request advertises ranges; a Range request gets a 206 slice.
        $finalBytes = Storage::disk('local')->get($project->final_audio_path);
        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.audio', $project))
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');

        $ranged = $this->actingAs($this->admin())
            ->withHeaders(['Range' => 'bytes=0-9'])
            ->get(route('admin.studio.projects.audio', $project));
        $ranged->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($finalBytes));
        $this->assertSame(substr($finalBytes, 0, 10), $ranged->getContent());

        // Per-chunk WAV endpoint is range-aware too.
        $chunk = $project->chunks()->whereNotNull('audio_path')->first();
        $chunkBytes = Storage::disk('local')->get($chunk->audio_path);
        $chunkRanged = $this->actingAs($this->admin())
            ->withHeaders(['Range' => 'bytes=0-3'])
            ->get(route('admin.studio.projects.chunks.audio', [$project, $chunk]));
        $chunkRanged->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-3/'.strlen($chunkBytes));
        $this->assertSame(substr($chunkBytes, 0, 4), $chunkRanged->getContent());
    }

    public function test_preview_stitches_selected_chunks_without_persisting(): void
    {
        $project = $this->project();
        $chunks = $project->chunks()->get();
        foreach ($chunks as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $res = $this->actingAs($this->admin())->postJson(route('admin.studio.projects.preview', $project), [
            'chunks' => $chunks->pluck('id')->all(),
        ]);

        $res->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
        // Preview must not write a final file.
        $this->assertNull($project->refresh()->final_audio_path);
    }

    public function test_preview_rejects_ungenerated_selection(): void
    {
        $project = $this->project(); // chunks exist but none generated

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.preview', $project), [
                'chunks' => $project->chunks()->pluck('id')->all(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select at least one generated chunk to preview.');
    }

    // --- Skip in final assembly ---------------------------------------------------

    public function test_a_chunk_can_be_skipped_and_included_again(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('skipped', true);
        $this->assertTrue($chunk->refresh()->skipped);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => false])
            ->assertOk()
            ->assertJsonPath('skipped', false);
        $this->assertFalse($chunk->refresh()->skipped);
    }

    public function test_skip_toggle_validates_the_flag(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), [])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertFalse($chunk->refresh()->skipped);
    }

    public function test_skip_toggle_is_a_404_for_a_foreign_chunk(): void
    {
        $project = $this->project();
        $foreignChunk = $this->project()->chunks()->orderBy('position')->first();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $foreignChunk]), ['skipped' => true])
            ->assertNotFound();
    }

    public function test_skipping_a_chunk_marks_the_final_stale(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        app(ProjectService::class)->seal($project, $admin);
        $chunk = $project->chunks()->orderBy('position')->first();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
            ->assertOk()
            ->assertJsonPath('project_status', ProjectStatus::Stale->value);
        $project->refresh();
        $this->assertSame(ProjectStatus::Stale, $project->status);
        $this->assertNull($project->sealed_at); // the sealed final no longer reflects intent

        // Re-sending the SAME state is a no-op: it must not stale a fresh final.
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
            ->assertOk()
            ->assertJsonPath('project_status', ProjectStatus::Ready->value);
    }

    public function test_rebuild_excludes_skipped_chunks(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        $bothChunks = strlen(Storage::disk('local')->get($project->final_audio_path));

        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
            ->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $oneChunk = strlen(Storage::disk('local')->get($project->refresh()->final_audio_path));
        $this->assertLessThan($bothChunks, $oneChunk);
    }

    public function test_rebuild_ignores_missing_audio_on_skipped_chunks(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        [$first, $second] = $project->chunks()->orderBy('position')->get();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $first]));

        // Ungenerated, but skipped — it isn't part of the stitch, so it must not
        // block the rebuild the way an ungenerated included chunk does.
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $second]), ['skipped' => true])
            ->assertOk();

        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->assertSame(ProjectStatus::Ready, $project->refresh()->status);
    }

    public function test_a_skipped_chunks_break_still_sizes_the_seam_gap(): void
    {
        // A (sentence) · B (paragraph, skipped) · C — the pause where B used to
        // be must keep B's paragraph gap, not collapse to A's sentence gap: the
        // text boundary between A and C is still a paragraph boundary.
        config(['tts.chunk_gap_ms' => 120, 'tts.paragraph_gap_ms' => 400, 'tts.chunk_mode' => 'packed']);
        $admin = $this->admin();
        [$project, , $second] = $this->paragraphSplitProject($admin);

        $recorder = $this->recordingConverter();

        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->assertSame([120, 400, 120], $recorder->seamGaps[0]);

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $second]), ['skipped' => true])
            ->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->assertSame([400, 120], $recorder->seamGaps[1]);
    }

    public function test_preview_paces_a_seam_across_a_skipped_chunk_like_the_final(): void
    {
        // Previewing A+C while B (paragraph break) is skipped must use the same
        // folded gap the rebuilt final will, or the audition misrepresents it.
        config(['tts.chunk_gap_ms' => 120, 'tts.paragraph_gap_ms' => 400, 'tts.chunk_mode' => 'packed']);
        $admin = $this->admin();
        [$project, $first, $second, $third] = $this->paragraphSplitProject($admin);
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $second]), ['skipped' => true])
            ->assertOk();

        $recorder = $this->recordingConverter();

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.preview', $project), ['chunks' => [$first->id, $third->id]])
            ->assertOk();
        $this->assertSame([400, 120], $recorder->seamGaps[0]);
    }

    public function test_rebuild_paces_a_mid_sentence_seam_as_a_breath_not_a_paragraph(): void
    {
        // Regression: a bulleted list reflowed into one running sentence leaves a
        // stored 'paragraph' break on now-unterminated text ("…containing" →
        // "the Studio editor; …"). The rebuild must read the FINAL chunk text and
        // insert only the small continuation breath there, not the 400 ms
        // paragraph pause its stale break would otherwise earn.
        config([
            'tts.chunk_gap_ms' => 120,
            'tts.paragraph_gap_ms' => 400,
            'tts.continuation_gap_ms' => 50,
            'tts.chunk_mode' => 'packed',
        ]);
        $admin = $this->admin();
        $project = $this->project();
        [$first, $second] = $project->chunks()->orderBy('position')->get();
        $first->update([
            'text' => 'Around the generation pipeline is a Laravel application containing',
            'break_after' => 'paragraph', // the stale block break the reflow left behind
        ]);
        $second->update([
            'text' => 'the Studio editor; eleven labs- and more; and seven hundred automated tests.',
            'break_after' => 'sentence',
        ]);

        foreach ([$first, $second] as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $recorder = $this->recordingConverter();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        // Seam after the first chunk = a breath (50 ms), not the stale 400 ms.
        $this->assertSame([50, 120], $recorder->seamGaps[0]);
    }

    public function test_rebuild_refuses_when_every_chunk_is_skipped(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($admin)
                ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Every chunk is skipped — include at least one chunk before rebuilding.');
    }

    public function test_preview_excludes_skipped_chunks(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunks = $project->chunks()->orderBy('position')->get();
        foreach ($chunks as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $ids = $chunks->pluck('id')->all();

        $both = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.preview', $project), ['chunks' => $ids])
            ->assertOk()
            ->getContent();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunks->first()]), ['skipped' => true])
            ->assertOk();

        // The same selection now stitches one chunk fewer.
        $withSkip = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.preview', $project), ['chunks' => $ids])
            ->assertOk()
            ->getContent();
        $this->assertLessThan(strlen($both), strlen($withSkip));
    }

    public function test_preview_rejects_an_all_skipped_selection(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunks = $project->chunks()->orderBy('position')->get();
        foreach ($chunks as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
            $this->actingAs($admin)
                ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $chunk]), ['skipped' => true])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.preview', $project), ['chunks' => $chunks->pluck('id')->all()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select at least one generated chunk to preview.');
    }

    public function test_duplicate_copies_the_skip_flag(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        [$first, $second] = $project->chunks()->orderBy('position')->get();
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $first]), ['skipped' => true])
            ->assertOk();

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copyChunks = $this->duplicateOf($project)->chunks()->orderBy('position')->get();
        $this->assertTrue($copyChunks[0]->skipped);
        $this->assertFalse($copyChunks[1]->skipped);
    }

    public function test_editing_a_chunk_marks_it_and_the_project_stale(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project));

        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $chunk]), ['text' => 'A rewritten sentence here.'])
            ->assertOk()
            ->assertJsonPath('status', 'stale')
            ->assertJsonPath('project_status', 'stale')
            ->assertJsonPath('rechunked', false); // short edit fits one chunk

        $this->assertSame(ChunkStatus::Stale, $chunk->refresh()->status);
        $this->assertSame('A rewritten sentence here.', $chunk->text);
    }

    public function test_inserting_a_chunk_shifts_positions_and_creates_empty_pending(): void
    {
        $project = $this->project(); // positions 0, 1

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 1])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $chunks = $project->chunks()->get();
        $this->assertCount(3, $chunks);
        $this->assertSame([0, 1, 2], $chunks->pluck('position')->all());

        $inserted = $chunks[1];
        $this->assertSame('', $inserted->text);
        $this->assertSame(0, $inserted->characters);
        $this->assertSame(ChunkStatus::Pending, $inserted->status);
    }

    public function test_inserting_at_boundaries_keeps_positions_contiguous(): void
    {
        $project = $this->project();

        // Lead (0) then append (current count).
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 0])->assertOk();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => $project->chunks()->count()])->assertOk();

        $this->assertSame([0, 1, 2, 3], $project->chunks()->pluck('position')->all());
    }

    public function test_insert_rejects_out_of_range_position(): void
    {
        $project = $this->project(); // 2 chunks → valid positions 0..2

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 99])
            ->assertStatus(422);

        $this->assertCount(2, $project->chunks()->get());
    }

    public function test_deleting_a_chunk_removes_it_and_renumbers_the_rest(): void
    {
        $project = $this->project(); // positions 0, 1
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 2])->assertOk();
        // Now positions 0, 1, 2 — delete the middle one.
        $middle = $project->chunks()->where('position', 1)->first();

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.destroy', [$project, $middle]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $chunks = $project->chunks()->get();
        $this->assertCount(2, $chunks);
        $this->assertSame([0, 1], $chunks->pluck('position')->all());
        $this->assertNull(TtsChunk::find($middle->id));
    }

    public function test_deleting_a_chunk_removes_its_take_files(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 2])->assertOk();

        // Generate the last chunk so it has a take (row + file on disk).
        $target = $project->chunks()->orderBy('position')->get()->last();
        $target->update(['text' => 'Some words long enough to render into audio here.']);
        app(ProjectService::class)->generateChunk($target->refresh());
        $audioPath = $target->refresh()->audio_path;
        $takeId = $target->takes()->first()->id;
        Storage::disk('local')->assertExists($audioPath);

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.destroy', [$project, $target]))
            ->assertOk();

        // The take row cascaded and its file is gone.
        $this->assertNull(TtsChunkTake::find($takeId));
        Storage::disk('local')->assertMissing($audioPath);
    }

    public function test_deleting_the_only_chunk_is_refused(): void
    {
        $project = $this->project();
        // Collapse to a single chunk, then try to delete it.
        $project->chunks()->where('position', '>', 0)->delete();
        $only = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.destroy', [$project, $only]))
            ->assertStatus(422);

        $this->assertCount(1, $project->chunks()->get());
    }

    public function test_deleting_a_chunk_from_another_project_is_404(): void
    {
        $mine = $this->project();
        $other = $this->projectWithVoice(Voice::create(['slug' => 'other', 'name' => 'Other']));
        $otherChunk = $other->chunks()->first();

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.destroy', [$mine, $otherChunk]))
            ->assertNotFound();
    }

    public function test_single_chunk_project_hides_the_delete_control(): void
    {
        $project = $this->project();
        $project->chunks()->where('position', '>', 0)->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertDontSee('chunk-delete-confirm');
    }

    public function test_generate_rejects_empty_chunk(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 0])->assertOk();

        $empty = $project->chunks()->where('position', 0)->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $empty]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This chunk is empty — add text before generating.');
    }

    public function test_long_edit_splits_chunk_and_preserves_sibling_audio(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get()->all();

        // Generate both; capture the SECOND (sibling) chunk's audio.
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $first]))->assertOk();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $second]))->assertOk();
        $siblingAudio = $second->refresh()->audio_path;
        $this->assertNotNull($siblingAudio);
        $this->assertTrue(Storage::disk('local')->exists($siblingAudio));

        // Edit the first chunk with text far over the ~280-char budget.
        $long = str_repeat('A reasonably long sentence that contributes plenty of characters here. ', 8);
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $first]), ['text' => $long])
            ->assertOk()
            ->assertJsonPath('rechunked', true);

        // The chunk list grew, positions stay contiguous.
        $positions = $project->chunks()->pluck('position')->all();
        $this->assertGreaterThan(2, count($positions));
        $this->assertSame(range(0, count($positions) - 1), $positions);

        // The edited chunk (had audio) is Stale; the untouched sibling keeps its audio.
        $this->assertSame(ChunkStatus::Stale, $first->refresh()->status);
        $second->refresh();
        $this->assertSame($siblingAudio, $second->audio_path);
        $this->assertTrue(Storage::disk('local')->exists($siblingAudio));
        $this->assertTrue($second->position > $first->position); // sibling shifted down
    }

    public function test_split_preserves_paragraph_break_on_the_last_piece(): void
    {
        $project = $this->project();
        $first = $project->chunks()->first();
        $this->assertSame('paragraph', $first->break_after); // precondition: ends a paragraph

        // A single long paragraph (no blank line) so internal seams are sentences.
        $long = str_repeat('This sentence is fairly long and adds plenty of characters to exceed the budget. ', 6);
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $first]), ['text' => $long])
            ->assertOk()
            ->assertJsonPath('rechunked', true);

        $chunks = $project->chunks()->get();
        // First piece is a within-block sentence seam...
        $this->assertSame('sentence', $chunks->first()->break_after);
        // ...and the last split piece (just before the original 2nd chunk) inherits paragraph.
        $this->assertSame('paragraph', $chunks[$chunks->count() - 2]->break_after);
    }

    public function test_reset_rechunks_text_and_wipes_audio(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $project->refresh();
        $oldAudio = $project->chunks()->whereNotNull('audio_path')->pluck('audio_path')->all();
        $finalPath = $project->final_audio_path;
        $voiceId = $project->voice_id;
        $this->assertNotEmpty($oldAudio);
        $this->assertNotNull($finalPath);

        $newText = "Brand new first paragraph that stands comfortably on its own.\n\n".
                   'Brand new second paragraph that is also long enough to be its own chunk.';

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.reset', $project), ['text' => $newText])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $project->refresh();
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->final_audio_path);
        $this->assertSame($voiceId, $project->voice_id); // voice preserved
        $this->assertSame($newText, $project->source_text);
        $this->assertStringContainsString('Brand new first paragraph', $project->chunks()->first()->text);
        $this->assertFalse($project->chunks()->where('status', '!=', 'pending')->exists());

        // All previous audio (chunks + final) is gone from disk.
        foreach ($oldAudio as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path));
        }
        $this->assertFalse(Storage::disk('local')->exists($finalPath));
    }

    public function test_edit_page_renders_source_text(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.edit', $project))
            ->assertOk()
            ->assertSee('Start over')
            ->assertSee('This is the first paragraph');
    }

    public function test_destroy_removes_project_and_audio(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $path = $chunk->refresh()->audio_path;

        $this->actingAs($this->admin())
            ->delete(route('admin.studio.projects.destroy', $project))
            ->assertRedirect(route('admin.studio.index'));

        $this->assertNull(TtsProject::find($project->id));
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    // --- Phase 2: per-chunk tuning overrides + re-roll -------------------------

    /** A provider that records the settings it's handed (and returns junk bytes,
     *  which generateChunk just stores raw). */
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

    public function test_generate_persists_the_tuning_panel_before_rendering(): void
    {
        $provider = $this->capturingProvider();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        // The Studio's Regenerate submits the whole panel with the click; the
        // server persists it BEFORE synthesis so the render uses these values.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), [
                'exaggeration' => 1.2,
                'cfg_weight' => 0.9,
                'temperature' => null,
                'seed' => 777,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $chunk->refresh();
        $this->assertSame(1.2, $chunk->settings['exaggeration']);
        $this->assertSame(0.9, $chunk->settings['cfg_weight']);
        $this->assertSame(777, $chunk->settings['seed']);
        $this->assertSame(1.2, $provider->lastSettings['exaggeration']);
        $this->assertSame(0.9, $provider->lastSettings['cfg_weight']);
        $this->assertSame(777, $provider->lastSettings['seed']);
    }

    public function test_generate_rejects_out_of_range_tuning_without_rendering(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['exaggeration' => 3])
            ->assertStatus(422);

        // Validation failed before synthesis — no take, no tuning written.
        $this->assertSame(0, $chunk->refresh()->takes()->count());
        $this->assertNull($chunk->settings);
    }

    public function test_generate_without_panel_keys_leaves_stored_tuning_untouched(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $chunk->update(['settings' => ['exaggeration' => 1.4]]);

        // A bare POST (older callers, tests) must not wipe the stored override —
        // only a request carrying panel keys rewrites it.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertOk();

        $this->assertSame(1.4, $chunk->refresh()->settings['exaggeration']);
    }

    public function test_generate_with_blank_panel_clears_the_stored_override(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $chunk->update(['settings' => ['exaggeration' => 1.4, 'seed' => 42]]);

        // The panel always submits every knob; blank ones ride as null = inherit.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), [
                'exaggeration' => null,
                'cfg_weight' => null,
                'seed' => null,
            ])
            ->assertOk();

        $this->assertNull($chunk->refresh()->settings);
    }

    public function test_chunk_override_overlays_project_settings_at_generation(): void
    {
        $provider = $this->capturingProvider();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->updateChunkTuning($chunk, ['stability' => 0.9, 'style' => null]);
        app(ProjectService::class)->generateChunk($chunk->refresh());

        $this->assertSame(0.9, $provider->lastSettings['stability']);       // chunk override wins
        $this->assertSame(0.75, $provider->lastSettings['similarity_boost']); // project/config kept
    }

    public function test_panel_seed_overrides_the_project_pin_and_blank_restores_it(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'P',
            voice: $voice,
            text: 'A single short line to speak.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: 123,
        );
        $chunk = $project->chunks()->first();

        app(ProjectService::class)->generateChunk($chunk);
        $this->assertSame(123, $provider->lastSettings['seed']);

        // A seed typed into the panel outranks the project pin…
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['seed' => 999])
            ->assertOk();
        $this->assertSame(999, $provider->lastSettings['seed']);

        // …and blanking the field falls back to the project pin again.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk->refresh()]), ['seed' => null])
            ->assertOk();
        $this->assertSame(123, $provider->lastSettings['seed']);
    }

    // ---- Take history -------------------------------------------------------

    public function test_generate_creates_a_committed_take_and_selects_it(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertOk()
            ->assertJsonCount(1, 'takes')
            ->assertJsonPath('takes.0.source', 'generate')
            ->assertJsonPath('takes.0.selected', true)
            // The recorded length reaches the panel, so the player can print the
            // duration without loading metadata (fake provider = 0.2s of silence).
            ->assertJsonPath('takes.0.duration_ms', 200);

        $take = $chunk->refresh()->takes()->first();
        $this->assertSame('generate', $take->source);
        $this->assertSame($chunk->audio_path, $take->audio_path); // the take IS the chunk audio
        $this->assertSame(200, $take->duration_ms); // measured from the WAV header at record time
        $this->assertTrue(Storage::disk('local')->exists($take->audio_path));
    }

    public function test_regenerate_appends_a_new_take_keeping_history(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertOk();

        $chunk->refresh();
        $this->assertSame(2, $chunk->takes()->count()); // the losing take is kept
        $newest = $chunk->takes()->first();
        $this->assertSame('generate', $newest->source);
        $this->assertSame($chunk->audio_path, $newest->audio_path); // newest take is selected
    }

    public function test_selecting_a_take_makes_it_the_chunk_audio(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);
        $svc->generateChunk($chunk);            // take A (selected)
        $svc->generateChunk($chunk->refresh()); // take B (now selected)
        $chunk->refresh();

        $takeA = $chunk->takes()->where('audio_path', '!=', $chunk->audio_path)->first(); // the non-selected take
        $this->assertNotNull($takeA);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $takeA]))
            ->assertOk()
            ->assertJsonPath('selected_take_id', $takeA->id);

        $this->assertSame($takeA->audio_path, $chunk->refresh()->audio_path);
    }

    public function test_selecting_a_take_restores_its_text_and_tuning_snapshot(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);

        // Take A: rendered with an explicit override + pinned seed.
        $svc->updateChunkTuning($chunk, ['exaggeration' => 1.3, 'seed' => 42]);
        $svc->generateChunk($chunk->refresh());
        $chunk->refresh();
        $takeA = $chunk->takes()->first();
        $textA = $chunk->text;

        // Take B: different text, override cleared back to inherited.
        $svc->updateChunkText($chunk, 'Completely different words now.');
        $svc->updateChunkTuning($chunk->refresh(), ['exaggeration' => null, 'seed' => null]);
        $svc->generateChunk($chunk->refresh());
        $chunk->refresh();
        $this->assertNull($chunk->settings);

        // Selecting take A brings back its whole snapshot — text, knobs, seed —
        // and the response mirrors it so the panel can update in place.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $takeA]))
            ->assertOk()
            ->assertJsonPath('text', $textA)
            ->assertJsonPath('tuning.exaggeration', 1.3)
            ->assertJsonPath('seed', 42);

        $chunk->refresh();
        $this->assertSame($textA, $chunk->text);
        $this->assertSame(mb_strlen($textA), $chunk->characters);
        $this->assertSame(1.3, $chunk->settings['exaggeration']);
        $this->assertSame(42, $chunk->settings['seed']);

        // And selecting take B restores the inherited state (no override at all).
        $takeB = $chunk->takes()->where('id', '!=', $takeA->id)->first();
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $takeB]))
            ->assertOk()
            ->assertJsonPath('tuning.exaggeration', null)
            ->assertJsonPath('seed', null);
        $chunk->refresh();
        $this->assertSame('Completely different words now.', $chunk->text);
        $this->assertNull($chunk->settings);
    }

    public function test_a_take_records_the_effective_voice_it_rendered_with(): void
    {
        $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);

        // An inheriting chunk records the PROJECT voice on its take.
        $svc->generateChunk($chunk);
        $this->assertSame($project->voice_id, $chunk->refresh()->takes()->latest('created_at')->first()->voice_id);

        // A pinned chunk records the OVERRIDE voice on its take.
        $svc->setChunkVoice($chunk->refresh(), $john);
        $svc->generateChunk($chunk->refresh());
        $this->assertSame($john->id, $chunk->refresh()->takes()->latest('created_at')->first()->voice_id);
    }

    public function test_selecting_a_take_restores_the_voice_it_rendered_with(): void
    {
        $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);

        // Take A on the (inherited) project voice; take B after pinning John.
        $svc->generateChunk($chunk);
        $chunk->refresh();
        $takeA = $chunk->takes()->first();
        $this->assertSame($project->voice_id, $takeA->voice_id);

        $svc->setChunkVoice($chunk, $john);
        $svc->generateChunk($chunk->refresh());
        $this->assertSame($john->id, $chunk->refresh()->voice_id);

        // Selecting take A restores its voice explicitly, stays Completed (the audio
        // WAS made with that voice), and the response carries the slug for the picker.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $takeA]))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('voice', Voice::defaultSlug());

        $chunk->refresh();
        $this->assertSame($project->voice_id, $chunk->voice_id);
        $this->assertSame(ChunkStatus::Completed, $chunk->status);
    }

    public function test_selecting_a_take_without_a_recorded_voice_keeps_the_chunk_voice(): void
    {
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->setChunkVoice($chunk, $john); // explicit John

        // A legacy take carries no voice_id (recorded before the column existed).
        $take = $chunk->takes()->create([
            'audio_path' => 'takes/'.Str::uuid().'.wav',
            'voice_id' => null,
            'text' => $chunk->text,
            'source' => 'legacy',
            'characters' => mb_strlen($chunk->text),
        ]);

        // Selecting it leaves the chunk's current voice in place, as with null text.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $take]))
            ->assertOk()
            ->assertJsonPath('voice', 'john');
        $this->assertSame($john->id, $chunk->refresh()->voice_id);
    }

    public function test_generate_persists_the_pending_voice_from_the_panel(): void
    {
        $spy = $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $chunk = $project->chunks()->orderBy('position')->first();

        // The picker is now a pending edit that rides on Regenerate: the chunk voice
        // is persisted before the render, which then uses that voice's clip.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['voice' => 'john'])
            ->assertOk();

        $this->assertSame($john->id, $chunk->refresh()->voice_id);
        $this->assertStringContainsString('john.wav', (string) $spy->refs[array_key_last($spy->refs)]);
    }

    public function test_generate_rejects_an_unknown_pending_voice_without_rendering(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]), ['voice' => 'nope'])
            ->assertStatus(422);

        $this->assertSame(0, $chunk->refresh()->takes()->count());
    }

    public function test_generate_without_a_voice_key_leaves_the_chunk_voice_untouched(): void
    {
        $this->spyProvider();
        $john = Voice::create(['slug' => 'john', 'name' => 'John', 'reference_audio_path' => 'voices/john.wav']);
        $project = $this->projectWithVoice(Voice::resolve(Voice::defaultSlug()));
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->setChunkVoice($chunk, $john);

        // A bare POST (no `voice` key) must not disturb the pinned voice.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertOk();

        $this->assertSame($john->id, $chunk->refresh()->voice_id);
    }

    public function test_take_tuning_labels_name_the_delivery_archetype_or_custom_mix(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        // Each take carries an exact knob override; its label should read as the
        // matching Delivery archetype (Steady/Balanced/Expressive) or spell out a
        // "Custom: …" mix. Created directly so one chunk holds every case at once.
        // Turbo takes are recognised by their own knobs (top_p/top_k/…), so they
        // read correctly even on this classic-voiced chunk. [$override, $expected]:
        $cases = [
            [['exaggeration' => 0.50, 'cfg_weight' => 0.50, 'temperature' => 0.80], 'Balanced'],
            [['exaggeration' => 0.35, 'cfg_weight' => 0.65, 'temperature' => 0.65], 'Steady'],
            [['exaggeration' => 0.85, 'cfg_weight' => 0.40, 'temperature' => 1.00], 'Expressive'],
            [['exaggeration' => 0.62, 'cfg_weight' => 0.55, 'temperature' => 0.90], 'Custom: exaggeration 0.62 · cfg/pace 0.55 · temp 0.90'],
            [['temperature' => 0.65, 'top_p' => 0.85, 'top_k' => 500, 'repetition_penalty' => 1.30], 'Steady'],
            [['temperature' => 0.90, 'top_p' => 0.90, 'top_k' => 800, 'repetition_penalty' => 1.25], 'Custom: top-p 0.90 · top-k 800 · rep. penalty 1.25 · temp 0.90'],
        ];

        $expected = [];
        foreach ($cases as [$override, $label]) {
            $take = $chunk->takes()->create([
                'audio_path' => 'takes/'.Str::uuid().'.wav',
                'text' => $chunk->text,
                'settings' => $override,
                'source' => 'generate',
                'characters' => mb_strlen($chunk->text),
            ]);
            $expected[$take->id] = $label;
        }

        // An empty override inherited the neutral defaults — which ARE Balanced,
        // so it reads as the archetype rather than a bare "inherited".
        $inherited = $chunk->takes()->create([
            'audio_path' => 'takes/'.Str::uuid().'.wav',
            'text' => $chunk->text,
            'settings' => null,
            'source' => 'duplicate',
            'characters' => mb_strlen($chunk->text),
        ]);
        $expected[$inherited->id] = 'Balanced';

        $labels = collect(
            $this->actingAs($this->admin())
                ->getJson(route('admin.studio.projects.chunks.takes.index', [$project, $chunk]))
                ->assertOk()
                ->json('takes')
        )->pluck('tuning_label', 'id');

        foreach ($expected as $id => $label) {
            $this->assertSame($label, $labels[$id], "take {$id}");
        }
    }

    public function test_an_inherited_take_reads_balanced_from_its_chunk_turbo_voice(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        // With no engine-naming knobs of its own, an empty override falls back to
        // the chunk's current voice engine; a turbo voice resolves it against
        // turbo's neutral defaults — still the Balanced archetype.
        $turbo = Voice::create(['slug' => 'turbo', 'name' => 'Turbo', 'model' => 'chatterbox-turbo']);
        $chunk->update(['voice_id' => $turbo->id]);
        $chunk->takes()->create([
            'audio_path' => 'takes/'.Str::uuid().'.wav',
            'text' => $chunk->text,
            'settings' => null,
            'source' => 'generate',
            'characters' => mb_strlen($chunk->text),
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.chunks.takes.index', [$project, $chunk]))
            ->assertOk()
            ->assertJsonPath('takes.0.tuning_label', 'Balanced');
    }

    public function test_selecting_a_legacy_take_without_text_keeps_the_chunk_text(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);
        $svc->generateChunk($chunk);
        $svc->generateChunk($chunk->refresh());
        $chunk->refresh();

        // A pre-snapshot take (before the text column existed) carries no text.
        $legacy = $chunk->takes()->where('audio_path', '!=', $chunk->audio_path)->first();
        $legacy->update(['text' => null]);
        $before = $chunk->text;

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.takes.select', [$project, $chunk, $legacy]))
            ->assertOk();

        // The audio switched, the words were left alone.
        $chunk->refresh();
        $this->assertSame($legacy->audio_path, $chunk->audio_path);
        $this->assertSame($before, $chunk->text);
    }

    public function test_cannot_delete_the_selected_take(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);
        $selected = $chunk->refresh()->takes()->first();

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.takes.delete', [$project, $chunk, $selected]))
            ->assertStatus(422);

        $this->assertDatabaseHas('tts_chunk_takes', ['id' => $selected->id]);
    }

    public function test_deleting_a_take_removes_its_file_and_row(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);
        $svc->generateChunk($chunk);
        $svc->generateChunk($chunk->refresh());
        $chunk->refresh();

        $old = $chunk->takes()->where('audio_path', '!=', $chunk->audio_path)->first(); // not the selected take
        $path = $old->audio_path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.studio.projects.chunks.takes.delete', [$project, $chunk, $old]))
            ->assertOk();

        $this->assertDatabaseMissing('tts_chunk_takes', ['id' => $old->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_retention_prunes_old_committed_takes_keeping_the_selected(): void
    {
        config(['tts.takes.keep' => 2, 'tts.takes.keep_preview' => 1]);
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);

        $svc->generateChunk($chunk);
        for ($i = 0; $i < 5; $i++) {
            $svc->generateChunk($chunk->refresh()); // 6 committed takes total
        }
        $chunk->refresh();

        // keep=2 history + the always-kept selected take = 3 rows; the rest pruned.
        $takes = $chunk->takes()->get();
        $this->assertCount(3, $takes);
        $this->assertSame($chunk->audio_path, $takes->first()->audio_path); // newest = selected, kept

        // Every surviving row still has its file, and no orphan files dangle.
        foreach ($takes as $t) {
            $this->assertTrue(Storage::disk('local')->exists($t->audio_path));
        }
        $dir = dirname($takes->first()->audio_path);
        $this->assertCount(3, Storage::disk('local')->files($dir));
    }

    public function test_legacy_preview_takes_are_pruned_more_aggressively_than_committed(): void
    {
        config(['tts.takes.keep' => 5, 'tts.takes.keep_preview' => 1]);
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc = app(ProjectService::class);
        $svc->generateChunk($chunk); // one committed take, selected
        $chunk->refresh();

        // Rows left behind by the retired Preview button: still pruned on their
        // own, harsher lane so old databases drain them without evicting real takes.
        for ($i = 0; $i < 4; $i++) {
            $path = dirname($chunk->audio_path)."/legacy-preview-{$i}.wav";
            Storage::disk('local')->put($path, 'RIFFxxxx');
            $chunk->takes()->create([
                'id' => (string) Str::orderedUuid(),
                'audio_path' => $path,
                'text' => $chunk->text,
                'settings' => ['stability' => 0.5],
                'source' => 'preview',
                'characters' => mb_strlen($chunk->text),
            ]);
        }

        $svc->generateChunk($chunk->refresh()); // recording a take runs the prune
        $chunk->refresh();

        // keep_preview=1 → only the newest legacy preview survives; the committed
        // takes are untouched (keep=5).
        $this->assertSame(1, $chunk->takes()->where('source', 'preview')->count());
        $this->assertSame(2, $chunk->takes()->where('source', 'generate')->count());
    }

    public function test_take_audio_endpoint_honors_range_requests(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);
        $take = $chunk->refresh()->takes()->first();
        $bytes = Storage::disk('local')->get($take->audio_path);

        $ranged = $this->actingAs($this->admin())
            ->withHeaders(['Range' => 'bytes=0-3'])
            ->get(route('admin.studio.projects.chunks.takes.audio', [$project, $chunk, $take]));
        $ranged->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-3/'.strlen($bytes));
        $this->assertSame(substr($bytes, 0, 4), $ranged->getContent());
    }

    public function test_setting_a_native_knob_drops_the_paired_el_key(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        // A legacy chunk that still carries ElevenLabs-style keys.
        $chunk->update(['settings' => ['stability' => 0.8, 'style' => 0.3]]);

        app(ProjectService::class)->updateChunkTuning($chunk, ['exaggeration' => 1.2, 'cfg_weight' => 0.7]);

        $chunk->refresh();
        $this->assertSame(1.2, $chunk->settings['exaggeration']);
        $this->assertSame(0.7, $chunk->settings['cfg_weight']);
        // Writing the native knobs drops their stale EL twins so the chunk never
        // carries both forms (the provider would prefer native anyway).
        $this->assertArrayNotHasKey('style', $chunk->settings);
        $this->assertArrayNotHasKey('stability', $chunk->settings);
    }

    public function test_legacy_chunk_with_el_keys_still_generates_via_fallback(): void
    {
        $provider = $this->capturingProvider();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $chunk->update(['settings' => ['stability' => 0.8, 'style' => 0.3]]);

        app(ProjectService::class)->generateChunk($chunk->refresh());

        $this->assertSame(ChunkStatus::Completed, $chunk->refresh()->status);
        // The EL keys reach the provider, which derives Chatterbox's native knobs
        // from them (the mapping itself is covered by ChatterboxTuningTest).
        $this->assertSame(0.8, $provider->lastSettings['stability']);
        $this->assertSame(0.3, $provider->lastSettings['style']);
    }

    public function test_legacy_take_backfill_creates_a_take_for_existing_audio(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);
        $audioPath = $chunk->refresh()->audio_path;

        // Re-run the takes migration against the already-generated chunk, as a real
        // deploy would: drop + recreate the table so up()'s backfill runs over the
        // existing audio (the chunk's audio_path is untouched by the rollback).
        // Twenty-seven steps because these all sit on top of the takes table
        // (native presets, project-seal, bundled default voices, account
        // fields, two-factor/connected-accounts, the unowned-api-key reassignment,
        // project ownership, the magic-login-table drop, per-user settings,
        // per-user voices, per-user presets, the take-text snapshot, the
        // default-clip replacement, the voice-clips staging table, the per-user
        // slug scoping, the preset-temperature column, the spent-characters
        // counters, the take-duration column, the turbo preset knobs, the
        // per-model spend counters, the per-chunk skip flag, the credit
        // system, the project-jobs table, the generation-timings table, the
        // voice-clip status column, and the take-voice column).
        Artisan::call('migrate:rollback', ['--step' => 30]);
        Artisan::call('migrate', ['--force' => true]);

        $takes = $chunk->refresh()->takes()->get();
        $this->assertCount(1, $takes);
        $this->assertSame('legacy', $takes->first()->source);
        $this->assertSame($audioPath, $takes->first()->audio_path); // references the file in place
        // The duration migration's backfill also re-ran, reading this take's WAV
        // header from storage (fake provider = 0.2s of silence).
        $this->assertSame(200, $takes->first()->duration_ms);
    }

    // --- Duplicate project ------------------------------------------------------

    /** Generate every chunk and build the final, returning the refreshed project. */
    private function generateAndBuild(TtsProject $project, User $admin): TtsProject
    {
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        return $project->refresh();
    }

    /** The project created by the duplicate POST (the only row besides the source). */
    private function duplicateOf(TtsProject $source): TtsProject
    {
        return TtsProject::where('id', '!=', $source->id)->latest('id')->firstOrFail();
    }

    public function test_duplicate_creates_an_independent_copy(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $response = $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $response->assertRedirect(route('admin.studio.projects.show', $copy));

        $this->assertSame('My project copy', $copy->title);
        $this->assertSame($admin->id, $copy->user_id);
        $this->assertSame(ProjectStatus::Ready, $copy->status);

        $disk = Storage::disk('local');

        // The final is an independent, byte-identical file under the copy's tree.
        $this->assertNotSame($project->final_audio_path, $copy->final_audio_path);
        $this->assertStringContainsString('/projects/'.$copy->id.'/', $copy->final_audio_path);
        $this->assertSame($disk->get($project->final_audio_path), $disk->get($copy->final_audio_path));

        // Every chunk copied in order, each with its own byte-identical file.
        $sourceChunks = $project->chunks()->get();
        $copyChunks = $copy->chunks()->get();
        $this->assertCount($sourceChunks->count(), $copyChunks);
        foreach ($copyChunks as $i => $copyChunk) {
            $sourceChunk = $sourceChunks[$i];
            $this->assertSame($sourceChunk->text, $copyChunk->text);
            $this->assertSame(ChunkStatus::Completed, $copyChunk->status);
            $this->assertNotSame($sourceChunk->audio_path, $copyChunk->audio_path);
            $this->assertStringContainsString('/projects/'.$copy->id.'/chunks/'.$copyChunk->id.'/takes/', $copyChunk->audio_path);
            $this->assertSame($disk->get($sourceChunk->audio_path), $disk->get($copyChunk->audio_path));
        }
    }

    public function test_duplicate_copies_only_the_selected_take(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->assertCount(3, $chunk->refresh()->takes()->get());

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $copyChunk = $copy->chunks()->orderBy('position')->first();
        $takes = $copyChunk->takes()->get();
        $this->assertCount(1, $takes);
        $this->assertSame('duplicate', $takes->first()->source);
        $this->assertSame($copyChunk->audio_path, $takes->first()->audio_path);
        $this->assertSame($chunk->text, $takes->first()->text);
        $this->assertSame(200, $takes->first()->duration_ms); // carried from the copied take
        $this->assertSame(
            Storage::disk('local')->get($chunk->audio_path),
            Storage::disk('local')->get($copyChunk->audio_path),
        );
    }

    public function test_duplicate_clears_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        app(ProjectService::class)->seal($project, $admin);
        $project->refresh();

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $this->assertFalse($copy->isSealed());
        $this->assertNull($copy->final_sha256);
        $this->assertNull($copy->final_bytes);
        $this->assertNull($copy->sealed_audio_path);
        $this->assertNull($copy->sealed_at);
        $this->assertNull($copy->sealed_by_id);
        $this->assertSame([], Storage::disk('local')->allFiles('speech/projects/'.$copy->id.'/sealed'));

        // The copy still carries the final audio; the source's seal is untouched.
        $this->assertSame(ProjectStatus::Ready, $copy->status);
        $this->assertTrue(Storage::disk('local')->exists($copy->final_audio_path));
        $this->assertTrue($project->refresh()->isSealed());
        $this->assertTrue(Storage::disk('local')->exists($project->sealed_audio_path));
    }

    public function test_deleting_the_original_does_not_affect_the_duplicate(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));
        $copy = $this->duplicateOf($project);
        $copyPaths = $copy->chunks()->pluck('audio_path')->push($copy->final_audio_path)->all();

        // Deleting a chunk in the original wipes only its own directory…
        $this->actingAs($admin)
            ->deleteJson(route('admin.studio.projects.chunks.destroy', [$project, $project->chunks()->first()]))
            ->assertOk();
        // …and deleting the whole original wipes only its own tree.
        $this->actingAs($admin)->delete(route('admin.studio.projects.destroy', $project));
        $this->assertNull(TtsProject::find($project->id));

        foreach ($copyPaths as $path) {
            $this->assertTrue(Storage::disk('local')->exists($path), "missing: {$path}");
        }
        $this->actingAs($admin)->get(route('admin.studio.projects.show', $copy))->assertOk();
    }

    public function test_duplicate_resets_ownership_and_recovery_metadata(): void
    {
        $project = $this->project();
        $project->update([
            'api_key_id' => '11111111-1111-1111-1111-111111111111',
            'origin' => 'api_failure',
            'source_speech_id' => '22222222-2222-2222-2222-222222222222',
            'failure_reason' => 'Provider exploded',
            'failed_chunk_index' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $this->assertSame($admin->id, $copy->user_id);
        $this->assertNull($copy->api_key_id);
        $this->assertNull($copy->origin);
        $this->assertNull($copy->source_speech_id);
        $this->assertNull($copy->failure_reason);
        $this->assertNull($copy->failed_chunk_index);
        $this->assertNull($copy->expires_at);
        // An ungenerated source stays Draft with no final on the copy.
        $this->assertSame(ProjectStatus::Draft, $copy->status);
        $this->assertNull($copy->final_audio_path);
    }

    public function test_duplicate_of_a_legacy_chunk_without_a_take_row(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        // A pre-takes-era chunk: audio in place at chunks/{id}.wav, no take rows.
        $legacyPath = 'speech/projects/'.$project->id.'/chunks/'.$chunk->id.'.wav';
        Storage::disk('local')->put($legacyPath, 'RIFFlegacybytes');
        $chunk->update(['audio_path' => $legacyPath, 'status' => ChunkStatus::Completed]);

        $this->actingAs($this->admin())->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $copyChunk = $copy->chunks()->orderBy('position')->first();
        $this->assertSame(ChunkStatus::Completed, $copyChunk->status);
        $this->assertStringContainsString('/chunks/'.$copyChunk->id.'/takes/', $copyChunk->audio_path);
        $this->assertSame('RIFFlegacybytes', Storage::disk('local')->get($copyChunk->audio_path));

        $takes = $copyChunk->takes()->get();
        $this->assertCount(1, $takes);
        $this->assertSame('duplicate', $takes->first()->source);
        $this->assertSame($chunk->text, $takes->first()->text);
    }

    public function test_duplicate_tolerates_a_missing_chunk_file(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        [$first, $second] = $project->chunks()->get();
        Storage::disk('local')->delete($first->audio_path);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.duplicate', $project))
            ->assertRedirect();

        $copy = $this->duplicateOf($project);
        [$copyFirst, $copySecond] = $copy->chunks()->get();

        // The chunk whose file vanished is downgraded to Pending, not fatal…
        $this->assertSame(ChunkStatus::Pending, $copyFirst->status);
        $this->assertNull($copyFirst->audio_path);
        $this->assertCount(0, $copyFirst->takes()->get());

        // …its sibling copies normally, and the final (still on disk) carries
        // over as Stale since it no longer matches every chunk.
        $this->assertSame(ChunkStatus::Completed, $copySecond->status);
        $this->assertSame(
            Storage::disk('local')->get($second->audio_path),
            Storage::disk('local')->get($copySecond->audio_path),
        );
        $this->assertSame(ProjectStatus::Stale, $copy->status);
        $this->assertTrue(Storage::disk('local')->exists($copy->final_audio_path));
    }

    public function test_duplicate_requires_project_access(): void
    {
        $owner = User::factory()->create(['is_super_admin' => false]);
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'Private project',
            voice: $voice,
            text: 'A paragraph long enough to become a chunk of its very own here.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $owner->id,
        );

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->post(route('admin.studio.projects.duplicate', $project))
            ->assertForbidden();

        $this->assertSame(1, TtsProject::count());
    }

    public function test_duplicate_of_a_foreign_project_copies_its_voices(): void
    {
        // Voices are per user, so a SuperAdmin duplicating another user's
        // project must receive clones of its voices — project-level AND
        // per-chunk overrides — or the copy references voices they can't reach.
        $owner = User::factory()->create(['is_super_admin' => false]);
        $projectVoice = Voice::create([
            'user_id' => $owner->id,
            'slug' => 'narrator',
            'name' => 'Narrator',
            'settings' => ['exaggeration' => 0.7],
            'reference_audio_path' => "voices/u{$owner->id}/narrator.wav",
        ]);
        Storage::disk('local')->put($projectVoice->reference_audio_path, 'RIFFnarratorclip');
        $overrideVoice = Voice::create(['user_id' => $owner->id, 'slug' => 'whisper', 'name' => 'Whisper']);

        $project = $this->projectWithVoice($projectVoice);
        $project->update(['user_id' => $owner->id]);
        $project->chunks()->orderBy('position')->first()->update(['voice_id' => $overrideVoice->id]);

        $admin = $this->admin();
        $project = $this->generateAndBuild($project, $admin);
        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Narrator')
                && str_contains($message, 'Whisper')
                && str_contains($message, 'also copied to your voices'));

        // Both voices were cloned to the duplicator with identity intact, the
        // clip an independent byte-copy under the duplicator's namespace.
        $narrator = Voice::where('user_id', $admin->id)->where('slug', 'narrator')->firstOrFail();
        $whisper = Voice::where('user_id', $admin->id)->where('slug', 'whisper')->firstOrFail();
        $this->assertSame('Narrator', $narrator->name);
        $this->assertSame(['exaggeration' => 0.7], $narrator->settings);
        $this->assertSame("voices/u{$admin->id}/narrator.wav", $narrator->reference_audio_path);
        $this->assertSame('RIFFnarratorclip', Storage::disk('local')->get($narrator->reference_audio_path));

        // The copy points at the clones — and its generated chunks stay
        // Completed: same voice provenance, so nothing is stale.
        $copy = $this->duplicateOf($project);
        $this->assertSame($narrator->id, $copy->voice_id);
        [$first, $second] = $copy->chunks()->orderBy('position')->get();
        $this->assertSame($whisper->id, $first->voice_id);
        $this->assertNull($second->voice_id);
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertSame(ChunkStatus::Completed, $second->status);

        // The owner's originals are untouched.
        $this->assertSame($owner->id, $projectVoice->refresh()->user_id);
        $this->assertSame($projectVoice->id, $project->refresh()->voice_id);
        $this->assertSame('RIFFnarratorclip', Storage::disk('local')->get($projectVoice->reference_audio_path));
    }

    public function test_duplicate_deconflicts_an_adopted_voice_id(): void
    {
        // The duplicator already uses the voice_id "narrator", so the adopted
        // clone must be minted under a fresh slug rather than colliding.
        $admin = $this->admin();
        Voice::create(['user_id' => $admin->id, 'slug' => 'narrator', 'name' => 'House Narrator']);

        $owner = User::factory()->create(['is_super_admin' => false]);
        $theirs = Voice::create([
            'user_id' => $owner->id,
            'slug' => 'narrator',
            'name' => 'Narrator',
            'reference_audio_path' => "voices/u{$owner->id}/narrator.wav",
        ]);
        Storage::disk('local')->put($theirs->reference_audio_path, 'RIFFtheirclip');

        $project = $this->projectWithVoice($theirs);
        $project->update(['user_id' => $owner->id]);

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));

        $copy = $this->duplicateOf($project);
        $clone = Voice::findOrFail($copy->voice_id);
        $this->assertSame($admin->id, $clone->user_id);
        $this->assertSame('narrator-2', $clone->slug);
        // The name mirrors the suffix so pickers don't show two "Narrator"s.
        $this->assertSame('Narrator 2', $clone->name);
        $this->assertSame("voices/u{$admin->id}/narrator-2.wav", $clone->reference_audio_path);
        $this->assertSame('RIFFtheirclip', Storage::disk('local')->get($clone->reference_audio_path));

        // The duplicator's own "narrator" is untouched.
        $this->assertSame('House Narrator', Voice::where('user_id', $admin->id)->where('slug', 'narrator')->firstOrFail()->name);
    }

    public function test_duplicate_adopts_no_voices_that_are_already_reachable(): void
    {
        // A foreign project bound to a SHARED voice (null owner): the
        // duplicator can already reach it, so nothing is cloned.
        $owner = User::factory()->create(['is_super_admin' => false]);
        $project = $this->project();
        $project->update(['user_id' => $owner->id]);

        $before = Voice::count();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.duplicate', $project))
            ->assertSessionHas('success', 'Project duplicated — you are now viewing the copy.');

        $this->assertSame($before, Voice::count());
        $this->assertSame($project->voice_id, $this->duplicateOf($project)->voice_id);
    }

    // ---- Clean up project (delete the non-selected takes) -------------------

    public function test_cleanup_deletes_non_selected_takes_and_their_files(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();

        $chunk->refresh();
        $paths = $chunk->takes()->pluck('audio_path');
        $this->assertCount(3, $paths);

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertRedirect(route('admin.studio.projects.show', $project))
            ->assertSessionHas('success', 'Project cleaned up — 2 unused takes were removed.');

        // Only the selected take survives, and what the chunk plays is unchanged.
        $takes = $chunk->refresh()->takes()->get();
        $this->assertCount(1, $takes);
        $this->assertSame($chunk->audio_path, $takes->first()->audio_path);

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($chunk->audio_path));
        foreach ($paths as $path) {
            if ($path !== $chunk->audio_path) {
                $this->assertFalse($disk->exists($path), "unselected take file should be gone: {$path}");
            }
        }
    }

    public function test_cleanup_keeps_the_final_and_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        foreach ($project->chunks()->get() as $c) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $c]));
        }
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertSessionHas('success', 'Project cleaned up — 1 unused take was removed.');

        // Cleanup never touches audio that plays, so the approval must survive.
        $project->refresh();
        $this->assertTrue($project->isSealed());
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertTrue(Storage::disk('local')->exists($project->final_audio_path));
        $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project))->assertOk();
    }

    public function test_cleanup_with_nothing_to_remove(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertRedirect(route('admin.studio.projects.show', $project))
            ->assertSessionHas('success', 'Nothing to clean up — every take is a selected one.');

        // One selected take per chunk, all untouched.
        $this->assertSame(2, TtsChunkTake::count());
    }

    public function test_cleanup_keeps_legacy_takes_sharing_the_selected_file(): void
    {
        // A legacy in-place set: several take rows referencing the chunk's ONE
        // file. None of them may be deleted — removing any would take the
        // shared file (the selected audio) with it.
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $chunk->refresh();
        $chunk->takes()->create([
            'audio_path' => $chunk->audio_path,
            'text' => $chunk->text,
            'source' => 'generate',
        ]);

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertSessionHas('success', 'Nothing to clean up — every take is a selected one.');

        $this->assertCount(2, $chunk->refresh()->takes()->get());
        $this->assertTrue(Storage::disk('local')->exists($chunk->audio_path));
    }

    public function test_cleanup_removes_all_takes_of_a_chunk_with_no_selected_audio(): void
    {
        // No selected audio means no take is selected — cleanup archives the
        // project down to what's actually in use, which here is nothing.
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $chunk->refresh();
        $takePath = $chunk->audio_path;
        $chunk->update(['audio_path' => null]);

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertSessionHas('success', 'Project cleaned up — 1 unused take was removed.');

        $this->assertCount(0, $chunk->refresh()->takes()->get());
        $this->assertFalse(Storage::disk('local')->exists($takePath));
    }

    public function test_cleanup_requires_project_access(): void
    {
        $owner = User::factory()->create(['is_super_admin' => false]);
        $project = $this->project();
        $project->update(['user_id' => $owner->id]);
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($owner)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $this->actingAs($owner)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->post(route('admin.studio.projects.cleanup', $project))
            ->assertForbidden();

        $this->assertCount(2, $chunk->refresh()->takes()->get());
    }

    public function test_cleanup_is_refused_while_a_background_run_is_active(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'chunk_ids' => $project->chunks()->pluck('id')->all(),
            'chunks_total' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertRedirect(route('admin.studio.projects.show', $project))
            ->assertSessionHas('error');

        $this->assertCount(2, $chunk->refresh()->takes()->get());
    }

    public function test_duplicate_reuses_an_identical_voice_instead_of_cloning(): void
    {
        // The duplicator already owns a voice that sounds identical to the
        // foreign project's — same tuning and a byte-identical clip, just
        // registered separately (different slug even). The copy must point at
        // THAT voice; minting a "-2" clone of a voice they effectively have
        // would only clutter their Voices page.
        $admin = $this->admin();
        $mine = Voice::create([
            'user_id' => $admin->id,
            'slug' => 'my-take',
            'name' => 'My Take',
            'settings' => ['seed' => 7],
            'reference_audio_path' => "voices/u{$admin->id}/my-take.wav",
        ]);
        Storage::disk('local')->put($mine->reference_audio_path, 'RIFFsameclip');

        $owner = User::factory()->create(['is_super_admin' => false]);
        $theirs = Voice::create([
            'user_id' => $owner->id,
            'slug' => 'narrator',
            'name' => 'Narrator',
            'settings' => ['seed' => 7],
            'reference_audio_path' => "voices/u{$owner->id}/narrator.wav",
        ]);
        Storage::disk('local')->put($theirs->reference_audio_path, 'RIFFsameclip');

        $project = $this->projectWithVoice($theirs);
        $project->update(['user_id' => $owner->id]);

        $before = Voice::count();
        // No "also copied to your voices" — nothing was minted.
        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project))
            ->assertSessionHas('success', 'Project duplicated — you are now viewing the copy.');

        $this->assertSame($before, Voice::count());
        $this->assertSame($mine->id, $this->duplicateOf($project)->voice_id);
    }

    public function test_duplicate_still_clones_when_the_lookalike_differs_in_tuning(): void
    {
        // Same clip bytes but different tuning is NOT the same voice — the
        // copy must keep generating exactly like the source, so a clone is
        // minted rather than reusing the near-miss.
        $admin = $this->admin();
        $mine = Voice::create([
            'user_id' => $admin->id,
            'slug' => 'my-take',
            'name' => 'My Take',
            'settings' => ['seed' => 7, 'exaggeration' => 1.2],
            'reference_audio_path' => "voices/u{$admin->id}/my-take.wav",
        ]);
        Storage::disk('local')->put($mine->reference_audio_path, 'RIFFsameclip');

        $owner = User::factory()->create(['is_super_admin' => false]);
        $theirs = Voice::create([
            'user_id' => $owner->id,
            'slug' => 'narrator',
            'name' => 'Narrator',
            'settings' => ['seed' => 7],
            'reference_audio_path' => "voices/u{$owner->id}/narrator.wav",
        ]);
        Storage::disk('local')->put($theirs->reference_audio_path, 'RIFFsameclip');

        $project = $this->projectWithVoice($theirs);
        $project->update(['user_id' => $owner->id]);

        $before = Voice::count();
        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'also copied to your voices'));

        $this->assertSame($before + 1, Voice::count());
        $clone = Voice::findOrFail($this->duplicateOf($project)->voice_id);
        $this->assertNotSame($mine->id, $clone->id);
        $this->assertSame($admin->id, $clone->user_id);
        $this->assertSame(['seed' => 7], $clone->settings);
    }

    public function test_foreign_project_reset_applies_the_owners_pronunciation_dictionary(): void
    {
        // Pronunciation lexicons are strictly per-writer. A SuperAdmin
        // resetting (re-chunking) someone else's project must apply the
        // OWNER's approved pronunciations to the owner's text — not their own.
        $admin = $this->admin();
        $owner = User::factory()->create(['is_super_admin' => false]);
        foreach ([[$admin->id, 'ADMIN-JIF'], [$owner->id, 'OWNER-JIF']] as [$userId, $phonetic]) {
            PronunciationEntry::create([
                'user_id' => $userId,
                'term' => 'GIF',
                'phonetic' => $phonetic,
                'match_mode' => 'case_sensitive',
                'source' => 'user',
                'approved' => true,
            ]);
        }

        $project = $this->project();
        $project->update(['user_id' => $owner->id]);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.reset', $project), [
                'text' => 'The GIF format works well in this sentence, which is long enough to chunk.',
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $normalized = $project->refresh()->normalized_text;
        $this->assertStringContainsString('OWNER-JIF', $normalized);
        $this->assertStringNotContainsString('ADMIN-JIF', $normalized);
    }

    public function test_foreign_project_voice_changes_resolve_against_the_owner(): void
    {
        // Both users own a voice with the SAME slug. A SuperAdmin switching
        // the voice on the owner's project must get the OWNER's row —
        // resolving for the requester would stamp the admin's voice onto a
        // project whose owner can't see it, which duplicate() would then have
        // to clone back as a confusing "-2" copy.
        $admin = $this->admin();
        $owner = User::factory()->create(['is_super_admin' => false]);
        Voice::create(['user_id' => $admin->id, 'slug' => 'narrator', 'name' => 'Admin Narrator']);
        $ownerNarrator = Voice::create(['user_id' => $owner->id, 'slug' => 'narrator', 'name' => 'Owner Narrator']);

        $project = $this->project();
        $project->update(['user_id' => $owner->id]);
        $first = $project->chunks()->orderBy('position')->first();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'narrator'])
            ->assertOk()
            ->assertJsonPath('voice_name', 'Owner Narrator');
        $this->assertSame($ownerNarrator->id, $project->refresh()->voice_id);

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $first]), ['voice' => 'narrator'])
            ->assertOk()
            ->assertJsonPath('voice_name', 'Owner Narrator');
        $this->assertSame($ownerNarrator->id, $first->refresh()->voice_id);

        // A voice only the ADMIN can reach does not resolve on the owner's
        // project — better a 422 than a reference the owner can't follow.
        Voice::create(['user_id' => $admin->id, 'slug' => 'admin-only', 'name' => 'Admin Only']);
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'admin-only'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.voice', [$project, $first]), ['voice' => 'admin-only'])
            ->assertStatus(422);
    }

    public function test_foreign_project_pickers_list_the_owners_voices(): void
    {
        // The show page's voice dropdowns must offer what the project's OWNER
        // can generate with, not the visiting SuperAdmin's collection.
        $admin = $this->admin();
        $owner = User::factory()->create(['is_super_admin' => false]);
        Voice::create(['user_id' => $admin->id, 'slug' => 'admins-own', 'name' => 'Admins Own']);
        Voice::create(['user_id' => $owner->id, 'slug' => 'owners-own', 'name' => 'Owners Own']);

        $project = $this->project();
        $project->update(['user_id' => $owner->id]);

        $slugs = $this->actingAs($admin)
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->viewData('voices')
            ->pluck('slug');
        $this->assertTrue($slugs->contains('owners-own'));
        $this->assertFalse($slugs->contains('admins-own'));
    }
}
