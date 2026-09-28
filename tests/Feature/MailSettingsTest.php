<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_superadmin' => true])->save();
    }

    protected function tearDown(): void
    {
        // Settings live in the (rolled back) database; drop what this test applied to the config.
        MailSettings::refresh();
        parent::tearDown();
    }

    public function test_superadmin_smtp_settings_configure_the_mailer(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/settings', [
            'mail_host' => 'smtp.example.com',
            'mail_port' => 465,
            'mail_username' => 'noreply@example.com',
            'mail_password' => 'secret-pass',
            'mail_encryption' => 'ssl',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'Redhopper',
        ])->assertOk()
            ->assertJsonPath('data.mail_host', 'smtp.example.com')
            ->assertJsonPath('data.mail_password', '••••pass'); // never sent back in clear

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
        $this->assertSame('secret-pass', PlatformSetting::get('mail_password'));
        $this->assertNotSame('secret-pass', PlatformSetting::query()->find('mail_password')->value); // encrypted at rest

        // Saving the masked password back keeps the real one.
        $this->actingAs($this->admin)->putJson('/api/admin/settings', ['mail_password' => '••••pass'])->assertOk();
        $this->assertSame('secret-pass', PlatformSetting::get('mail_password'));

        // Clearing the host falls back to the .env mailer.
        $this->actingAs($this->admin)->putJson('/api/admin/settings', ['mail_host' => null])->assertOk();
        $this->assertSame('array', config('mail.default'));
    }

    public function test_test_mail_is_sent_with_the_configured_mailer(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/settings/test-mail', ['email' => 'me@example.com'])
            ->assertOk()
            ->assertJsonPath('mailer', 'array');

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('me@example.com', $sent[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_test_mail_reports_smtp_errors(): void
    {
        PlatformSetting::put('mail_host', '127.0.0.1');
        PlatformSetting::put('mail_port', '1'); // nothing listens here

        $this->actingAs($this->admin)
            ->postJson('/api/admin/settings/test-mail', ['email' => 'me@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Mail göndərilmədi') || str_contains($message, 'could not be sent'));
    }

    public function test_only_superadmins_manage_mail_settings(): void
    {
        $this->actingAs(User::factory()->create())->putJson('/api/admin/settings', ['mail_host' => 'evil.example.com'])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/settings/test-mail', ['email' => 'x@example.com'])->assertForbidden();
    }
}
