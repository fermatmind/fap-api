<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class ReportContextBuilder
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function fromArray(array $payload): ReportContext
    {
        return ReportContext::fromArray($payload);
    }
}
