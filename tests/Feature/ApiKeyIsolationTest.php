<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API keys are strictly per-user: nobody sees, resets, rotates, or deletes another
 * user's key — not even a SuperAdmin. (Guards the multi-user leak where a keyless
 * user inherited a shared/legacy key.)
 */
class ApiKeyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_never_sees_another_users_key_on_the_dashboard(): void
    {
        $alice = User::factory()->create();
        $aliceKey = ApiKey::generate('alice-key', null, $alice->id);

        $bob = User::factory()->create(); // no key of their own

        $this->actingAs($bob)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee($aliceKey->key)
            ->assertSee('No active key yet');
    }

    public function test_a_legacy_unowned_key_is_not_shown_to_anyone(): void
    {
        ApiKey::generate('legacy', null, null); // unowned
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('No active key yet');
    }

    public function test_the_api_keys_page_lists_only_your_own_keys(): void
    {
        $alice = User::factory()->create();
        ApiKey::generate('alice-only-key', null, $alice->id);
        $bob = User::factory()->create();
        ApiKey::generate('bob-only-key', null, $bob->id);

        $this->actingAs($alice)->get(route('admin.api-keys.index'))
            ->assertSee('alice-only-key')
            ->assertDontSee('bob-only-key');
    }

    public function test_a_user_cannot_regenerate_another_users_key(): void
    {
        $alice = User::factory()->create();
        $aliceKey = ApiKey::generate('alice', null, $alice->id);
        $original = $aliceKey->key;

        // Even a SuperAdmin can't touch someone else's key.
        $bob = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($bob)->post(route('admin.api-keys.regenerate', $aliceKey))->assertForbidden();
        $this->assertSame($original, $aliceKey->fresh()->key);
    }

    public function test_a_user_cannot_toggle_or_delete_another_users_key(): void
    {
        $alice = User::factory()->create();
        $aliceKey = ApiKey::generate('alice', null, $alice->id);
        $bob = User::factory()->create();

        $this->actingAs($bob)->post(route('admin.api-keys.toggle', $aliceKey))->assertForbidden();
        $this->assertTrue($aliceKey->fresh()->is_active);

        $this->actingAs($bob)->delete(route('admin.api-keys.destroy', $aliceKey))->assertForbidden();
        $this->assertNotNull($aliceKey->fresh());
    }

    public function test_a_user_can_manage_their_own_key(): void
    {
        $alice = User::factory()->create();
        $aliceKey = ApiKey::generate('alice', null, $alice->id);

        $this->actingAs($alice)->post(route('admin.api-keys.toggle', $aliceKey))->assertRedirect();
        $this->assertFalse($aliceKey->fresh()->is_active);
    }
}
