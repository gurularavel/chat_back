<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Notifications\InvoiceNotification;
use App\Services\Billing\Gateways\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_renewal_invoice_is_issued_ahead_and_emailed_once(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5);
        $workspace->update(['billing_email' => 'accounts@acme.test']);

        $this->artisan('billing:remind')->assertSuccessful();
        $this->artisan('billing:remind')->assertSuccessful();

        $invoice = Invoice::withoutGlobalScopes()->sole();
        $subscription = $this->subscription($workspace);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame('58.00', $invoice->total);
        $this->assertTrue($invoice->due_at->equalTo($subscription->current_period_end));
        $this->assertSame(2, $invoice->lines[0]['seats']);
        $this->assertSame('month', $invoice->lines[0]['kind']);
        $this->assertSame('accounts@acme.test', $invoice->buyer['email']);

        $sent = $this->sentStages();
        $this->assertSame(['issued'], $sent);
        Notification::assertSentOnDemand(InvoiceNotification::class, function (InvoiceNotification $n, array $channels, AnonymousNotifiable $to) use ($workspace) {
            return in_array('accounts@acme.test', $to->routes['mail'], true) && in_array($workspace->owner->email, $to->routes['mail'], true);
        });
    }

    public function test_no_invoice_before_the_lead_time_or_for_trials(): void
    {
        $this->paidWorkspace(seats: 2, endsInDays: 20);
        $trial = $this->createWorkspace(name: 'Trial Co');
        $this->subscription($trial)->update(['current_period_end' => now()->addDays(2)]);

        $this->artisan('billing:remind');

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
        Notification::assertNothingSent();
    }

    public function test_customer_without_card_gets_reminders_until_the_due_date(): void
    {
        $this->paidWorkspace(seats: 1, endsInDays: 7, withCard: false);
        $this->artisan('billing:remind');

        $this->travel(4)->days();
        $this->artisan('billing:remind'); // 3 days left
        $this->travel(2)->days();
        $this->artisan('billing:remind'); // 1 day left
        $this->artisan('billing:remind'); // same day again: nothing new
        $this->travel(1)->days();
        $this->artisan('billing:remind'); // due today

        $this->assertSame(['issued', 'reminder', 'reminder', 'due'], $this->sentStages());
        $this->assertEqualsCanonicalizing(['issued', 'reminder-3', 'reminder-1', 'due'], Invoice::withoutGlobalScopes()->sole()->reminders);
    }

    public function test_paying_an_open_invoice_early_extends_the_period_and_sends_a_receipt(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5, withCard: false);
        $periodEnd = $this->subscription($workspace)->current_period_end;
        $this->artisan('billing:remind');
        $invoice = Invoice::withoutGlobalScopes()->sole();

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->postJson("/api/billing/invoices/{$invoice->id}/pay")
            ->assertOk()
            ->assertJsonStructure(['redirect_url']);

        $payment = Payment::withoutGlobalScopes()->sole();
        $this->assertSame('58.00', $payment->amount);
        FakeGateway::complete($payment->gateway_order_id, paid: true);
        $this->get('/billing/return?payment='.$payment->id)->assertRedirectContains('status=paid');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame($payment->id, $invoice->payment_id);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
        $subscription = $this->subscription($workspace);
        $this->assertTrue($subscription->current_period_start->equalTo($periodEnd));
        $this->assertTrue($subscription->current_period_end->equalTo($periodEnd->copy()->addMonth()));
        $this->assertContains('paid', $this->sentStages());

        // Already paid → cannot be paid again.
        $this->actingInWorkspace($workspace->owner, $workspace)->postJson("/api/billing/invoices/{$invoice->id}/pay")->assertUnprocessable();
    }

    public function test_automatic_renewal_settles_the_issued_invoice(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 3);
        $this->artisan('billing:remind');

        $this->travel(4)->days();
        $this->artisan('billing:renew');

        $invoice = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->payment_id);
        $this->assertSame(SubscriptionStatus::Active, $this->subscription($workspace)->status);
        $this->assertSame(['issued', 'paid'], $this->sentStages());
    }

    public function test_failed_renewal_emails_a_pay_link_and_keeps_the_invoice_open(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 0);
        PaymentMethod::withoutGlobalScopes()->each(fn (PaymentMethod $method) => $method->update(['token' => 'fail']));
        $this->subscription($workspace)->update(['current_period_end' => now()->subMinute()]);

        $this->artisan('billing:renew');

        $this->assertSame(SubscriptionStatus::PastDue, $this->subscription($workspace)->status);
        $this->assertSame(InvoiceStatus::Open, Invoice::withoutGlobalScopes()->sole()->status);
        $this->assertSame(['failed'], $this->sentStages());
    }

    public function test_cancelling_voids_the_open_invoice(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5);
        $this->artisan('billing:remind');

        $this->actingInWorkspace($workspace->owner, $workspace)->postJson('/api/billing/cancel')->assertOk();

        $this->assertSame(InvoiceStatus::Void, Invoice::withoutGlobalScopes()->sole()->status);
    }

    public function test_invoice_detail_has_seller_buyer_and_vat(): void
    {
        PlatformSetting::put('invoice_company', 'Redhopper MMC');
        PlatformSetting::put('invoice_tax_id', '1234567891');
        PlatformSetting::put('invoice_tax_rate', '18');
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5);
        $this->actingInWorkspace($workspace->owner, $workspace)
            ->patchJson('/api/billing/details', ['billing_name' => 'Acme LLC', 'billing_tax_id' => '9876543210'])
            ->assertOk();
        $this->artisan('billing:remind');
        $invoice = Invoice::withoutGlobalScopes()->sole();

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->getJson("/api/billing/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.seller.name', 'Redhopper MMC')
            ->assertJsonPath('data.seller.tax_id', '1234567891')
            ->assertJsonPath('data.buyer.name', 'Acme LLC')
            ->assertJsonPath('data.buyer.tax_id', '9876543210')
            ->assertJsonPath('data.total', '58.00')
            ->assertJsonPath('data.tax', '8.85')
            ->assertJsonPath('data.subtotal', '49.15')
            ->assertJsonPath('data.lines.0.seats', 2);

        $this->actingInWorkspace($workspace->owner, $workspace)
            ->getJson('/api/billing')
            ->assertJsonPath('open_invoice.id', $invoice->id);
    }

    public function test_every_email_stage_renders_with_the_pay_link(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5);
        $this->artisan('billing:remind');
        $invoice = Invoice::withoutGlobalScopes()->sole();

        foreach (InvoiceNotification::STAGES as $stage) {
            $html = (string) (new InvoiceNotification($invoice, $stage, '4169****1234', now()->addWeek()))
                ->toMail(new AnonymousNotifiable)
                ->render();

            $this->assertStringContainsString($invoice->number, $html, $stage);
            $this->assertStringContainsString(e($invoice->payUrl()), $html, $stage);
        }
        $this->assertStringContainsString('workspace='.$workspace->id, $invoice->payUrl());
    }

    public function test_invoices_of_another_workspace_are_not_visible(): void
    {
        $workspace = $this->paidWorkspace(seats: 2, endsInDays: 5);
        $this->artisan('billing:remind');
        $invoice = Invoice::withoutGlobalScopes()->sole();
        $other = $this->createWorkspace(name: 'Other');

        $this->actingInWorkspace($other->owner, $other)->getJson("/api/billing/invoices/{$invoice->id}")->assertNotFound();
        $this->actingInWorkspace($other->owner, $other)->postJson("/api/billing/invoices/{$invoice->id}/pay")->assertNotFound();
    }

    /** @return list<string> stages of all invoice emails, in order */
    private function sentStages(): array
    {
        return Notification::sent(new AnonymousNotifiable, InvoiceNotification::class)
            ->map(fn (InvoiceNotification $n) => $n->stage)
            ->values()
            ->all();
    }

    private function paidWorkspace(int $seats, int $endsInDays, bool $withCard = true): Workspace
    {
        $workspace = $this->createWorkspace();
        $method = $withCard
            ? PaymentMethod::create(['workspace_id' => $workspace->id, 'gateway' => 'fake', 'token' => 'tok_ok', 'masked_pan' => '4169****1234'])
            : null;

        $this->subscription($workspace)->update([
            'plan_id' => Plan::where('code', 'business')->value('id'),
            'seats' => $seats,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->addDays($endsInDays)->subMonth(),
            'current_period_end' => now()->addDays($endsInDays),
            'payment_method_id' => $method?->id,
        ]);

        return $workspace;
    }

    private function subscription(Workspace $workspace): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
    }
}
