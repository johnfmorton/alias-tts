<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Credit\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SuperAdmin credit surface on the Users screens (grant / adjust / make
 * unlimited, balance column, the detail page's statement) and the read-only
 * balance card a limited user sees on their own Account page.
 */
class UsersCreditAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function limitedUser(int $micro = 5_000_000): User
    {
        $user = User::factory()->create();
        $user->forceFill(['credit_balance_micro' => $micro])->save();

        return $user;
    }

    public function test_a_grant_sets_the_balance_and_writes_an_audited_ledger_row(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(); // unlimited until the first grant

        $this->actingAs($admin)
            ->post(route('admin.users.credit', $user), ['amount' => '5', 'note' => 'trial credit'])
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success');

        $this->assertSame(5_000_000, $user->fresh()->credit_balance_micro);

        $tx = CreditTransaction::sole();
        $this->assertSame(CreditTransaction::TYPE_GRANT, $tx->type);
        $this->assertSame($admin->id, $tx->created_by);
        $this->assertSame('trial credit', $tx->note);
        $this->assertSame(5_000_000, $tx->amount_micro);
    }

    public function test_a_negative_amount_adjusts_the_balance_down(): void
    {
        $user = $this->limitedUser(5_000_000);

        $this->actingAs($this->admin())
            ->post(route('admin.users.credit', $user), ['amount' => '-2'])
            ->assertSessionHas('success');

        $this->assertSame(3_000_000, $user->fresh()->credit_balance_micro);
        $this->assertSame(CreditTransaction::TYPE_ADJUSTMENT, CreditTransaction::sole()->type);
    }

    public function test_a_zero_amount_is_rejected(): void
    {
        $user = $this->limitedUser();

        $this->actingAs($this->admin())
            ->from(route('admin.users.index', ['user' => $user->id]))
            ->post(route('admin.users.credit', $user), ['amount' => '0'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_make_unlimited_clears_the_balance(): void
    {
        $user = $this->limitedUser();

        $this->actingAs($this->admin())
            ->delete(route('admin.users.credit.unlimited', $user))
            ->assertSessionHas('success');

        $this->assertNull($user->fresh()->credit_balance_micro);
        $this->assertSame(CreditTransaction::TYPE_ADJUSTMENT, CreditTransaction::sole()->type);

        // Doing it again is a no-op with a clear error, not a duplicate row.
        $this->actingAs($this->admin())
            ->delete(route('admin.users.credit.unlimited', $user))
            ->assertSessionHas('error');
        $this->assertSame(1, CreditTransaction::count());
    }

    public function test_the_credit_routes_are_superadmin_only(): void
    {
        $user = $this->limitedUser();
        $regular = User::factory()->create();

        $this->actingAs($regular)
            ->post(route('admin.users.credit', $user), ['amount' => '5'])
            ->assertStatus(403);

        $this->actingAs($regular)
            ->delete(route('admin.users.credit.unlimited', $user))
            ->assertStatus(403);
    }

    public function test_the_users_list_shows_balances(): void
    {
        $admin = $this->admin();
        $this->limitedUser(5_000_000);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertStatus(200)
            // The admin's own row reads Unlimited; the user's shows dollars.
            ->assertSee('Unlimited')
            ->assertSee('$5.00')
            ->assertSee('Balance');
    }

    public function test_the_detail_page_shows_credit_and_the_usage_timeline(): void
    {
        $admin = $this->admin();
        $user = $this->limitedUser(5_000_000);
        app(CreditService::class)->charge($user->id, 1000, 'chatterbox', 'api');

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertStatus(200)
            // Rail: the grant card with its escape hatch to unlimited.
            ->assertSee('Grant credit')
            ->assertSee('Make unlimited')
            // Statement head: lifetime billed-vs-actual next to the balance.
            ->assertSee('cost you')
            // The charge shows as a usage event with its metering detail.
            ->assertSee('api')
            ->assertSee('chatterbox');
    }

    public function test_the_users_list_shows_lifetime_spend_per_user(): void
    {
        $admin = $this->admin();
        $user = $this->limitedUser(20_000_000); // $20.00 balance
        // 280,000 chars of classic chatterbox @ $0.025/1k = $7.00 spend, which
        // also drops the balance to $13.00 — three distinct figures on the row.
        app(CreditService::class)->charge($user->id, 280_000, 'chatterbox', 'api');

        // No ?user= — the spend must be visible in the LIST, not just the drawer.
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertStatus(200)
            ->assertSee('Spend')      // column header
            ->assertSee('$7.00')      // lifetime spend
            ->assertSee('$13.00');    // remaining balance
    }

    public function test_the_account_page_shows_the_card_only_to_limited_users(): void
    {
        $limited = $this->limitedUser(2_500_000);
        $this->actingAs($limited)
            ->get(route('admin.account.index'))
            ->assertStatus(200)
            ->assertSee('$2.50')
            ->assertSee('pauses when it reaches $0');

        $unlimited = User::factory()->create();
        $this->actingAs($unlimited)
            ->get(route('admin.account.index'))
            ->assertStatus(200)
            ->assertDontSee('pauses when it reaches $0');
    }
}
