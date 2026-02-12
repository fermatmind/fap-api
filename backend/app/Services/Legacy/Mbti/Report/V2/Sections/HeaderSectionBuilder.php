<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Sections;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;

final class HeaderSectionBuilder implements SectionBuilderInterface
{
    public function build(array $legacyPayload, array $input): array
    {
        // Legacy payload has no header block; keep no-op for compatibility.
        return [];
    }
}
