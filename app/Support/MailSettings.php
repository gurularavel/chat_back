<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Mail\MailManager;
use Throwable;

/**
 * Outgoing mail (SMTP server, sender) managed by the superadmin instead of .env.
 * Applied at boot and before every queued job, so long-running workers pick up
 * changes without a restart. Without a saved SMTP host the .env mailer is used.
 */
class MailSettings
{
    public const KEYS = ['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name'];

    private static ?string $applied = null;

    private static ?array $original = null;

    public static function apply(): void
    {
        try {
            $settings = [];
            foreach (self::KEYS as $key) {
                $settings[$key] = PlatformSetting::get($key);
            }
        } catch (Throwable) {
            return; // before migrations / no database
        }

        $fingerprint = md5(serialize($settings));
        if (self::$applied === $fingerprint) {
            return;
        }

        // Start from the .env configuration so clearing a setting restores it.
        self::$original ??= config('mail');
        config(['mail' => self::$original]);

        if ($settings['mail_host']) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,
                'mail.mailers.smtp.host' => $settings['mail_host'],
                'mail.mailers.smtp.port' => (int) ($settings['mail_port'] ?: 587),
                'mail.mailers.smtp.username' => $settings['mail_username'],
                'mail.mailers.smtp.password' => $settings['mail_password'],
                // ssl = implicit TLS (port 465); tls = STARTTLS, negotiated automatically by the smtp scheme
                'mail.mailers.smtp.scheme' => $settings['mail_encryption'] === 'ssl' ? 'smtps' : 'smtp',
            ]);
        }
        if ($settings['mail_from_address']) {
            config(['mail.from.address' => $settings['mail_from_address']]);
        }
        if ($settings['mail_from_name']) {
            config(['mail.from.name' => $settings['mail_from_name']]);
        }

        // Drop mailers built with the previous settings.
        if (app()->resolved('mail.manager')) {
            app(MailManager::class)->forgetMailers();
        }

        self::$applied = $fingerprint;
    }

    /** Settings changed in this process (admin saved them): re-apply on the next call. */
    public static function refresh(): void
    {
        self::$applied = null;
        self::apply();
    }
}
