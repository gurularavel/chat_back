<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::orderBy('sort')->get());
    }

    public function store(Request $request): PlanResource
    {
        $plan = Plan::create($this->validated($request));
        AuditLog::record('admin.plan.created', $plan);

        return new PlanResource($plan);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $plan->update($this->validated($request, $plan));
        AuditLog::record('admin.plan.updated', $plan);

        return new PlanResource($plan);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        // Plans in use are only deactivated.
        if (Subscription::where('plan_id', $plan->id)->exists()) {
            $plan->update(['is_active' => false]);

            return response()->json(['deactivated' => true]);
        }

        $plan->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => [$required, 'array'],
            'name.*' => ['string', 'max:80'],
            'description' => ['nullable', 'array'],
            'price_per_seat' => [$required, 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'interval' => ['sometimes', Rule::in(['month', 'year'])],
            'min_seats' => ['sometimes', 'integer', 'min:1'],
            'max_seats' => ['nullable', 'integer', 'min:1'],
            'limits' => [$required, 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'is_trial_plan' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer'],
        ]);
    }
}
