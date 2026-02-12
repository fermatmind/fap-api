<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Sections;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;

final class HighlightsSectionBuilder implements SectionBuilderInterface
{
    public function build(array $legacyPayload, array $input): array
    {
        return [
            'highlights' => is_array($legacyPayload['highlights'] ?? null) ? $legacyPayload['highlights'] : [],
        ];
    }
}
