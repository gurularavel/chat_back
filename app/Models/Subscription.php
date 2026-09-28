<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'plan_id', 'seats', 'pending_seats', 'status', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'renewal_attempts', 'next_retry_at', 'payment_method_id'])]
class Subscription extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_retry_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }
}
