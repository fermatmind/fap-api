<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Tool;

interface Platform12ReadOnlyTool
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function invoke(array $input): array;
}
