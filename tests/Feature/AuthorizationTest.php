<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The post-redesign access model: the control panel is open to any signed-in,
 * active user; only the SuperAdmin surface is narrower. (The Users screen's own
 * SuperAdmin gate is covered in the Users admin tests once that screen lands.)
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_non_admin_user_can_reach_the_panel(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_suspended_user_is_logged_out_and_redirected_to_login(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_suspending_a_superadmin_also_blocks_them(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => true,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_presence_is_recorded_on_a_panel_request(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'last_active_at' => null,
        ]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $this->assertNotNull($user->fresh()->last_active_at);
    }
}
