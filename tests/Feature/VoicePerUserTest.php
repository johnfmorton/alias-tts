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

    public function test_a_super_admins_voices_page_defaults_to_their_own_scope(): void
    {
        $other = User::factory()->create(['is_super_admin' => false, 'name' => 'Vera Owner']);
        $this->voiceFor($other, 'theirs');
        $this->voiceFor(null, 'shared-voice');
        $admin = $this->admin();
        $this->voiceFor($admin, 'mine');

        $this->actingAs($admin)->get(route('admin.voices.index'))
            ->assertOk()
            ->assertSee('mine')
            ->assertSee('shared-voice')
            ->assertDontSee('theirs')
            // Vera still appears — as an option in the owner dropdown, which
            // lists the signed-in admin first, then the widener, then the rest.
            ->assertSeeInOrder(['(you)', 'All owners', 'Vera Owner']);
    }

    public function test_a_super_admin_can_widen_to_every_voice_owner_labeled(): void
    {
        $other = User::factory()->create(['is_super_admin' => false, 'name' => 'Vera Owner']);
        $this->voiceFor($other, 'theirs');

        $this->actingAs($this->admin())->get(route('admin.voices.index', ['owner' => 'all']))
            ->assertOk()
            ->assertSee('theirs')
            ->assertSee('Vera Owner');
    }

    public function test_a_super_admin_can_filter_voices_to_one_owners_view(): void
    {
        $other = User::factory()->create(['is_super_admin' => false, 'name' => 'Vera Owner']);
        $this->voiceFor($other, 'theirs');
        $this->voiceFor(null, 'shared-voice');
        $admin = $this->admin();
        $this->voiceFor($admin, 'mine');

        // Filtering to Vera shows what Vera sees: her voices plus the shared
        // built-ins — not the admin's own.
        $this->actingAs($admin)->get(route('admin.voices.index', ['owner' => $other->id]))
            ->assertOk()
            ->assertSee('theirs')
            ->assertSee('shared-voice')
            ->assertDontSee('mine');
    }

    public function test_the_owner_filter_never_widens_a_regular_users_voices(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->voiceFor($me, 'mine');
        $this->voiceFor($other, 'theirs');

        $this->actingAs($me)->get(route('admin.voices.index', ['owner' => 'all']))
            ->assertOk()
            ->assertSee('mine')
            ->assertDontSee('theirs');

        $this->actingAs($me)->get(route('admin.voices.index', ['owner' => $other->id]))
            ->assertOk()
            ->assertSee('mine')
            ->assertDontSee('theirs');
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

    public function test_bench_tuning_cannot_be_saved_onto_a_shared_voice_by_a_regular_user(): void
    {
        // The Studio bench's "save to voice defaults" follows the same rule as
        // the edit form: a shared voice sounds the same for everyone, so only a
        // SuperAdmin may retune it.
        $voice = $this->voiceFor(null, 'shared-voice');

        $this->actingAs($this->user())
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'shared-voice', 'exaggeration' => 1.8])
            ->assertForbidden();
        $this->assertNull($voice->refresh()->settings);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'shared-voice', 'exaggeration' => 1.8])
            ->assertOk();
        $this->assertSame(1.8, $voice->refresh()->settings['exaggeration']);
    }

    public function test_bench_tuning_saves_onto_your_own_voice(): void
    {
        $me = $this->user();
        $this->voiceFor($me, 'mine');

        $this->actingAs($me)
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'mine', 'exaggeration' => 1.2, 'cfg_weight' => 0.7])
            ->assertOk();

        $settings = Voice::firstWhere('slug', 'mine')->settings;
        $this->assertSame(1.2, $settings['exaggeration']);
        $this->assertSame(0.7, $settings['cfg_weight']);
    }

    public function test_duplicate_clones_a_shared_voice_into_an_owned_tunable_copy(): void
    {
        $me = $this->user();
        $voice = $this->voiceFor(null, 'shared-voice');
        $voice->update(['settings' => ['exaggeration' => 0.9, 'seed' => 7]]);
        Storage::disk('local')->put('voices/shared-voice.wav', 'clip-bytes');

        $this->actingAs($me)->post(route('admin.voices.duplicate', $voice))
            ->assertRedirect();

        $copy = Voice::firstWhere('slug', 'shared-voice-copy');
        $this->assertSame($me->id, $copy->user_id);
        $this->assertSame('Shared-voice copy', $copy->name);
        $this->assertSame(['exaggeration' => 0.9, 'seed' => 7], $copy->settings);
        // The copy's clip lands in the owner's namespace, not the shared root.
        $this->assertSame("voices/u{$me->id}/shared-voice-copy.wav", $copy->reference_audio_path);
        $this->assertSame('clip-bytes', Storage::disk('local')->get($copy->reference_audio_path));

        // The original is untouched, and the copy IS tunable by its owner.
        $this->assertSame(['exaggeration' => 0.9, 'seed' => 7], $voice->refresh()->settings);
        $this->actingAs($me)
            ->postJson(route('admin.studio.voice-defaults'), ['voice' => 'shared-voice-copy', 'cfg_weight' => 0.5])
            ->assertOk();
    }

    public function test_duplicate_slugs_increment_on_collision(): void
    {
        $me = $this->user();
        $voice = $this->voiceFor(null, 'shared-voice');

        $this->actingAs($me)->post(route('admin.voices.duplicate', $voice));
        $this->actingAs($me)->post(route('admin.voices.duplicate', $voice));

        $this->assertNotNull(Voice::firstWhere('slug', 'shared-voice-copy'));
        $this->assertNotNull(Voice::firstWhere('slug', 'shared-voice-copy-2'));
    }

    public function test_duplicating_an_invisible_voice_is_a_404(): void
    {
        $voice = $this->voiceFor($this->user(), 'theirs');

        $this->actingAs($this->user())->post(route('admin.voices.duplicate', $voice))->assertNotFound();
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

        $res = $this->actingAs($me)->post(route('admin.voices.store'), [
            'name' => 'My narrator',
        ]);

        $voice = Voice::firstWhere('slug', 'my-narrator');
        $this->assertSame($me->id, $voice->user_id);
        // Creation lands on the edit page — tuning by ear lives there.
        $res->assertRedirect(route('admin.voices.edit', $voice));
    }

    public function test_registering_another_owners_slug_creates_a_separate_voice(): void
    {
        // voice_ids are only unique per owner: two users may each have a
        // "precious" — this is what lets an exported voice import cleanly on
        // an account that never saw the original.
        $other = $this->user();
        $this->voiceFor($other, 'precious', 'Original');

        $me = $this->user();
        $mine = app(VoiceService::class)->register(
            name: 'Mine too', slug: 'precious', audioBytes: null, ext: null,
            normalize: false, seed: null, ownerId: $me->id,
        );

        $this->assertSame($me->id, $mine->user_id);
        $this->assertCount(2, Voice::where('slug', 'precious')->get());

        // The other user's voice is untouched.
        $theirs = Voice::where('slug', 'precious')->where('user_id', $other->id)->sole();
        $this->assertSame('Original', $theirs->name);
    }

    public function test_registering_a_shared_voices_slug_is_refused(): void
    {
        // A shared voice sits in every user's picker, so taking its slug would
        // make lookups inside that user's set ambiguous.
        $this->voiceFor(null, 'shared-voice', 'Shared original');

        try {
            app(VoiceService::class)->register(
                name: 'Takeover', slug: 'shared-voice', audioBytes: null, ext: null,
                normalize: false, seed: null, ownerId: $this->user()->id,
            );
            $this->fail('Registering a shared slug should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already in use', $e->getMessage());
        }

        $this->assertSame('Shared original', Voice::firstWhere('slug', 'shared-voice')->name);
    }

    public function test_a_shared_voice_cannot_take_a_slug_any_user_owns(): void
    {
        // The mirror rule: a new shared voice lands in EVERY user's set, so it
        // may not collide with any owner's slug.
        $this->voiceFor($this->user(), 'precious', 'Original');

        try {
            app(VoiceService::class)->register(
                name: 'Shared takeover', slug: 'precious', audioBytes: null, ext: null,
                normalize: false, seed: null, ownerId: null,
            );
            $this->fail('A shared voice must not take over a user-owned slug.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already in use', $e->getMessage());
        }

        $this->assertSame('Original', Voice::firstWhere('slug', 'precious')->name);
    }

    public function test_same_slug_voices_store_their_clips_at_distinct_paths(): void
    {
        // Reference clips are namespaced per owner — without that, the second
        // "narrator" would silently overwrite the first user's clip file.
        $service = app(VoiceService::class);
        $mine = $service->register('Narrator', 'narrator', 'clip-a', 'wav', false, null, ownerId: $this->user()->id);
        $theirs = $service->register('Narrator', 'narrator', 'clip-b', 'wav', false, null, ownerId: $this->user()->id);

        $this->assertNotSame($mine->reference_audio_path, $theirs->reference_audio_path);
        $this->assertSame('clip-a', Storage::disk('local')->get($mine->reference_audio_path));
        $this->assertSame('clip-b', Storage::disk('local')->get($theirs->reference_audio_path));
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
