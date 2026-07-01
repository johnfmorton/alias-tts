<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_renders_for_any_user(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.account.index'))
            ->assertOk()
            ->assertSee('Manage your profile, security, and how you sign in.');
    }

    public function test_profile_update_persists_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Old', 'email' => 'old@example.com']);

        $this->actingAs($user)
            ->put(route('admin.account.profile'), ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertRedirect();

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_profile_email_must_be_unique_to_others(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $this->actingAs($user)
            ->put(route('admin.account.profile'), ['name' => 'Me', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'current-pass']);

        $this->actingAs($user)
            ->put(route('admin.account.password'), [
                'current_password' => 'wrong-pass',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_password_change_succeeds_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'current-pass']);

        $this->actingAs($user)
            ->put(route('admin.account.password'), [
                'current_password' => 'current-pass',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));
    }

    public function test_the_only_superadmin_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'password' => 'pw']);

        $this->actingAs($user)
            ->delete(route('admin.account.destroy'), ['password' => 'pw'])
            ->assertRedirect();

        $this->assertModelExists($user);
        $this->assertAuthenticated();
    }

    public function test_a_superadmin_can_delete_themselves_when_another_exists(): void
    {
        User::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create(['is_super_admin' => true, 'password' => 'pw']);

        $this->actingAs($user)
            ->delete(route('admin.account.destroy'), ['password' => 'pw'])
            ->assertRedirect(route('login'));

        $this->assertModelMissing($user);
        $this->assertGuest();
    }

    public function test_a_regular_user_can_delete_themselves(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'password' => 'pw']);

        $this->actingAs($user)
            ->delete(route('admin.account.destroy'), ['password' => 'pw'])
            ->assertRedirect(route('login'));

        $this->assertModelMissing($user);
    }

    public function test_avatar_route_404s_when_the_user_has_none(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($viewer)
            ->get(route('admin.avatars.show', $target))
            ->assertNotFound();
    }
}
