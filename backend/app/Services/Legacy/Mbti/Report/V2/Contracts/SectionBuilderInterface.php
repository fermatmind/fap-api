<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Contracts;

interface SectionBuilderInterface
{
    /**
     * @param array<string,mixed> $legacyPayload
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function build(array $legacyPayload, array $input): array;
}
