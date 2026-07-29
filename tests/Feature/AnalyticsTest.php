<?php

namespace Tests\Feature;

use App\Http\Controllers\InvitationController;
use App\Models\AppEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * First-party analytics: the AppEvent recorder (fire-and-forget, never
 * throws), the admin page-view middleware (full-page HTML GETs only), the
 * GA4 partial (public pages only, never on signed-URL pages), the
 * SuperAdmin-only Insights page, and the retention prune.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function user(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    public function test_record_writes_an_event_row(): void
    {
        $user = $this->user();

        AppEvent::record(AppEvent::PROJECT_CREATED, $user->id, AppEvent::SOURCE_STUDIO, ['project_id' => 'abc']);

        $this->assertDatabaseHas('app_events', [
            'name' => AppEvent::PROJECT_CREATED,
            'user_id' => $user->id,
            'source' => AppEvent::SOURCE_STUDIO,
        ]);
        $this->assertSame(['project_id' => 'abc'], AppEvent::first()->meta);
        $this->assertNotNull(AppEvent::first()->created_at);
    }

    public function test_record_is_disabled_by_the_master_switch(): void
    {
        config()->set('tts.analytics.events_enabled', false);

        AppEvent::record(AppEvent::PROJECT_CREATED);

        $this->assertDatabaseCount('app_events', 0);
    }

    public function test_record_never_throws_even_without_the_table(): void
    {
        Schema::drop('app_events');

        AppEvent::record(AppEvent::PROJECT_CREATED, null, AppEvent::SOURCE_STUDIO);

        // Reaching this line IS the assertion — a failed insert is swallowed.
        $this->assertTrue(true);
    }

    public function test_an_admin_page_view_is_recorded_with_its_route_name(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $event = AppEvent::where('name', AppEvent::PAGE_VIEW)->first();
        $this->assertNotNull($event);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('admin.dashboard', $event->meta['route']);
    }

    public function test_json_polls_and_posts_record_no_page_view(): void
    {
        $user = $this->user();

        // The Jobs page's status poll — the archetypal JSON GET that must not count.
        $this->actingAs($user)->getJson(route('admin.jobs.status'))->assertOk();

        // A POST is never a page view either (target doesn't matter — the
        // method/content-type filters run before any handler logic).
        $this->actingAs($user)->post(route('admin.dashboard.getting-started'), ['page' => 'dashboard']);

        $this->assertDatabaseCount('app_events', 0);
    }

    public function test_insights_is_superadmin_only(): void
    {
        $this->actingAs($this->admin())->get(route('admin.insights'))
            ->assertOk()
            ->assertSee('Daily events')
            ->assertSee('Feature use')
            ->assertSee('Top screens')
            ->assertSee('Generation volume by source');

        $this->actingAs($this->user())->get(route('admin.insights'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.insights'))->assertRedirect(route('login'));
    }

    public function test_insights_aggregates_events_and_screens(): void
    {
        $admin = $this->admin();
        $user = $this->user();

        AppEvent::record(AppEvent::PROJECT_CREATED, $user->id);
        AppEvent::record(AppEvent::PROJECT_CREATED, $admin->id);
        AppEvent::record(AppEvent::PAGE_VIEW, $user->id, AppEvent::SOURCE_STUDIO, ['route' => 'admin.studio.index']);

        $this->actingAs($admin)->get(route('admin.insights'))
            ->assertOk()
            ->assertSee(AppEvent::PROJECT_CREATED)
            ->assertSee('admin.studio.index');
    }

    public function test_ga_partial_renders_on_public_pages_only_when_configured(): void
    {
        $this->get('/')->assertOk()->assertDontSee('googletagmanager.com');

        config()->set('tts.analytics.ga_id', 'G-TEST123');

        $this->get('/')->assertOk()->assertSee('googletagmanager.com/gtag/js?id=G-TEST123', false);
        $this->get(route('login'))->assertOk()->assertSee('googletagmanager.com', false);
    }

    public function test_ga_never_renders_on_the_signed_invite_page(): void
    {
        config()->set('tts.analytics.ga_id', 'G-TEST123');

        $invitee = User::factory()->create(['status' => 'invited', 'password' => 'unusable']);
        $url = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $invitee->id,
            'fp' => InvitationController::linkFingerprint($invitee),
        ]);

        $this->get($url)->assertOk()->assertDontSee('googletagmanager.com');
    }

    public function test_prune_deletes_only_rows_past_the_retention_window(): void
    {
        config()->set('tts.analytics.retention_days', 180);

        AppEvent::record(AppEvent::PROJECT_CREATED);
        AppEvent::first()->forceFill(['created_at' => now()->subDays(200)])->save();
        AppEvent::record(AppEvent::PROJECT_SEALED);

        Artisan::call('analytics:prune', ['--dry-run' => true]);
        $this->assertDatabaseCount('app_events', 2);

        Artisan::call('analytics:prune');
        $this->assertDatabaseCount('app_events', 1);
        $this->assertSame(AppEvent::PROJECT_SEALED, AppEvent::first()->name);
    }

    public function test_prune_keeps_everything_when_retention_is_disabled(): void
    {
        config()->set('tts.analytics.retention_days', 0);

        AppEvent::record(AppEvent::PROJECT_CREATED);
        AppEvent::first()->forceFill(['created_at' => now()->subDays(2000)])->save();

        Artisan::call('analytics:prune');

        $this->assertDatabaseCount('app_events', 1);
    }
}
