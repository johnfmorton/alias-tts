<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Voices are per-user: a custom voice belongs to whoever created it and is
 * visible only to them (plus SuperAdmins on the Voices page); built-ins
 * (user_id NULL) are shared. Each user also has a personal drag order
 * (voice_orders) that drives every picker — its first entry is what the New
 * Project form pre-selects.
 */
class VoicePerUserTest extends TestCase
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

    private function user(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    private function voiceFor(?User $owner, string $slug, ?string $name = null): Voice
    {
        return Voice::create([
            'user_id' => $owner?->id,
            'slug' => $slug,
            'name' => $name ?? ucfirst($slug),
            'reference_audio_path' => "voices/{$slug}.wav",
        ]);
    }

    // ---- Visibility ---------------------------------------------------------

    public function test_the_voices_page_shows_only_shared_voices_and_your_own(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->voiceFor(null, 'shared-voice');
        $this->voiceFor($me, 'mine');
        $this->voiceFor($other, 'theirs');

        $this->actingAs($me)->get(route('admin.voices.index'))
            ->assertOk()
            ->assertSee('shared-voice')
            ->assertSee('mine')
            ->assertDontSee('theirs');
    }

    public function test_a_super_admin_sees_every_voice_owner_labeled(): void
    {
        $other = User::factory()->create(['is_super_admin' => false, 'name' => 'Vera Owner']);
        $this->voiceFor($other, 'theirs');

        $this->actingAs($this->admin())->get(route('admin.voices.index'))
            ->assertOk()
            ->assertSee('theirs')
            ->assertSee('Vera Owner');
    }

    public function test_pickers_exclude_other_users_voices(): void
    {
        $me = $this->user();
        $this->voiceFor(null, 'shared-voice');
        $this->voiceFor($this->user(), 'theirs');

        $this->actingAs($me)->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSee('shared-voice')
            ->assertDontSee('theirs');
    }

    // ---- Guards -------------------------------------------------------------

    public function test_a_user_cannot_edit_update_or_delete_anothers_voice(): void
    {
        $me = $this->user();
        $voice = $this->voiceFor($this->user(), 'theirs');

        $this->actingAs($me)->get(route('admin.voices.edit', $voice))->assertForbidden();
        $this->actingAs($me)->put(route('admin.voices.update', $voice), [
            'name' => 'Hijacked', 'slug' => 'theirs',
        ])->assertForbidden();
        $this->actingAs($me)->delete(route('admin.voices.destroy', $voice))->assertForbidden();

        $this->assertSame('Theirs', $voice->refresh()->name);
    }

    public function test_a_regular_user_cannot_edit_a_shared_voice(): void
    {
        // Editing a shared (built-in / ownerless) voice changes what every user
        // hears — SuperAdmin only.
        $voice = $this->voiceFor(null, 'shared-voice');

        $this->actingAs($this->user())->get(route('admin.voices.edit', $voice))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.voices.edit', $voice))->assertOk();
    }

    public function test_the_owner_can_edit_their_own_voice(): void
    {
        $me = $this->user();
        $voice = $this->voiceFor($me, 'mine');

        $this->actingAs($me)->get(route('admin.voices.edit', $voice))->assertOk();
    }

    public function test_creating_a_voice_records_the_owner(): void
    {
        $me = $this->user();

        $this->actingAs($me)->post(route('admin.voices.store'), [
            'name' => 'My narrator',
        ])->assertRedirect(route('admin.voices.index'));

        $this->assertSame($me->id, Voice::firstWhere('slug', 'my-narrator')->user_id);
    }

    public function test_registering_an_existing_slug_of_another_owner_is_refused(): void
    {
        $victim = $this->user();
        $this->voiceFor($victim, 'precious', 'Original');

        try {
            app(VoiceService::class)->register(
                name: 'Takeover', slug: 'precious', audioBytes: null, ext: null,
                normalize: false, seed: null, ownerId: $this->user()->id,
            );
            $this->fail('Registering an already-owned slug should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already in use', $e->getMessage());
        }

        // The victim's voice is untouched.
        $this->assertSame('Original', Voice::firstWhere('slug', 'precious')->name);
        $this->assertSame($victim->id, Voice::firstWhere('slug', 'precious')->user_id);
    }

    // ---- API scoping ----------------------------------------------------------

    public function test_an_api_key_cannot_generate_with_another_users_voice(): void
    {
        $mine = ApiKey::generate('mine', userId: $this->user()->id);
        $voice = $this->voiceFor($this->user(), 'theirs');

        $this->postJson("/v1/text-to-speech/{$voice->slug}", ['text' => 'Hello there'], [
            'xi-api-key' => $mine->key,
        ])->assertNotFound();
    }

    public function test_an_api_key_can_generate_with_a_shared_and_its_owners_voice(): void
    {
        $me = $this->user();
        $mine = ApiKey::generate('mine', userId: $me->id);
        $this->voiceFor(null, 'shared-voice');
        $this->voiceFor($me, 'my-voice');

        $this->postJson('/v1/text-to-speech/shared-voice', ['text' => 'Hello there'], [
            'xi-api-key' => $mine->key,
        ])->assertOk();

        $this->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello there'], [
            'xi-api-key' => $mine->key,
        ])->assertOk();
    }

    // ---- Ordering -------------------------------------------------------------

    public function test_saved_drag_order_drives_the_picker_and_its_preselection(): void
    {
        $me = $this->user();
        $shared = $this->voiceFor(null, 'shared-voice');
        $mine = $this->voiceFor($me, 'my-voice', 'Zz Mine'); // sorts last by name

        // Put MY voice first — it becomes the picker's first option AND the
        // pre-selected default for new projects.
        $this->actingAs($me)->postJson(route('admin.voices.order'), [
            'order' => [$mine->id, $shared->id],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($me)->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSeeInOrder(['value="my-voice"', 'value="shared-voice"'], false)
            ->assertSee('value="my-voice" selected', false);
    }

    public function test_the_drag_order_is_personal(): void
    {
        $me = $this->user();
        $other = $this->user();
        $shared = $this->voiceFor(null, 'aaa-shared', 'Aaa Shared');
        $second = $this->voiceFor(null, 'zzz-shared', 'Zzz Shared');

        // The other user flips the order for themselves.
        $this->actingAs($other)->postJson(route('admin.voices.order'), [
            'order' => [$second->id, $shared->id],
        ])->assertOk();

        // My picker still uses the fallback order — their drag never leaks.
        $this->actingAs($me)->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSeeInOrder(['value="aaa-shared"', 'value="zzz-shared"'], false);

        $this->actingAs($other)->get(route('admin.studio.projects.create'))
            ->assertOk()
            ->assertSeeInOrder(['value="zzz-shared"', 'value="aaa-shared"'], false);
    }

    public function test_ordering_a_foreign_voice_id_is_ignored(): void
    {
        $me = $this->user();
        $shared = $this->voiceFor(null, 'shared-voice');
        $foreign = $this->voiceFor($this->user(), 'theirs');

        $res = $this->actingAs($me)->postJson(route('admin.voices.order'), [
            'order' => [$foreign->id, $shared->id],
        ])->assertOk();

        $res->assertJsonPath('ranked', 1); // only the visible voice was ranked
        $this->assertDatabaseMissing('voice_orders', ['user_id' => $me->id, 'voice_id' => $foreign->id]);
    }
}
