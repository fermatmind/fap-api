<?php

namespace App\Services\Report\Composer;

use App\Services\Content\ContentPacksIndex;
use App\Services\ContentPackResolver;
use App\Services\Overrides\HighlightsOverridesApplier;
use App\Services\Overrides\ReportOverridesApplier;
use App\Services\Report\IdentityLayerBuilder;
use App\Services\Report\SectionCardGenerator;
use App\Services\Report\TagBuilder;

class ReportPayloadAssembler
{
    use ReportPayloadAssemblerComposeEntryTrait;
    use ReportPayloadAssemblerComposeBuildTrait;
    use ReportPayloadAssemblerComposeFinalizeTrait;
    use ReportPayloadAssemblerNormsAndContextTrait;
    use ReportPayloadAssemblerPackDocsTrait;
    use ReportPayloadAssemblerProfilesTrait;
    use ReportPayloadAssemblerContentGraphTrait;
    use ReportPayloadAssemblerOverridesTrait;

    public function __construct(
        private TagBuilder $tagBuilder,
        private SectionCardGenerator $cardGen,
        private HighlightsOverridesApplier $overridesApplier,
        private IdentityLayerBuilder $identityLayerBuilder,
        private ReportOverridesApplier $reportOverridesApplier,
        private ContentPackResolver $resolver,
        private ContentPacksIndex $packsIndex,
    ) {
    }

    public function assemble(ReportComposeContext $context): array
    {
        $options = $context->options;
        $options['variant'] = $context->variant;
        $options['report_access_level'] = $context->reportAccessLevel;
        $options['modules_allowed'] = $context->modulesAllowed;
        $options['modules_preview'] = $context->modulesPreview;

        return $this->composeInternal($context->attempt, $options, $context->result);
    }
}
