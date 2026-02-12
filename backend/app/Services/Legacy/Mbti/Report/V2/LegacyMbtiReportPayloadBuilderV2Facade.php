<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2;

final class LegacyMbtiReportPayloadBuilderV2Facade
{
    public function __construct(private readonly LegacyMbtiReportPayloadComposer $composer)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        return $this->composer->compose($input);
    }
}
