<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use App\Support\OrgContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return 'Create Article';
    }

    public function getSubheading(): ?string
    {
        return 'Draft the article body in the main canvas, then finish publishing and SEO details in the side rail.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToArticles')
                ->label('All Articles')
                ->url(ArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create Article')
            ->icon('heroicon-o-check-circle');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Create & Add Another')
            ->icon('heroicon-o-document-duplicate');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Back to Articles')
            ->icon('heroicon-o-arrow-left');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_governance']);

        $authorAdminUserId = $this->workspaceGovernanceState['author_admin_user_id'] ?? null;
        if (is_numeric($authorAdminUserId) && (int) $authorAdminUserId > 0) {
            $data['author_admin_user_id'] = (int) $authorAdminUserId;
        }

        try {
            IntentRegistryService::assertNoConflict(
                ArticleResource::getModel(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? null,
                    'slug' => $data['slug'] ?? null,
                    'content_md' => $data['content_md'] ?? null,
                ],
                max(0, (int) app(OrgContext::class)->orgId()),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'workspace_governance.primary_query' => $e->getMessage(),
            ]);
        }

        return ContentGovernanceService::enforceReleaseManagedDraft($data);
    }

    protected function afterCreate(): void
    {
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Article created';
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
