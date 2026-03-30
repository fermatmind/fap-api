<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Pages;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Services\Cms\ContentGovernanceService;
use App\Services\Cms\IntentRegistryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceGovernanceState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : 'Edit Article';
    }

    public function getSubheading(): ?string
    {
        return 'Refine the article body, release cues, and SEO metadata without leaving the editorial workspace.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToArticles')
                ->label('All Articles')
                ->url(ArticleResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Action::make('openPublicUrl')
                ->label('Open Public URL')
                ->url(fn (): ?string => ArticleWorkspace::publicUrl((string) $this->getRecord()->slug), shouldOpenInNewTab: true)
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()->is_public && filled(ArticleWorkspace::publicUrl((string) $this->getRecord()->slug))),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Save Changes')
            ->icon('heroicon-o-check-circle');
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'workspace_governance' => ContentGovernanceService::stateFromRecord($this->getRecord()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->workspaceGovernanceState = is_array($data['workspace_governance'] ?? null) ? $data['workspace_governance'] : [];

        unset($data['workspace_governance']);

        $authorAdminUserId = $this->workspaceGovernanceState['author_admin_user_id'] ?? null;
        if (is_numeric($authorAdminUserId) && (int) $authorAdminUserId > 0) {
            $data['author_admin_user_id'] = (int) $authorAdminUserId;
        }

        try {
            IntentRegistryService::assertNoConflict(
                $this->getRecord(),
                $this->workspaceGovernanceState,
                [
                    'title' => $data['title'] ?? $this->getRecord()->title,
                    'slug' => $data['slug'] ?? $this->getRecord()->slug,
                    'content_md' => $data['content_md'] ?? $this->getRecord()->content_md,
                ],
                max(0, (int) data_get($this->getRecord(), 'org_id', 0)),
                $this->getRecord(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'workspace_governance.primary_query' => $e->getMessage(),
            ]);
        }

        return ContentGovernanceService::preserveReleaseManagedState($this->getRecord(), $data);
    }

    protected function afterSave(): void
    {
        ContentGovernanceService::sync($this->getRecord(), $this->workspaceGovernanceState);
        IntentRegistryService::sync($this->getRecord(), $this->workspaceGovernanceState);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Article updated';
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
