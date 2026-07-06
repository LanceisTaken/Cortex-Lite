<?php

namespace Tests\Unit\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\UsageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function quota(): UsageQuota
    {
        return new UsageQuota();
    }

    public function test_used_counts_only_matching_type_within_window(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'reverse']);

        $this->assertSame(2, $this->quota()->used($user, 'recommend'));
        $this->assertSame(1, $this->quota()->used($user, 'reverse'));
    }

    public function test_used_ignores_events_older_than_the_window(): void
    {
        $user = User::factory()->create();
        UsageEvent::factory()->for($user)->create([
            'type' => 'recommend',
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);
        UsageEvent::factory()->for($user)->create([
            'type' => 'recommend',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->assertSame(1, $this->quota()->used($user, 'recommend'));
    }

    public function test_used_ignores_other_users_events(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->usageEvents()->create(['type' => 'recommend']);

        $this->assertSame(0, $this->quota()->used($user, 'recommend'));
    }

    public function test_remaining_counts_down_for_free_users(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->assertSame(2, $this->quota()->remaining($user, 'recommend'));
    }

    public function test_remaining_is_null_for_premium_users(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->assertNull($this->quota()->remaining($user, 'recommend'));
    }

    public function test_ensure_within_limit_passes_below_the_cap(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->quota()->ensureWithinLimit($user, 'recommend');

        $this->assertTrue(true);
    }

    public function test_ensure_within_limit_throws_at_the_cap(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $user->usageEvents()->create(['type' => 'recommend']);
        }

        try {
            $this->quota()->ensureWithinLimit($user, 'recommend');
            $this->fail('Expected QuotaExceededException.');
        } catch (QuotaExceededException $e) {
            $this->assertSame('recommend', $e->type);
            $this->assertSame(3, $e->limit);
            $this->assertSame(3, $e->used);
        }
    }

    public function test_ensure_within_limit_never_throws_for_premium(): void
    {
        $user = User::factory()->create(['is_premium' => true]);
        for ($i = 0; $i < 10; $i++) {
            $user->usageEvents()->create(['type' => 'recommend']);
        }

        $this->quota()->ensureWithinLimit($user, 'recommend');

        $this->assertTrue(true);
    }

    public function test_record_writes_one_event(): void
    {
        $user = User::factory()->create();

        $this->quota()->record($user, 'reverse');

        $this->assertSame(1, $user->usageEvents()->where('type', 'reverse')->count());
    }
}
