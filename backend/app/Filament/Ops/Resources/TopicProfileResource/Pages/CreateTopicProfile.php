<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\TopicProfileResource\Pages;

use App\Filament\Ops\Resources\TopicProfileResource;
use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateTopicProfile extends CreateRecord
{
    protected static string $resource = TopicProfileResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSectionsState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceEntriesState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSeoState = [];

    public function getTitle(): string|Htmlable
    {
        return 'Create Topic';
    }

    public function getSubheading(): ?string
    {
        return 'Build a structured topic hub with fixed narrative sections, grouped entries, and metadata rails.';
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(TopicWorkspace::defaultFormState());

        $this->callHook('afterFill');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToTopics')
                ->label('All Topic Profiles')
                ->url(TopicProfileResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Create Topic')
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
            ->label('Back to Topics')
            ->icon('heroicon-o-arrow-left');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->workspaceSectionsState = is_array($data['workspace_sections'] ?? null) ? $data['workspace_sections'] : [];
        $this->workspaceEntriesState = is_array($data['workspace_entries'] ?? null) ? $data['workspace_entries'] : [];
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];

        unset($data['workspace_sections'], $data['workspace_entries'], $data['workspace_seo']);

        $topicCode = TopicWorkspace::normalizeTopicCode((string) ($data['topic_code'] ?? ''));

        $data['org_id'] = 0;
        $data['topic_code'] = $topicCode;
        $data['slug'] = TopicWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $topicCode);
        $data['locale'] = TopicWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));
        $data['created_by_admin_user_id'] = $this->currentAdminId();
        $data['updated_by_admin_user_id'] = $this->currentAdminId();

        return $data;
    }

    protected function afterCreate(): void
    {
        TopicWorkspace::syncWorkspaceSections($this->getRecord(), $this->workspaceSectionsState);
        TopicWorkspace::syncWorkspaceEntries($this->getRecord(), $this->workspaceEntriesState);
        TopicWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        $this->getRecord()->unsetRelation('sections');
        $this->getRecord()->unsetRelation('entries');
        $this->getRecord()->unsetRelation('seoMeta');
        TopicWorkspace::createRevision($this->getRecord(), 'Initial workspace snapshot');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Topic profile created';
    }

    protected function getRedirectUrl(): string
    {
        return TopicProfileResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    private function currentAdminId(): ?int
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }
}
