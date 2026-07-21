<?php

namespace Tests\Feature;

use App\Http\Controllers\InvitationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_non_superadmin_cannot_access_the_users_screen(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_superadmin_sees_the_users_table(): void
    {
        $admin = $this->superAdmin();
        User::factory()->create(['name' => 'Amara Okafor']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Amara Okafor');
    }

    public function test_create_user_persists_and_reveals_a_temp_password(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'New Person',
                'email' => 'new@example.com',
                'role' => 'User',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('reveals');

        // Two reveals: a signed set-password link and a temp-password fallback.
        $reveals = collect($response->getSession()->get('reveals'));
        $this->assertCount(2, $reveals);
        $this->assertTrue($reveals->contains(fn ($r) => str_contains($r['value'], '/invite/')));

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'is_super_admin' => false, 'status' => 'active']);
    }

    public function test_invite_creates_an_invited_user_and_reveals_a_link(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.invite'), ['email' => 'invitee@example.com', 'role' => 'User'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('reveals');

        $this->assertDatabaseHas('users', ['email' => 'invitee@example.com', 'status' => 'invited']);
    }

    public function test_role_can_be_promoted(): void
    {
        $target = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($this->superAdmin())
            ->patch(route('admin.users.role', $target), ['role' => 'SuperAdmin'])
            ->assertRedirect(route('admin.users.index', ['user' => $target->id]));

        $this->assertTrue($target->fresh()->isSuperAdmin());
    }

    public function test_the_last_superadmin_cannot_be_demoted(): void
    {
        $only = $this->superAdmin();

        $this->actingAs($only)
            ->patch(route('admin.users.role', $only), ['role' => 'User'])
            ->assertSessionHas('error');

        $this->assertTrue($only->fresh()->isSuperAdmin());
    }

    public function test_cannot_change_your_own_role(): void
    {
        User::factory()->create(['is_super_admin' => true]); // a second SA so the guard isn't "last one"
        $me = $this->superAdmin();

        $this->actingAs($me)
            ->patch(route('admin.users.role', $me), ['role' => 'User'])
            ->assertSessionHas('error');

        $this->assertTrue($me->fresh()->isSuperAdmin());
    }

    public function test_suspend_toggles_status_but_not_for_self(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_super_admin' => false, 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.users.suspend', $target));
        $this->assertSame('suspended', $target->fresh()->status);

        $this->actingAs($admin)->post(route('admin.users.suspend', $target));
        $this->assertSame('active', $target->fresh()->status);

        // Can't suspend yourself.
        $this->actingAs($admin)->post(route('admin.users.suspend', $admin))->assertSessionHas('error');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_force_reset_invalidates_the_password_and_reveals_a_link(): void
    {
        $target = User::factory()->create(['password' => 'known-password']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.force-reset', $target))
            ->assertSessionHas('reveals');

        $this->assertFalse(Hash::check('known-password', $target->fresh()->password));
    }

    public function test_impersonate_then_leave_round_trip(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_super_admin' => false, 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($target);

        $this->post(route('admin.impersonate.leave'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_cannot_impersonate_a_suspended_user(): void
    {
        $target = User::factory()->create(['status' => 'suspended']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.impersonate', $target))
            ->assertSessionHas('error');
    }

    public function test_delete_guards_self_and_last_superadmin_but_allows_others(): void
    {
        $admin = $this->superAdmin();

        // Can't delete the last SuperAdmin (also self here).
        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))->assertSessionHas('error');
        $this->assertModelExists($admin);

        // Can delete a regular user.
        $target = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($admin)->delete(route('admin.users.destroy', $target))->assertRedirect(route('admin.users.index'));
        $this->assertModelMissing($target);
    }

    public function test_invite_link_sets_a_password_and_signs_the_user_in(): void
    {
        $invited = User::factory()->create(['status' => 'invited', 'password' => 'unusable']);

        $signed = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $invited->id,
            'fp' => InvitationController::linkFingerprint($invited),
        ]);

        // Visiting the signed link authorizes the session to set this user's password.
        $this->get($signed)->assertOk();

        $this->post(route('invite.store', $invited), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a-brand-new-password', $invited->fresh()->password));
        $this->assertSame('active', $invited->fresh()->status);
        $this->assertAuthenticatedAs($invited->fresh());
    }

    public function test_invite_link_previews_the_invite_social_card(): void
    {
        $invited = User::factory()->create(['status' => 'invited', 'password' => 'unusable']);

        $signed = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $invited->id,
            'fp' => InvitationController::linkFingerprint($invited),
        ]);

        $this->get($signed)
            ->assertOk()
            ->assertSee('images/social/alias-tts-invite-og.png')
            ->assertSee('Create your account')
            ->assertDontSee('alias-tts-reset-og.png');
    }

    public function test_force_reset_link_previews_the_reset_social_card(): void
    {
        // A force-reset targets an existing active user, so the page shows the
        // "welcome back" reset card, not the new-user invite card.
        $active = User::factory()->create(['status' => 'active', 'password' => 'unusable']);

        $signed = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $active->id,
            'fp' => InvitationController::linkFingerprint($active),
        ]);

        $this->get($signed)
            ->assertOk()
            ->assertSee('images/social/alias-tts-reset-og.png')
            ->assertDontSee('alias-tts-invite-og.png');
    }

    public function test_a_set_password_link_cannot_be_used_a_second_time(): void
    {
        $invited = User::factory()->create(['status' => 'invited', 'password' => 'unusable']);

        $signed = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $invited->id,
            'fp' => InvitationController::linkFingerprint($invited),
        ]);

        // First use: valid link, password gets set.
        $this->get($signed)->assertOk();
        $this->post(route('invite.store', $invited), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        // The signature is still cryptographically valid and unexpired, but setting the
        // password changed its fingerprint — so the very same link is now dead.
        $this->post(route('logout'));
        $this->get($signed)->assertRedirect(route('login'));

        // And a fresh session can't complete the POST either (no authorized GET).
        $this->post(route('invite.store', $invited), [
            'password' => 'a-second-password',
            'password_confirmation' => 'a-second-password',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('a-brand-new-password', $invited->fresh()->password));
        $this->assertFalse(Hash::check('a-second-password', $invited->fresh()->password));
    }

    public function test_suspended_user_cannot_reactivate_via_a_set_password_link(): void
    {
        // A still-valid set-password link (issued at invite or force-reset) must not
        // let a since-suspended account regain access. Otherwise suspension — the
        // security control enforced everywhere else — is bypassed.
        $suspended = User::factory()->create(['status' => 'suspended', 'password' => 'unusable']);

        $signed = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $suspended->id,
            'fp' => InvitationController::linkFingerprint($suspended),
        ]);

        // The signed GET still authorizes the session (link is genuinely valid)...
        $this->get($signed)->assertOk();

        // ...but completing it is refused: no password set, still suspended, no session.
        $this->post(route('invite.store', $suspended), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect(route('login'));

        $this->assertFalse(Hash::check('a-brand-new-password', $suspended->fresh()->password));
        $this->assertSame('suspended', $suspended->fresh()->status);
        $this->assertGuest();
    }

    public function test_invite_post_is_rejected_without_a_signed_visit(): void
    {
        $invited = User::factory()->create(['status' => 'invited']);

        // No prior signed GET → session not authorized → forbidden.
        $this->post(route('invite.store', $invited), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertForbidden();
    }
}
