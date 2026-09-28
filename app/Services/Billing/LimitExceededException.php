<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class LimitExceededException extends RuntimeException
{
    public function __construct(public readonly string $limit, public readonly ?int $max = null)
    {
        parent::__construct(__('billing.limit_exceeded', ['limit' => __('billing.limits.'.$limit), 'max' => $max ?? '-']));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'limit_exceeded',
            'limit' => $this->limit,
            'max' => $this->max,
        ], 402);
    }
}
