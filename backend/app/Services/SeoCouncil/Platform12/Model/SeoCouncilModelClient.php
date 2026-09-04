<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

interface SeoCouncilModelClient
{
    public function complete(SeoCouncilModelRequest $request): SeoCouncilModelResponse;
}
