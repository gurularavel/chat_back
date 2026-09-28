<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Widget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WidgetOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Widget $widget;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('platform_settings');

        $this->workspace = $this->createWorkspace();
        $this->widget = Widget::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->first();
    }

    public function test_social_links_are_validated_and_only_published_when_enabled(): void
    {
        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['social_links' => ['instagram' => 'not-a-url', 'myspace' => 'https://x.y']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links', 'social_links.instagram']);

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", [
                'social_links' => [
                    'whatsapp' => 'https://wa.me/994501234567',
                    'instagram' => 'https://instagram.com/shop',
                    'phone' => '+994 50 123 45 67',
                    'email' => 'info@shop.az',
                    'telegram' => '',
                ],
                'show_social_links' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/shop')
            ->assertJsonMissingPath('data.social_links.telegram');

        $config = fn () => $this->getJson("/api/widget/{$this->widget->public_key}/config");
        $config()->assertJsonPath('social_links', []);

        $this->widget->update(['show_social_links' => true]);

        $config()->assertJsonPath('social_links', [
            ['network' => 'whatsapp', 'url' => 'https://wa.me/994501234567'],
            ['network' => 'instagram', 'url' => 'https://instagram.com/shop'],
            ['network' => 'phone', 'url' => 'tel:+994501234567'],
            ['network' => 'email', 'url' => 'mailto:info@shop.az'],
        ]);
    }

    public function test_customer_can_choose_a_preset_launcher_icon(): void
    {
        $this->getJson("/api/widget/{$this->widget->public_key}/config")->assertJsonPath('appearance.launcher_icon', 'chat');

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['appearance' => ['launcher_icon' => 'rocket']])
            ->assertUnprocessable();

        $this->actingInWorkspace($this->workspace->owner, $this->workspace)
            ->patchJson("/api/widgets/{$this->widget->id}", ['appearance' => ['launcher_icon' => 'headset']])
            ->assertOk()
            ->assertJsonPath('data.appearance.launcher_icon', 'headset');

        $this->getJson("/api/widget/{$this->widget->public_key}/config")->assertJsonPath('appearance.launcher_icon', 'headset');
    }

    public function test_customer_can_upload_and_remove_an_own_launcher_image(): void
    {
        Storage::fake('public');
        $as = fn () => $this->actingInWorkspace($this->workspace->owner, $this->workspace);

        $as()->post("/api/widgets/{$this->widget->id}/launcher-image", ['image' => UploadedFile::fake()->create('i.svg', 2, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $url = $as()->post("/api/widgets/{$this->widget->id}/launcher-image", ['image' => UploadedFile::fake()->image('icon.png', 128, 128)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.appearance.launcher_image_url');

        $this->assertStringContainsString("/storage/widget-icons/{$this->workspace->id}/", $url);
        $this->getJson("/api/widget/{$this->widget->public_key}/config")
            ->assertJsonPath('appearance.launcher_image_url', $url)
            ->assertJsonMissingPath('appearance.launcher_image');

        // A client cannot point the image at an arbitrary path, and saving other fields keeps the upload.
        $as()->patchJson("/api/widgets/{$this->widget->id}", ['appearance' => ['color' => '#112233', 'launcher_image' => '../../.env', 'launcher_image_url' => 'https://evil.test/x.png']])
            ->assertOk()
            ->assertJsonPath('data.appearance.launcher_image_url', $url);

        $as()->deleteJson("/api/widgets/{$this->widget->id}/launcher-image")
            ->assertOk()
            ->assertJsonPath('data.appearance.launcher_image_url', null);
        $this->assertCount(0, Storage::disk('public')->allFiles('widget-icons'));
    }

    public function test_launcher_image_of_another_workspace_cannot_be_changed(): void
    {
        Storage::fake('public');
        $other = $this->createWorkspace(name: 'Other');

        $this->actingInWorkspace($other->owner, $other)
            ->post("/api/widgets/{$this->widget->id}/launcher-image", ['image' => UploadedFile::fake()->image('icon.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    public function test_widget_can_be_hidden_while_no_operator_is_online(): void
    {
        $config = fn () => $this->getJson("/api/widget/{$this->widget->public_key}/config");

        $config()->assertJsonPath('enabled', true);

        $this->widget->update(['hide_when_offline' => true]);
        $config()->assertJsonPath('enabled', false);

        $this->workspace->members()->updateExistingPivot($this->workspace->owner_id, ['is_online' => true, 'last_seen_at' => now()]);
        $config()->assertJsonPath('enabled', true)->assertJsonPath('operators_online', true);
    }

    public function test_branding_defaults_to_redhopper_and_superadmin_can_change_it(): void
    {
        Storage::fake('public');

        $this->getJson("/api/widget/{$this->widget->public_key}/config")
            ->assertJsonPath('branding', ['name' => 'Redhopper', 'url' => 'https://redhopper.co', 'logo_url' => asset('brand/icon.png'), 'custom_logo' => false]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        // Tenants cannot touch platform branding.
        $this->actingAs($this->workspace->owner)
            ->post('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $logoUrl = $this->actingAs($admin)
            ->post('/api/admin/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('branding.logo_url');

        $this->assertStringContainsString('/storage/branding/', $logoUrl);
        $this->assertCount(1, Storage::disk('public')->files('branding'));

        $this->getJson("/api/widget/{$this->widget->public_key}/config")->assertJsonPath('branding.logo_url', $logoUrl);

        $this->actingAs($admin)->deleteJson('/api/admin/settings/logo')->assertOk()->assertJsonPath('branding.logo_url', asset('brand/icon.png'))->assertJsonPath('branding.custom_logo', false);
        $this->assertCount(0, Storage::disk('public')->files('branding'));
    }
}
