<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Add / Edit voice pipeline: Identity → Voice source → Delivery defaults,
 * one rail on both pages, one save bar on Edit — and the reference clip made
 * playable and inspectable in step 2.
 */
class VoiceFlowTest extends TestCase
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

    /** A parseable mono 44.1 kHz PCM WAV of the given length. */
    private function silentWav(float $seconds): string
    {
        $sampleRate = 44100;
        $bits = 16;
        $dataSize = (int) ($sampleRate * $seconds) * ($bits / 8);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $sampleRate).pack('V', $sampleRate * 2).pack('v', 2).pack('v', $bits)
            .'data'.pack('V', $dataSize).str_repeat("\x00", $dataSize);
    }

    private function voiceWithClip(User $owner, float $seconds = 18.0): Voice
    {
        Storage::disk('local')->put('voices/john.wav', $this->silentWav($seconds));

        return Voice::create([
            'user_id' => $owner->id,
            'slug' => 'john',
            'name' => 'John',
            'reference_audio_path' => 'voices/john.wav',
        ]);
    }

    // ── the rail ────────────────────────────────────────────────────────────

    public function test_the_edit_page_runs_the_three_step_rail(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()
            ->assertSee('data-rail-step="identity"', false)
            ->assertSee('data-rail-step="source"', false)
            ->assertSee('data-rail-step="delivery"', false)
            ->assertSee('1 · Identity')
            ->assertSee('2 · Voice source')
            ->assertSee('3 · Delivery defaults');
    }

    public function test_the_add_page_runs_the_same_rail_with_delivery_locked(): void
    {
        $this->actingAs($this->admin())->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('data-rail-step="identity"', false)
            ->assertSee('data-rail-step="source"', false)
            // Locked, not absent: you tune by ear, and there's nothing to hear yet.
            ->assertSee('data-rail-step="delivery" data-state="locked"', false)
            ->assertSee('after the voice exists');
    }

    public function test_the_add_page_picks_the_engine_as_cards_with_their_tradeoffs(): void
    {
        $this->actingAs($this->admin())->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('expressive classic · knob tuning')
            ->assertSee('fast · sound tags · built-ins')
            ->assertSee('ten languages · style notes')
            ->assertSee('Create voice');
    }

    public function test_the_edit_page_gates_an_engine_change(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()
            ->assertSee('Change engine…')
            ->assertSee("won't carry over", false)
            // The picker is present but closed until the gate is acknowledged.
            ->assertSee('data-engine-picker class="mt-4 hidden', false);
    }

    // ── step 2's clip, playable and inspectable ─────────────────────────────

    public function test_the_source_step_describes_the_clip_from_its_own_header(): void
    {
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin, 18.0);

        $this->actingAs($admin)->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('Current reference clip')
            // Duration, channels, rate and container all read from the header —
            // nothing claimed that wasn't measured.
            ->assertSee('18s · mono · 44.1 kHz · WAV')
            ->assertSee(route('admin.voices.clip', $voice), false);
    }

    public function test_a_voice_without_a_clip_says_so_and_opens_the_source_step(): void
    {
        $admin = $this->admin();
        $voice = Voice::create(['user_id' => $admin->id, 'slug' => 'bare', 'name' => 'Bare']);

        $this->actingAs($admin)->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('no clip yet')
            ->assertDontSee('Current reference clip');
    }

    // ── the clip endpoint ───────────────────────────────────────────────────

    public function test_the_clip_route_streams_the_reference_audio(): void
    {
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $response = $this->actingAs($admin)->get(route('admin.voices.clip', $voice));

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame(Storage::disk('local')->get('voices/john.wav'), $response->getContent());
    }

    public function test_the_clip_route_answers_a_range_request_with_a_206(): void
    {
        // iOS Safari range-probes any <audio src>; a plain 200 reads as a live
        // stream with a dead scrubber.
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $this->actingAs($admin)
            ->get(route('admin.voices.clip', $voice), ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');
    }

    public function test_the_clip_route_can_be_downloaded(): void
    {
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $this->actingAs($admin)
            ->get(route('admin.voices.clip', [$voice, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="john-reference.wav"');
    }

    public function test_the_clip_route_404s_without_a_clip_and_for_another_users_voice(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['is_super_admin' => false]);

        $bare = Voice::create(['user_id' => $admin->id, 'slug' => 'bare', 'name' => 'Bare']);
        $this->actingAs($admin)->get(route('admin.voices.clip', $bare))->assertNotFound();

        $mine = $this->voiceWithClip($admin);
        $this->actingAs($other)->get(route('admin.voices.clip', $mine))->assertNotFound();
    }

    // ── a built-in voice and a clip are alternative sources ─────────────────

    public function test_the_clip_widget_is_addressable_so_a_builtin_can_replace_it(): void
    {
        // The provider sends `voice`/`speaker` ONLY when there is no reference
        // audio, so a clip silently overrides a chosen built-in. initVoiceFlow()
        // shows one or the other — these are the hooks it drives.
        $this->actingAs($this->admin())->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('data-clip-section', false)
            ->assertSee('data-clip-built-in-note', false)
            // The per-engine clip-length rules are clip-path guidance only.
            ->assertSee('data-clip-path-hint', false);
    }

    public function test_the_edit_page_declares_whether_a_clip_is_already_stored(): void
    {
        // Drives the honest warning: on a voice that HAS a clip, picking a
        // built-in stores it but changes nothing about what is heard.
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()
            ->assertSee('data-has-clip="1"', false);

        $bare = Voice::create(['user_id' => $admin->id, 'slug' => 'bare', 'name' => 'Bare']);
        $this->actingAs($admin)->get(route('admin.voices.edit', $bare))
            ->assertOk()
            ->assertSee('data-has-clip=""', false);
    }

    public function test_the_builtin_picker_is_not_hidden_behind_the_engine_gate(): void
    {
        // A built-in voice is a SOURCE, not an engine setting. Filing it inside
        // the gated engine picker left a voice already using one with no way to
        // change which one — and the copy pointing at it pointed at nothing.
        $admin = $this->admin();
        $voice = Voice::create([
            'user_id' => $admin->id, 'slug' => 'eric', 'name' => 'Eric',
            'model' => 'qwen3-tts', 'settings' => ['preset_voice' => 'Eric'],
        ]);

        $html = $this->actingAs($admin)->get(route('admin.voices.edit', $voice))
            ->assertOk()->assertSee('id="preset-voice"', false)->getContent();

        // The picker closes over the model select only; the built-in select must
        // sit outside it, or it inherits the gate's `hidden`.
        $gateStart = strpos($html, 'data-engine-picker');
        $presetAt = strpos($html, 'id="preset-voice"');
        $this->assertLessThan($gateStart, $presetAt, 'The built-in voice select must not sit inside the engine gate.');
    }

    public function test_the_clip_transcript_only_appears_when_a_clip_is_the_source(): void
    {
        // The transcript describes a clip. Beside a built-in voice there is no
        // clip for it to transcribe, so its help text would be nonsense.
        $admin = $this->admin();

        $builtIn = Voice::create([
            'user_id' => $admin->id, 'slug' => 'eric', 'name' => 'Eric',
            'model' => 'qwen3-tts', 'settings' => ['preset_voice' => 'Eric'],
        ]);
        $this->assertTrue(
            $this->transcriptHidden($this->actingAs($admin)->get(route('admin.voices.edit', $builtIn))->getContent()),
            'A built-in-voice voice has no clip, so the transcript must start hidden.',
        );

        $cloned = $this->voiceWithClip($admin);
        $cloned->update(['model' => 'qwen3-tts']);
        $this->assertFalse(
            $this->transcriptHidden($this->actingAs($admin)->get(route('admin.voices.edit', $cloned))->getContent()),
            'A voice that clones from a clip should offer that clip a transcript.',
        );
    }

    /** Whether the rendered transcript wrapper starts hidden, however Blade spells the class. */
    private function transcriptHidden(string $html): bool
    {
        $this->assertSame(1, preg_match('/data-clip-transcript\s*(?:class="([^"]*)")?\s*>/', $html, $m));

        return str_contains($m[1] ?? '', 'hidden');
    }

    public function test_the_builtin_note_sits_outside_the_replace_disclosure(): void
    {
        // Nested inside it, the "a stored clip wins" warning would be hidden by
        // the collapsed disclosure exactly when it matters.
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()->getContent();

        $noteAt = strpos($html, 'data-clip-built-in-note');
        $disclosureAt = strpos($html, 'data-disclosure="replace-clip"');
        $this->assertLessThan($disclosureAt, $noteAt, 'The built-in note must not nest inside the Replace disclosure.');
    }

    public function test_a_staged_clip_can_be_discarded_on_both_pages(): void
    {
        // The A/B chooser's "Start over" only covers the cleanup path; a file
        // picked with cleanup off never reaches it, leaving no way to un-pick.
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('data-staged-clip', false)
            ->assertSee('data-clear-staged-clip', false)
            // Add has no stored clip to replace, so the copy is the plain form.
            ->assertSee('data-clip-replace=""', false);

        $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()
            ->assertSee('data-clear-staged-clip', false)
            // On Edit the same control abandons a REPLACEMENT, keeping the stored clip.
            ->assertSee('data-clip-replace="1"', false);
    }

    // ── removing the stored clip ────────────────────────────────────────────

    public function test_removing_the_clip_clears_the_row_and_deletes_the_bytes(): void
    {
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $this->actingAs($admin)
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'remove_clip' => '1',
            ])
            ->assertRedirect(route('admin.voices.index'));

        $this->assertNull($voice->fresh()->reference_audio_path);
        Storage::disk('local')->assertMissing('voices/john.wav');
    }

    public function test_removing_the_clip_takes_its_transcript_with_it(): void
    {
        // The transcript describes the clip; qwen reads it ALONGSIDE the audio,
        // so one left behind describes audio that no longer exists.
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);
        $voice->update(['model' => 'qwen3-tts', 'settings' => ['reference_text' => 'the old harbor wakes slowly']]);

        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'John',
            'slug' => 'john',
            'model' => 'qwen3-tts',
            'preset_voice' => 'Serena',
            'reference_text' => 'the old harbor wakes slowly',
            'remove_clip' => '1',
        ])->assertRedirect(route('admin.voices.index'));

        $settings = (array) $voice->fresh()->settings;
        $this->assertArrayNotHasKey('reference_text', $settings);
        $this->assertSame('Serena', $settings['preset_voice']);
    }

    public function test_removing_the_last_source_is_refused_and_keeps_the_clip(): void
    {
        // Qwen ships built-in voices, so it needs a clip OR one of those. A
        // refused save must not have deleted the bytes on the way out.
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);
        $voice->update(['model' => 'qwen3-tts']);

        $this->actingAs($admin)
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'model' => 'qwen3-tts',
                'remove_clip' => '1',
            ])
            ->assertRedirect(route('admin.voices.edit', $voice))
            ->assertSessionHas('error');

        $this->assertSame('voices/john.wav', $voice->fresh()->reference_audio_path);
        Storage::disk('local')->assertExists('voices/john.wav');
    }

    public function test_a_clipless_chatterbox_voice_may_drop_its_clip(): void
    {
        // Chatterbox ships no built-ins, so it falls back to the model's own
        // generic voice — removal is allowed with nothing else picked.
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'John',
            'slug' => 'john',
            'model' => 'chatterbox',
            'remove_clip' => '1',
        ])->assertRedirect(route('admin.voices.index'));

        $this->assertNull($voice->fresh()->reference_audio_path);
    }

    public function test_a_replacement_clip_beats_a_removal_in_the_same_save(): void
    {
        $admin = $this->admin();
        $voice = $this->voiceWithClip($admin);

        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'John',
            'slug' => 'john',
            'remove_clip' => '1',
            'audio' => UploadedFile::fake()->createWithContent('new.wav', $this->silentWav(9.0)),
            'clip_rights' => '1',
        ])->assertRedirect(route('admin.voices.index'));

        // Replacing is not removing: the voice still has a clip.
        $this->assertNotNull($voice->fresh()->reference_audio_path);
    }

    public function test_the_edit_page_offers_removal_only_when_there_is_a_clip(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.edit', $this->voiceWithClip($admin)))
            ->assertOk()
            ->assertSee('data-remove-clip', false)
            ->assertSee('name="remove_clip"', false);

        $bare = Voice::create(['user_id' => $admin->id, 'slug' => 'bare', 'name' => 'Bare']);
        $this->actingAs($admin)->get(route('admin.voices.edit', $bare))
            ->assertOk()
            ->assertDontSee('name="remove_clip"', false);
    }

    // ── recording tips ──────────────────────────────────────────────────────

    public function test_recording_tips_sit_behind_a_disclosure_rather_than_greeting_everyone(): void
    {
        $this->actingAs($this->admin())->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('Recording tips ▾')
            ->assertSee('Get a great recording')
            ->assertSee('data-disclosure="recording-tips" class="mb-4 hidden', false);
    }
}
