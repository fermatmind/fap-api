<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Sections;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;

final class RecommendationsSectionBuilder implements SectionBuilderInterface
{
    public function build(array $legacyPayload, array $input): array
    {
        return [
            'recommended_reads' => is_array($legacyPayload['recommended_reads'] ?? null)
                ? $legacyPayload['recommended_reads']
                : [],
        ];
    }
}
