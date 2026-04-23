<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPageResource\Pages;

use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\ContentPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContentPage extends EditRecord
{
    protected static string $resource = ContentPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(false),
        ];
    }

    protected function afterSave(): void
    {
        /** @var ContentPage $record */
        $record = $this->getRecord()->fresh();

        if (ContentReleaseAudit::shouldDispatchPublishedFollowUp('content_page', $record, [
            'title',
            'kicker',
            'summary',
            'content_md',
            'content_html',
            'seo_title',
            'seo_description',
            'meta_description',
            'kind',
            'page_type',
            'template',
            'animation_profile',
        ])) {
            ContentReleaseAudit::log('content_page', $record, 'content_page_resource_edit');
        }
    }
}
