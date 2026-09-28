<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'payment_id', 'subscription_id', 'number', 'status', 'type',
    'period_start', 'period_end', 'due_at', 'paid_at',
    'lines', 'subtotal', 'tax_rate', 'tax', 'total', 'currency', 'seller', 'buyer', 'reminders',
])]
class Invoice extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'type' => PaymentType::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'lines' => 'array',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'seller' => 'array',
            'buyer' => 'array',
            'reminders' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === InvoiceStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at?->isPast();
    }

    /** Dashboard page where the customer sees and pays this invoice (the link in every email). */
    public function payUrl(): string
    {
        return rtrim(config('chat.frontend_url'), '/').'/dashboard/billing?invoice='.$this->id.'&workspace='.$this->workspace_id;
    }
}
