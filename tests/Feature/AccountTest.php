<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_avatar_upload_stores_a_downsized_square_webp(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        // A large, non-square PNG proves both the downscale and the re-encode.
        $this->actingAs($user)
            ->post(route('admin.account.avatar'), [
                'avatar' => UploadedFile::fake()->image('me.png', 1200, 900),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('avatars/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk($disk)->assertExists($path);

        // Stored bytes are a real WebP, cropped to a 512x512 square — not the PNG.
        $info = getimagesizefromstring(Storage::disk($disk)->get($path));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame(512, $info[0]);
        $this->assertSame(512, $info[1]);
    }

    public function test_avatar_upload_never_upscales_a_small_source(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)
            ->post(route('admin.account.avatar'), [
                'avatar' => UploadedFile::fake()->image('tiny.jpg', 180, 180),
            ])
            ->assertRedirect();

        $info = getimagesizefromstring(Storage::disk($disk)->get($user->fresh()->avatar_path));
        $this->assertSame(180, $info[0]);
        $this->assertSame(180, $info[1]);
    }

    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->post(route('admin.account.avatar'), [
            'avatar' => UploadedFile::fake()->image('first.png', 600, 600),
        ]);
        $first = $user->fresh()->avatar_path;

        $this->actingAs($user)->post(route('admin.account.avatar'), [
            'avatar' => UploadedFile::fake()->image('second.png', 600, 600),
        ]);
        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk($disk)->assertMissing($first);
        Storage::disk($disk)->assertExists($second);
    }

    public function test_avatar_upload_rejects_a_disallowed_image_type(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        // A real GIF image — passes the `image` rule but fails the mimes whitelist.
        $this->actingAs($user)
            ->post(route('admin.account.avatar'), [
                'avatar' => UploadedFile::fake()->image('anim.gif', 200, 200),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_a_non_image_file(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        // A PDF renamed with image bytes it does not have — the `image` rule catches it.
        $this->actingAs($user)
            ->post(route('admin.account.avatar'), [
                'avatar' => UploadedFile::fake()->create('resume.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_a_file_that_is_too_large(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        // 5 MB is over the 4 MB (max:4096) cap.
        $this->actingAs($user)
            ->post(route('admin.account.avatar'), [
                'avatar' => UploadedFile::fake()->create('huge.jpg', 5120, 'image/jpeg'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_route_serves_webp_bytes_with_nosniff(): void
    {
        $disk = config('tts.storage_disk');
        Storage::fake($disk);
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->post(route('admin.account.avatar'), [
            'avatar' => UploadedFile::fake()->image('me.png', 500, 500),
        ]);

        $this->actingAs($user)
            ->get(route('admin.avatars.show', $user->fresh()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
