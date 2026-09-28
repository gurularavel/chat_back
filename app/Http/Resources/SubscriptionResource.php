<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'seats' => $this->seats,
            'pending_seats' => $this->pending_seats,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'masked_pan' => $this->paymentMethod->masked_pan,
                'brand' => $this->paymentMethod->brand,
                'expiry' => $this->paymentMethod->expiry,
            ] : null),
        ];
    }
}
