<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** Full invoice (lines, seller, buyer, payment) instead of the list summary. */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'overdue' => $this->isOverdue(),
            'type' => $this->type?->value,
            'period_start' => $this->period_start?->toIso8601String(),
            'period_end' => $this->period_end?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->tax_rate,
            'tax' => $this->tax,
            'total' => $this->total,
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->when($this->detailed, fn () => $this->lines),
            'seller' => $this->when($this->detailed, fn () => $this->seller ?? []),
            'buyer' => $this->when($this->detailed, fn () => $this->buyer ?? []),
            'payment' => $this->when($this->detailed && $this->relationLoaded('payment'), fn () => $this->payment ? [
                'id' => $this->payment->id,
                'gateway' => $this->payment->gateway,
                'status' => $this->payment->status->value,
                'paid_at' => $this->payment->paid_at?->toIso8601String(),
            ] : null),
        ];
    }
}
