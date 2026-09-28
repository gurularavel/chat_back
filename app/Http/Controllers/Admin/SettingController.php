<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Support\Branding;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Platform-wide settings: embedding fallback key, payment gateway, widget branding, invoice seller details, SMTP. */
class SettingController extends Controller
{
    private const KEYS = [
        'platform_embedding_provider',
        'platform_embedding_model',
        'platform_embedding_key',
        'payment_gateway',
        'kapitalbank_username',
        'kapitalbank_password',
        'branding_name',
        'branding_url',
        'invoice_company',
        'invoice_tax_id',
        'invoice_address',
        'invoice_email',
        'invoice_phone',
        'invoice_bank',
        'invoice_tax_rate',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    public function show(): JsonResponse
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $value = PlatformSetting::get($key);
            $settings[$key] = in_array($key, PlatformSetting::SECRET_KEYS, true)
                ? ($value ? '••••'.substr($value, -4) : null)
                : $value;
        }

        return response()->json(['data' => $settings, 'branding' => Branding::toArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_embedding_provider' => ['nullable', Rule::in(['openai', 'gemini'])],
            'platform_embedding_model' => ['nullable', 'string', 'max:100'],
            'platform_embedding_key' => ['nullable', 'string', 'max:500', function (string $attribute, string $value, \Closure $fail) {
                // Browsers like to autofill a saved login password here; real keys are long and have no spaces.
                if (! str_starts_with($value, '••••') && (strlen($value) < 20 || preg_match('/\s/', $value))) {
                    $fail(__('knowledge.invalid_api_key'));
                }
            }],
            'payment_gateway' => ['nullable', Rule::in(['fake', 'kapitalbank'])],
            'kapitalbank_username' => ['nullable', 'string', 'max:190'],
            'kapitalbank_password' => ['nullable', 'string', 'max:190'],
            'branding_name' => ['nullable', 'string', 'max:60'],
            'branding_url' => ['nullable', 'url:https,http', 'max:190'],
            // Seller details printed on every new invoice
            'invoice_company' => ['nullable', 'string', 'max:190'],
            'invoice_tax_id' => ['nullable', 'string', 'max:40'],
            'invoice_address' => ['nullable', 'string', 'max:500'],
            'invoice_email' => ['nullable', 'email', 'max:190'],
            'invoice_phone' => ['nullable', 'string', 'max:40'],
            'invoice_bank' => ['nullable', 'string', 'max:1000'],
            'invoice_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
            // Outgoing mail (SMTP); empty host = use the server's .env mailer
            'mail_host' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'],
            'mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:500'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        foreach ($data as $key => $value) {
            // Masked secrets sent back unchanged are ignored.
            if (in_array($key, PlatformSetting::SECRET_KEYS, true) && is_string($value) && str_starts_with($value, '••••')) {
                continue;
            }
            PlatformSetting::put($key, $value);
        }

        MailSettings::refresh();
        AuditLog::record('admin.settings.updated', null, ['keys' => array_keys($data)]);

        return $this->show();
    }

    /** Sends a test email with the saved SMTP settings and reports the server's error, if any. */
    public function testMail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        MailSettings::refresh();

        try {
            Mail::raw(__('mail.test.body', ['app' => Branding::toArray()['name']]), function (Message $message) use ($data) {
                $message->to($data['email'])->subject(__('mail.test.subject'));
            });
        } catch (Throwable $e) {
            report($e);
            abort(422, __('mail.test.failed', ['error' => Str::limit($e->getMessage(), 300)]));
        }

        AuditLog::record('admin.settings.test_mail', null, ['to' => $data['email']]);

        return response()->json(['ok' => true, 'mailer' => config('mail.default')]);
    }

    /**
     * Logo icon shown next to "Powered by" in every widget.
     * Raster formats only: an SVG served from the API domain could carry scripts.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=1024,max_height=1024'],
        ]);

        $this->deleteLogoFile();
        $path = $request->file('logo')->store(Branding::LOGO_DIRECTORY, 'public');
        PlatformSetting::put('branding_logo', $path);
        AuditLog::record('admin.branding.logo_uploaded');

        return $this->show();
    }

    public function deleteLogo(): JsonResponse
    {
        $this->deleteLogoFile();
        PlatformSetting::put('branding_logo', null);
        AuditLog::record('admin.branding.logo_deleted');

        return $this->show();
    }

    private function deleteLogoFile(): void
    {
        $current = PlatformSetting::get('branding_logo');
        if ($current) {
            Storage::disk('public')->delete($current);
        }
    }
}
