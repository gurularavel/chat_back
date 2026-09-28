<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Platform branding shown in the widget footer ("Powered by …"), managed by the superadmin.
 */
class Branding
{
    public const DEFAULT_NAME = 'Redhopper';

    public const DEFAULT_URL = 'https://redhopper.co';

    public const LOGO_DIRECTORY = 'branding';

    /** Bundled brand images in public/brand (the icon is the default "Powered by" logo). */
    public const DEFAULT_ICON = 'brand/icon.png';

    public const FULL_LOGO = 'brand/logo.png';

    /** @return array{name: string, url: string, logo_url: string, custom_logo: bool} */
    public static function toArray(): array
    {
        $logo = PlatformSetting::get('branding_logo');

        return [
            'name' => PlatformSetting::get('branding_name', self::DEFAULT_NAME),
            'url' => PlatformSetting::get('branding_url', self::DEFAULT_URL),
            'logo_url' => $logo ? Storage::disk('public')->url($logo) : asset(self::DEFAULT_ICON),
            'custom_logo' => (bool) $logo,
        ];
    }

    /** The full logo (icon + name) for invoices and emails. */
    public static function fullLogoUrl(): string
    {
        return asset(self::FULL_LOGO);
    }
}
