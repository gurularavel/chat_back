<?php

namespace App\Services\Ai\Anthropic;

use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

/**
 * Prism's Anthropic provider, with the text payload extended by EffortText
 * (registered in AppServiceProvider through Prism::extend('anthropic')).
 */
class AnthropicProvider extends Anthropic
{
    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        return (new EffortText($this->client($request->clientOptions(), $request->clientRetry()), $request))->handle();
    }
}
