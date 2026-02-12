<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Sections;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;

final class NarrativeSectionBuilder implements SectionBuilderInterface
{
    public function build(array $legacyPayload, array $input): array
    {
        return [
            'cards' => is_array($legacyPayload['cards'] ?? null) ? $legacyPayload['cards'] : [],
        ];
    }
}
