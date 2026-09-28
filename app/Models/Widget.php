<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'public_key', 'name', 'allowed_domains', 'appearance', 'texts', 'pre_chat_form', 'social_links', 'show_social_links', 'hide_when_offline', 'is_active'])]
class Widget extends Model
{
    use BelongsToWorkspace;

    public const DEFAULT_APPEARANCE = [
        'color' => '#4f46e5',
        'position' => 'right',
        'avatar_url' => null,
        'launcher_icon' => 'chat',
    ];

    /** Preset launcher (bubble) icons; keep in sync with widget/src/loader.ts and the dashboard. */
    public const LAUNCHER_ICONS = ['chat', 'message', 'messages', 'headset', 'help', 'bot', 'phone', 'sparkles'];

    /** Directory on the public disk for customer-uploaded launcher images. */
    public const LAUNCHER_IMAGE_DIRECTORY = 'widget-icons';

    /** Social networks a widget can link to; "phone" and "email" become tel:/mailto: links. */
    public const SOCIAL_NETWORKS = [
        'whatsapp', 'telegram', 'instagram', 'facebook', 'messenger', 'tiktok',
        'youtube', 'linkedin', 'x', 'website', 'phone', 'email',
    ];

    /**
     * System messages the server posts into the chat (lang/chat.php), overridable like the
     * widget's own texts (lang/widget.php). ":name" is replaced with the operator's name.
     */
    public const SYSTEM_TEXT_KEYS = ['handoff_waiting', 'handoff_offline', 'operator_joined', 'transferred'];

    protected static function booted(): void
    {
        static::creating(function (Widget $widget) {
            $widget->public_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'appearance' => 'array',
            'texts' => 'array',
            'pre_chat_form' => 'array',
            'social_links' => 'array',
            'show_social_links' => 'boolean',
            'hide_when_offline' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Empty whitelist = any domain allowed. Entries may be "example.com" or "*.example.com".
     */
    public function allowsHost(?string $host): bool
    {
        $domains = array_filter($this->allowed_domains ?? []);
        if ($domains === []) {
            return true;
        }
        if (! $host) {
            return false;
        }
        $host = strtolower($host);

        foreach ($domains as $domain) {
            $domain = strtolower(trim(preg_replace('#^https?://#', '', $domain), '/'));
            if ($host === $domain) {
                return true;
            }
            if (str_starts_with($domain, '*.') && str_ends_with($host, substr($domain, 1))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every overridable text key */
    public static function textKeys(): array
    {
        return [...array_keys(trans('widget', [], 'en')), ...self::SYSTEM_TEXT_KEYS];
    }

    /** @return array<string, string> built-in texts of a language */
    public static function defaultTexts(string $locale): array
    {
        $texts = trans('widget', [], $locale);
        foreach (self::SYSTEM_TEXT_KEYS as $key) {
            $texts[$key] = trans('chat.'.$key, [], $locale);
        }

        return $texts;
    }

    /** @return array<string, string> texts of a language with this widget's overrides applied */
    public function textsFor(string $locale): array
    {
        $overrides = array_filter($this->texts[$locale] ?? [], fn ($value) => filled($value));

        return array_merge(self::defaultTexts($locale), array_intersect_key($overrides, array_flip(self::textKeys())));
    }

    /** One text (e.g. a system message), with :placeholders replaced. */
    public function text(string $key, ?string $locale, array $replace = []): string
    {
        $locale = in_array($locale, config('chat.locales'), true) ? $locale : 'en';
        $text = $this->textsFor($locale)[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public function publicConfig(?string $locale = null): array
    {
        return [
            'key' => $this->public_key,
            'appearance' => $this->publicAppearance(),
            'texts' => $this->textsFor(in_array($locale, config('chat.locales'), true) ? $locale : 'en'),
            'pre_chat_form' => $this->pre_chat_form ?? ['enabled' => false, 'fields' => []],
            'social_links' => $this->show_social_links ? $this->orderedSocialLinks() : [],
        ];
    }

    /**
     * Appearance for clients: defaults applied, and the stored launcher image path
     * replaced by its public URL (launcher_image_url).
     */
    public function publicAppearance(): array
    {
        $appearance = array_merge(self::DEFAULT_APPEARANCE, $this->appearance ?? []);
        $path = $appearance['launcher_image'] ?? null;
        unset($appearance['launcher_image']);
        $appearance['launcher_image_url'] = $path ? Storage::disk('public')->url($path) : null;

        return $appearance;
    }

    public function deleteLauncherImage(): void
    {
        $path = $this->appearance['launcher_image'] ?? null;
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return list<array{network: string, url: string}> in SOCIAL_NETWORKS order, empty values dropped */
    public function orderedSocialLinks(): array
    {
        $links = $this->social_links ?? [];
        $result = [];

        foreach (self::SOCIAL_NETWORKS as $network) {
            $value = trim((string) ($links[$network] ?? ''));
            if ($value === '') {
                continue;
            }

            $result[] = ['network' => $network, 'url' => match ($network) {
                'phone' => 'tel:'.preg_replace('/[^\d+]/', '', $value),
                'email' => 'mailto:'.$value,
                default => $value,
            }];
        }

        return $result;
    }
}
