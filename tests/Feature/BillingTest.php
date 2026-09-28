<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Billing\Gateways\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_activates_subscription_after_bank_confirmation(): void
    {
        $workspace = $this->createWorkspace();
        $plan = Plan::where('code', 'business')->first();

        $redirect = $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/billing/checkout', ['plan_id' => $plan->id, 'seats' => 3])
            ->assertOk()
            ->json('redirect_url');
        $this->assertStringContainsString('/billing/fake-checkout/', $redirect);

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('87.00', $payment->amount);

        // Customer pays on the hosted page, then comes back.
        FakeGateway::complete($payment->gateway_order_id, paid: true);
        $this->get('/billing/return?payment='.$payment->id)->assertRedirectContains('status=paid');

        $subscription = $this->subscription($workspace);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(3, $subscription->seats);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertNotNull($subscription->payment_method_id);
        $this->assertTrue($subscription->current_period_end->between(now()->addMonth()->subMinute(), now()->addMonth()->addMinute()));
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());

        // Returning twice does not apply the payment twice.
        $this->get('/billing/return?payment='.$payment->id);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    public function test_declined_payment_changes_nothing(): void
    {
        $workspace = $this->createWorkspace();
        $plan = Plan::where('code', 'starter')->first();

        $this->actingInWorkspace($workspace->owner, $workspace)->postJson('/api/billing/checkout', ['plan_id' => $plan->id, 'seats' => 1]);
        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        FakeGateway::complete($payment->gateway_order_id, paid: false);
        $this->get('/billing/return?payment='.$payment->id)->assertRedirectContains('status=failed');

        $this->assertSame(SubscriptionStatus::Trialing, $this->subscription($workspace)->status);
    }

    public function test_seat_increase_is_prorated_and_charged_to_stored_card(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, periodStartedDaysAgo: 15);

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/billing/seats', ['seats' => 4])
            ->assertOk()
            ->assertJsonPath('status', 'applied');

        $payment = Payment::withoutGlobalScopes()->where('type', 'seat_upgrade')->firstOrFail();
        // 2 extra seats × 29 AZN × ~half the period remaining
        $this->assertEqualsWithDelta(29.0, (float) $payment->amount, 2.0);
        $this->assertSame(4, $this->subscription($workspace)->seats);
    }

    public function test_seat_decrease_is_applied_at_renewal(): void
    {
        $workspace = $this->paidWorkspace(seats: 4);

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson('/api/billing/seats', ['seats' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'scheduled');
        $this->assertSame(4, $this->subscription($workspace)->seats);

        $this->subscription($workspace)->update(['current_period_end' => now()->subMinute()]);
        $this->artisan('billing:renew')->assertSuccessful();

        $subscription = $this->subscription($workspace);
        $this->assertSame(2, $subscription->seats);
        $this->assertNull($subscription->pending_seats);
        $this->assertTrue($subscription->current_period_end->isFuture());
        $this->assertSame('58.00', Payment::withoutGlobalScopes()->where('type', 'renewal')->value('amount'));
    }

    public function test_failed_renewals_go_past_due_then_expire(): void
    {
        $workspace = $this->paidWorkspace(seats: 2);
        PaymentMethod::withoutGlobalScopes()->each(fn (PaymentMethod $method) => $method->update(['token' => 'fail']));
        $this->subscription($workspace)->update(['current_period_end' => now()->subMinute()]);

        $this->artisan('billing:renew');
        $subscription = $this->subscription($workspace);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->renewal_attempts);

        for ($i = 0; $i < 2; $i++) {
            $this->subscription($workspace)->update(['next_retry_at' => now()->subMinute()]);
            $this->artisan('billing:renew');
        }

        $this->assertSame(SubscriptionStatus::Expired, $this->subscription($workspace)->status);
        $this->assertSame(3, Payment::withoutGlobalScopes()->where('status', PaymentStatus::Failed)->count());
    }

    public function test_trial_expires_through_the_scheduler(): void
    {
        $workspace = $this->createWorkspace();
        $this->subscription($workspace)->update(['current_period_end' => now()->subMinute()]);

        $this->artisan('billing:renew');

        $this->assertSame(SubscriptionStatus::Expired, $this->subscription($workspace)->status);
    }

    private function paidWorkspace(int $seats, int $periodStartedDaysAgo = 0): Workspace
    {
        $workspace = $this->createWorkspace();
        $method = PaymentMethod::create(['workspace_id' => $workspace->id, 'gateway' => 'fake', 'token' => 'tok_ok', 'masked_pan' => '4169****1234']);
        $start = now()->subDays($periodStartedDaysAgo);

        $this->subscription($workspace)->update([
            'plan_id' => Plan::where('code', 'business')->value('id'),
            'seats' => $seats,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays(30),
            'payment_method_id' => $method->id,
        ]);

        return $workspace;
    }

    private function subscription(Workspace $workspace): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
    }
}
