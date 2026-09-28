<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'price_per_seat', 'currency', 'interval', 'min_seats', 'max_seats', 'limits', 'features', 'is_active', 'is_trial_plan', 'sort'])]
class Plan extends Model
{
    /** Limit keys; null value = unlimited. */
    public const LIMIT_KEYS = ['widgets', 'documents', 'max_pdf_mb', 'total_storage_mb', 'conversations_per_month'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'price_per_seat' => 'decimal:2',
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_trial_plan' => 'boolean',
        ];
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function localizedName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->name[$locale] ?? $this->name['en'] ?? $this->code;
    }

    public function priceFor(int $seats): string
    {
        return number_format((float) $this->price_per_seat * $seats, 2, '.', '');
    }
}
