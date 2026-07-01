<?php

namespace Tests\Unit\Actions;

use App\Actions\Auth\DeleteAccountAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeleteAccountActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_deletes_user_when_no_subscription(): void
    {
        $user = User::factory()->create();

        (new DeleteAccountAction())->execute($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cancels_subscription_then_deletes_user(): void
    {
        $user = User::factory()->create();
        // Create a fake active Cashier subscription row for this user.
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_fake_1',
            'stripe_status' => 'active',
            'stripe_price' => 'price_fake',
            'quantity' => 1,
        ]);

        // Partial-mock the subscription to intercept cancelNow().
        // NOTE: Cashier's Billable::subscription() has a strict `?Subscription`
        // return type; a plain Mockery::mock() fails that type check when
        // Mockery's partial-mock subclass enforces the parent's signature.
        // Typing the mock to Subscription::class satisfies it.
        $userMock = Mockery::mock($user)->makePartial();
        $subscriptionMock = Mockery::mock(Subscription::class);
        $subscriptionMock->shouldReceive('cancelNow')->once()->andReturnSelf();
        $userMock->shouldReceive('subscribed')->with('default')->andReturnTrue();
        $userMock->shouldReceive('subscription')->with('default')->andReturn($subscriptionMock);

        (new DeleteAccountAction())->execute($userMock);

        // Since we mocked the user, delete() on the mock still needs to run.
        // Use fresh model to check DB state (the real row is still deleted via the mock's underlying model).
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_rollback_when_user_delete_fails(): void
    {
        $user = User::factory()->create();
        $userMock = Mockery::mock($user)->makePartial();
        $userMock->shouldReceive('subscribed')->with('default')->andReturnFalse();
        $userMock->shouldReceive('delete')->andThrow(new RuntimeException('boom'));

        try {
            (new DeleteAccountAction())->execute($userMock);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_rollback_when_cancel_fails(): void
    {
        $user = User::factory()->create();
        $userMock = Mockery::mock($user)->makePartial();
        $subscriptionMock = Mockery::mock(Subscription::class);
        $subscriptionMock->shouldReceive('cancelNow')->andThrow(new RuntimeException('stripe down'));
        $userMock->shouldReceive('subscribed')->with('default')->andReturnTrue();
        $userMock->shouldReceive('subscription')->with('default')->andReturn($subscriptionMock);
        $userMock->shouldNotReceive('delete');

        try {
            (new DeleteAccountAction())->execute($userMock);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
