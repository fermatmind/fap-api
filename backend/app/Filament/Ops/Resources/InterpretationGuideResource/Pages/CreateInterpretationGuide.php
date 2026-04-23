<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\InterpretationGuideResource\Pages;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\InterpretationGuide;
use Filament\Resources\Pages\CreateRecord;

class CreateInterpretationGuide extends CreateRecord
{
    protected static string $resource = InterpretationGuideResource::class;

    protected function afterCreate(): void
    {
        /** @var InterpretationGuide $record */
        $record = $this->getRecord()->fresh();

        if (ContentReleaseAudit::shouldDispatchPublishedFollowUp('interpretation_guide', $record, [
            'title',
            'summary',
            'body_md',
            'body_html',
            'seo_title',
            'seo_description',
            'test_family',
            'result_context',
            'audience',
        ])) {
            ContentReleaseAudit::log('interpretation_guide', $record, 'interpretation_guide_resource_create');
        }
    }
}
