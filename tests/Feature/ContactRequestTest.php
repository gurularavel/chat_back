<?php

namespace Tests\Feature;

use App\Models\ContactRequest;
use App\Models\User;
use App\Notifications\NewContactRequestNotification;
use App\Notifications\NewSignupNotification;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ContactRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $form = [
        'first_name' => 'Aysel',
        'last_name' => 'Məmmədova',
        'email' => 'Aysel@Example.az',
        'country' => 'az',
        'phone' => '+994501234567',
    ];

    public function test_visitor_can_send_a_contact_request_and_the_team_is_emailed(): void
    {
        Notification::fake();

        $this->postJson('/api/contact-requests', $this->form)->assertCreated();

        $contact = ContactRequest::sole();
        $this->assertSame('aysel@example.az', $contact->email);
        $this->assertSame('AZ', $contact->country);
        $this->assertNull($contact->handled_at);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewContactRequestNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'support@kvadrat.az',
        );
    }

    public function test_phone_must_be_digits_only(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['+99450abc4567', '+994 50 123 45 67', '994501234567', '+123'] as $phone) {
            $this->postJson('/api/contact-requests', [...$this->form, 'phone' => $phone])->assertJsonValidationErrors('phone');
        }
        $this->assertSame(0, ContactRequest::count());
    }

    public function test_honeypot_is_rejected(): void
    {
        $this->postJson('/api/contact-requests', [...$this->form, 'website' => 'spam.example'])->assertJsonValidationErrors('website');
    }

    public function test_superadmin_lists_and_handles_requests(): void
    {
        Notification::fake();
        $this->postJson('/api/contact-requests', $this->form)->assertCreated();
        $contact = ContactRequest::sole();

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        $this->actingAs($admin)->getJson('/api/admin/contact-requests?status=new&search=aysel')->assertJsonPath('total', 1);
        $this->actingAs($admin)->patchJson("/api/admin/contact-requests/{$contact->id}", ['handled' => true])->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/contact-requests?status=new')->assertJsonPath('total', 0);
        $this->actingAs($admin)->deleteJson("/api/admin/contact-requests/{$contact->id}")->assertOk();
        $this->assertSame(0, ContactRequest::count());
    }

    public function test_regular_users_cannot_see_requests(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/contact-requests')->assertForbidden();
    }

    public function test_new_registration_emails_the_team(): void
    {
        Notification::fake();
        $this->seed(PlanSeeder::class);

        $this->postJson('/api/auth/register', [
            'name' => 'Orxan',
            'email' => 'orxan@shop.az',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'company' => 'Shop',
        ])->assertCreated();

        Notification::assertSentTo(
            new AnonymousNotifiable,
            NewSignupNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'support@kvadrat.az' && $n->workspace->name === 'Shop',
        );
    }
}
