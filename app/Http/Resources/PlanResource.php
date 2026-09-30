<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->localizedName(),
            'names' => $this->name,
            'description' => $this->description,
            'price_per_seat' => $this->price_per_seat,
            'yearly_price_per_seat' => $this->yearly_price_per_seat,
            'currency' => $this->currency,
            'intervals' => $this->intervals(),
            'min_seats' => $this->min_seats,
            'max_seats' => $this->max_seats,
            'limits' => $this->limits,
            'features' => $this->features,
            'is_active' => $this->is_active,
            'is_trial_plan' => $this->is_trial_plan,
            'sort' => $this->sort,
        ];
    }
}
