<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
