<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Billable;
use Tests\TestCase;

class CashierInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_must_verify_email(): void
    {
        $this->assertContains(MustVerifyEmail::class, class_implements(User::class));
    }

    public function test_user_uses_billable_trait(): void
    {
        $this->assertContains(Billable::class, class_uses_recursive(User::class));
    }

    public function test_stripe_columns_present_on_users(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'stripe_id'));
        $this->assertTrue(Schema::hasColumn('users', 'pm_type'));
        $this->assertTrue(Schema::hasColumn('users', 'pm_last_four'));
        $this->assertTrue(Schema::hasColumn('users', 'trial_ends_at'));
    }

    public function test_subscriptions_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('subscriptions'));
        $this->assertTrue(Schema::hasTable('subscription_items'));
    }
}
