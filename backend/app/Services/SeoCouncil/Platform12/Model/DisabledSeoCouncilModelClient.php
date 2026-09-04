<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

final class DisabledSeoCouncilModelClient implements SeoCouncilModelClient
{
    public function complete(SeoCouncilModelRequest $request): SeoCouncilModelResponse
    {
        throw new SeoCouncilModelFailure('MODEL_PROVIDER_DISABLED');
    }
}
