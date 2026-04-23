<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SupportArticleResource\Pages;

use App\Filament\Ops\Resources\SupportArticleResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\SupportArticle;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportArticle extends CreateRecord
{
    protected static string $resource = SupportArticleResource::class;

    protected function afterCreate(): void
    {
        /** @var SupportArticle $record */
        $record = $this->getRecord()->fresh();

        if (ContentReleaseAudit::shouldDispatchPublishedFollowUp('support_article', $record, [
            'title',
            'summary',
            'body_md',
            'body_html',
            'seo_title',
            'seo_description',
            'support_category',
            'support_intent',
            'primary_cta_label',
            'primary_cta_url',
        ])) {
            ContentReleaseAudit::log('support_article', $record, 'support_article_resource_create');
        }
    }
}
