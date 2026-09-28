<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Key/value settings editable by the superadmin. Secret keys are stored encrypted.
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const SECRET_KEYS = ['platform_embedding_key', 'kapitalbank_password', 'mail_password'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('platform_settings', fn () => static::query()->pluck('value', 'key')->all());

        if (! array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }

        return in_array($key, self::SECRET_KEYS, true) ? Crypt::decryptString($all[$key]) : $all[$key];
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value !== null && in_array($key, self::SECRET_KEYS, true)) {
            $value = Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('platform_settings');
    }
}
