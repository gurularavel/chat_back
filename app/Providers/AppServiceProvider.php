<?php

namespace App\Providers;

use App\Models\PlatformSetting;
use App\Services\Ai\Anthropic\AnthropicProvider;
use App\Services\Billing\Gateways\FakeGateway;
use App\Services\Billing\Gateways\KapitalBankGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Knowledge\PgVectorStore;
use App\Services\Knowledge\VectorStore;
use App\Support\CurrentWorkspace;
use App\Support\MailSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: reset between requests and queued jobs
        $this->app->scoped(CurrentWorkspace::class);

        $this->app->bind(VectorStore::class, PgVectorStore::class);

        // Anthropic with `output_config.effort` support (see EffortText).
        $this->app->afterResolving(PrismManager::class, function (PrismManager $manager) {
            $manager->extend('anthropic', fn ($app, array $config) => new AnthropicProvider(
                apiKey: $config['api_key'],
                apiVersion: $config['version'],
                url: $config['url'] ?? 'https://api.anthropic.com/v1',
                betaFeatures: $config['anthropic_beta'] ?? null,
            ));
        });

        $this->app->bind(PaymentGateway::class, function () {
            $gateway = $this->platformSetting('payment_gateway', config('payments.default'));

            if ($gateway === 'kapitalbank') {
                $config = config('payments.gateways.kapitalbank');
                $config['username'] = $this->platformSetting('kapitalbank_username', $config['username']);
                $config['password'] = $this->platformSetting('kapitalbank_password', $config['password']);

                return new KapitalBankGateway($config);
            }

            return new FakeGateway;
        });
    }

    public function boot(): void
    {
        // `composer dev`: the default worker only listens to "default", but AI answers run on "ai"
        // and PDF processing on "documents" (same list as the production supervisor config).
        // Reverb delivers messages to the widget and the inbox in real time.
        if ($this->app->runningInConsole()) {
            DevCommands::except('queue');
            DevCommands::artisan('queue:listen --queue=ai,default,documents --tries=1 --timeout=0 --sleep=1', 'worker');
            DevCommands::artisan('reverb:start', 'reverb');
        }

        // SMTP settings from the superadmin panel; re-checked before each queued job (mails are queued).
        MailSettings::apply();
        Queue::before(fn () => MailSettings::apply());

        $frontend = rtrim(config('chat.frontend_url'), '/');

        ResetPassword::createUrlUsing(
            fn ($user, string $token) => "{$frontend}/reset-password?token={$token}&email=".urlencode($user->email)
        );

        VerifyEmail::createUrlUsing(function ($notifiable) use ($frontend) {
            $apiUrl = URL::temporarySignedRoute('verification.verify', now()->addDay(), [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]);

            return "{$frontend}/verify-email?url=".urlencode($apiUrl);
        });

        RateLimiter::for('widget', function (Request $request) {
            return [
                Limit::perMinute(60)->by('widget-ip:'.$request->ip()),
                Limit::perMinute(20)->by('widget-visitor:'.$request->header('X-Visitor-Token', $request->ip()).':'.$request->route('key')),
            ];
        });

        RateLimiter::for('widget-session', fn (Request $request) => Limit::perMinute(20)->by('widget-session:'.$request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by('auth:'.$request->ip()));
    }

    private function platformSetting(string $key, mixed $default): mixed
    {
        try {
            return PlatformSetting::get($key, $default);
        } catch (Throwable) {
            return $default; // before migrations
        }
    }
}
