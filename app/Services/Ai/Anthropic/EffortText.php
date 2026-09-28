<?php

namespace App\Services\Ai\Anthropic;

use Prism\Prism\Contracts\PrismRequest;
use Prism\Prism\Providers\Anthropic\Handlers\Text;

/**
 * Adds `output_config.effort` (low | medium | high | xhigh | max) from the "effort" provider option.
 * Newer Claude models think adaptively by default; a low effort keeps short support answers fast.
 */
class EffortText extends Text
{
    #[\Override]
    public static function buildHttpRequestPayload(PrismRequest $request): array
    {
        $payload = parent::buildHttpRequestPayload($request);

        if ($effort = $request->providerOptions('effort')) {
            $payload['output_config'] = array_merge($payload['output_config'] ?? [], ['effort' => $effort]);
        }

        return $payload;
    }
}
