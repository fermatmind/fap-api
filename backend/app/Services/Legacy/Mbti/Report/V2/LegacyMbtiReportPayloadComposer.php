<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report\V2;

use App\Services\Legacy\Mbti\Report\V2\Contracts\SectionBuilderInterface;
use App\Services\Legacy\Mbti\Report\V2\Sections\HeaderSectionBuilder;
use App\Services\Legacy\Mbti\Report\V2\Sections\HighlightsSectionBuilder;
use App\Services\Legacy\Mbti\Report\V2\Sections\MetaSectionBuilder;
use App\Services\Legacy\Mbti\Report\V2\Sections\NarrativeSectionBuilder;
use App\Services\Legacy\Mbti\Report\V2\Sections\RecommendationsSectionBuilder;
use App\Services\Legacy\Mbti\Report\V2\Sections\ScoresSectionBuilder;

/**
 * Legacy section composer retained for compat/test coverage only.
 */
final class LegacyMbtiReportPayloadComposer
{
    /**
     * @param  array<int,SectionBuilderInterface>  $sections
     */
    public function __construct(
        private readonly LegacyMbtiReportPayloadBuilderV2 $source,
        private readonly array $sections = [],
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $legacyPayload = $this->source->buildLegacyMbtiReportParts($input);
        $result = [];

        foreach ($this->resolvedSections() as $section) {
            $result = array_merge($result, $section->build($legacyPayload, $input));
        }

        return $result;
    }

    /**
     * @return array<int,SectionBuilderInterface>
     */
    private function resolvedSections(): array
    {
        if ($this->sections !== []) {
            return $this->sections;
        }

        return [
            new HeaderSectionBuilder,
            new ScoresSectionBuilder,
            new HighlightsSectionBuilder,
            new NarrativeSectionBuilder,
            new RecommendationsSectionBuilder,
            new MetaSectionBuilder,
        ];
    }
}
