<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\SupportArticleResource\Pages;

use App\Filament\Ops\Resources\SupportArticleResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\SupportArticle;
use App\Services\Cms\RowBackedRevisionWorkspace;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class EditSupportArticle extends EditRecord
{
    protected static string $resource = SupportArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var SupportArticle $record */
        $record = $this->getRecord();

        return filled($record->title) ? (string) $record->title : __('ops.nav.support_articles');
    }

    public function getSubheading(): ?string
    {
        return __('ops.edit.descriptions.main_tabs');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToSupportArticles')
                ->label(__('ops.resources.articles.actions.back_to_list'))
                ->url(SupportArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Actions\DeleteAction::make()->visible(false),
        ];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->label(__('ops.resources.articles.actions.save'))
            ->icon('heroicon-o-check-circle');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SupportArticle $record */
        $record = $this->getRecord()->fresh();
        $editor = app(RowBackedRevisionWorkspace::class)->editorRecord('support_article', $record);

        return array_merge($data, [
            'title' => (string) $editor->title,
            'summary' => $editor->summary,
            'body_md' => (string) ($editor->body_md ?? ''),
            'body_html' => (string) ($editor->body_html ?? ''),
            'support_category' => (string) $editor->support_category,
            'support_intent' => (string) $editor->support_intent,
            'status' => (string) $editor->status,
            'review_state' => (string) $editor->review_state,
            'primary_cta_label' => $editor->primary_cta_label,
            'primary_cta_url' => $editor->primary_cta_url,
            'related_support_article_ids' => is_array($editor->related_support_article_ids) ? $editor->related_support_article_ids : [],
            'related_content_page_ids' => is_array($editor->related_content_page_ids) ? $editor->related_content_page_ids : [],
            'published_at' => $editor->published_at,
            'last_reviewed_at' => $editor->last_reviewed_at,
            'seo_title' => $editor->seo_title,
            'seo_description' => $editor->seo_description,
            'canonical_path' => $editor->canonical_path,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SupportArticle $record */
        $record = $record;
        $workspace = app(RowBackedRevisionWorkspace::class);
        $revisionStatus = $record->isSourceContent()
            ? SupportArticle::TRANSLATION_STATUS_SOURCE
            : ((string) $data['status'] === SupportArticle::STATUS_PUBLISHED
                ? SupportArticle::TRANSLATION_STATUS_PUBLISHED
                : ((string) $data['review_state'] === SupportArticle::REVIEW_APPROVED
                    ? SupportArticle::TRANSLATION_STATUS_APPROVED
                    : (in_array((string) $data['review_state'], [SupportArticle::REVIEW_SUPPORT, SupportArticle::REVIEW_PRODUCT_OR_POLICY], true)
                        ? SupportArticle::TRANSLATION_STATUS_HUMAN_REVIEW
                        : SupportArticle::TRANSLATION_STATUS_DRAFT)));

        $updated = $workspace->saveWorkingDraft(
            'support_article',
            $record,
            [
                'title' => trim((string) $data['title']),
                'summary' => $data['summary'] ?? null,
                'body_md' => (string) ($data['body_md'] ?? ''),
                'body_html' => (string) ($data['body_html'] ?? ''),
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'support_category' => (string) $data['support_category'],
                'support_intent' => (string) $data['support_intent'],
                'primary_cta_label' => $data['primary_cta_label'] ?? null,
                'primary_cta_url' => $data['primary_cta_url'] ?? null,
                'related_support_article_ids' => array_values((array) ($data['related_support_article_ids'] ?? [])),
                'related_content_page_ids' => array_values((array) ($data['related_content_page_ids'] ?? [])),
                'canonical_path' => $data['canonical_path'] ?? null,
            ],
            $revisionStatus,
            [
                'status' => (string) $data['status'],
                'review_state' => (string) $data['review_state'],
                'last_reviewed_at' => $data['last_reviewed_at'] ?? null,
                'published_at' => $data['published_at'] ?? null,
            ],
        );

        return (string) $data['status'] === SupportArticle::STATUS_PUBLISHED
            ? $workspace->publishWorkingRevision('support_article', $updated)
            : $updated;
    }

    protected function afterSave(): void
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
            ContentReleaseAudit::log('support_article', $record, 'support_article_resource_edit');
        }
    }
}
