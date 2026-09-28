<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\Widget;
use App\Notifications\InvoiceNotification;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_widget_shows_the_bundled_icon_next_to_powered_by(): void
    {
        $workspace = $this->createWorkspace();
        $widget = Widget::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

        $this->getJson("/api/widget/{$widget->public_key}/config")
            ->assertOk()
            ->assertJsonPath('branding.logo_url', asset('brand/icon.png'));
        $this->assertFileExists(public_path('brand/icon.png'));

        // An uploaded logo still wins.
        PlatformSetting::put('branding_logo', 'branding/custom.png');
        $this->assertStringEndsWith('branding/custom.png', Branding::toArray()['logo_url']);
        $this->assertTrue(Branding::toArray()['custom_logo']);
    }

    public function test_invoices_and_their_emails_carry_the_full_logo(): void
    {
        $this->assertFileExists(public_path('brand/logo.png'));
        $invoice = Invoice::create([
            'workspace_id' => $this->createWorkspace()->id,
            'number' => 'INV-TEST-1',
            'status' => 'open',
            'lines' => [['description' => 'Business', 'kind' => 'month', 'seats' => 1, 'unit_price' => '29.00', 'amount' => '29.00']],
            'total' => '29.00',
            'seller' => ['name' => 'Redhopper', 'logo_url' => Branding::fullLogoUrl()],
            'buyer' => ['name' => 'Acme'],
        ]);

        $html = (string) (new InvoiceNotification($invoice, 'issued'))->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('src="'.asset('brand/logo.png').'"', $html);
        $this->assertStringNotContainsString('laravel.com/img/notification-logo', $html);
    }
}
