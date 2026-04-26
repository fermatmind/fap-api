<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

interface BigFiveResultPageV2TransformerContract
{
    /**
     * @param  array<string,mixed>  $input
     * @return array{big5_result_page_v2?: array<string,mixed>}
     */
    public function transform(array $input): array;
}
