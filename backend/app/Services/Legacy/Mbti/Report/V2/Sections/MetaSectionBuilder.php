<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2\Sections;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;

final class MetaSectionBuilder implements SectionBuilderInterface
{
    public function build(array $legacyPayload, array $input): array
    {
        return [
            'borderline' => is_array($legacyPayload['borderline'] ?? null) ? $legacyPayload['borderline'] : [],
            'roles' => is_array($legacyPayload['roles'] ?? null) ? $legacyPayload['roles'] : [],
            'strategies' => is_array($legacyPayload['strategies'] ?? null) ? $legacyPayload['strategies'] : [],
            'identity_layer' => is_array($legacyPayload['identity_layer'] ?? null) ? $legacyPayload['identity_layer'] : [],
        ];
    }
}
