<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerGuideResource\Pages;

use App\Console\Commands\UpdateExistingCareerGuideBody;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Models\AdminUser;
use App\Models\CareerGuide;
use App\Support\Rbac\PermissionNames;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;

class EditCareerGuide extends EditRecord
{
    protected static string $resource = CareerGuideResource::class;

    /**
     * @var array<int, array{career_job_id: int}>
     */
    protected array $workspaceRelatedJobsState = [];

    /**
     * @var array<int, array{personality_profile_id: int}>
     */
    protected array $workspaceRelatedPersonalityProfilesState = [];

    /**
     * @var array<string, mixed>
     */
    protected array $workspaceSeoState = [];

    public function getTitle(): string|Htmlable
    {
        return filled($this->getRecord()->title) ? (string) $this->getRecord()->title : __('ops.resources.career_guides.edit_title');
    }

    public function getSubheading(): ?string
    {
        return __('ops.resources.career_guides.edit_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCareerGuides')
                ->label(__('ops.resources.career_guides.actions.all'))
                ->url(CareerGuideResource::getUrl())
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
            Action::make('updatePublishedBody')
                ->label('仅更新已发布正文')
                ->visible(fn (): bool => $this->bodyUpdateOperator() instanceof AdminUser
                    && $this->isPublicMarkdownGuide($this->getRecord()))
                ->modalDescription('从当前公开指南生成锁定快照，只写入 Markdown 正文并记录新修订。')
                ->fillForm(function (): array {
                    $guide = $this->getRecord()->fresh();

                    return [
                        'baseline' => UpdateExistingCareerGuideBody::digest(UpdateExistingCareerGuideBody::state($guide)),
                        'body_md' => $guide->body_md,
                    ];
                })
                ->form([
                    Textarea::make('baseline')->label('公开指南快照')->readOnly()->required()->rows(2),
                    Textarea::make('body_md')->label('完整正文 Markdown')->required()->maxLength(100000)->rows(18),
                ])
                ->action(function (array $data): void {
                    $error = $this->updatePublishedBody($data);
                    if ($error === 'public_authority_event_failed') {
                        Notification::make()->title('正文已提交，公开变更事件未完成')->warning()->persistent()->send();
                    } elseif ($error !== null) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionForm()->getStatePath().'.body_md' => $error,
                        ]);
                    } else {
                        Notification::make()->title('已核对公开指南正文')->success()->send();
                    }
                    $this->redirect($this->getRedirectUrl());
                }),
        ];
    }

    private function bodyUpdateOperator(): ?AdminUser
    {
        $actor = auth((string) config('admin.guard', 'admin'))->user();

        return $actor instanceof AdminUser && ($actor->hasPermission(PermissionNames::ADMIN_OWNER)
            || $actor->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)) ? $actor : null;
    }

    private function isPublicMarkdownGuide(CareerGuide $guide): bool
    {
        return (int) $guide->org_id === 0 && $guide->status === CareerGuide::STATUS_PUBLISHED
            && $guide->is_public && ! $guide->published_at?->isFuture() && blank($guide->body_html);
    }

    private function updatePublishedBody(array $data): ?string
    {
        $actor = $this->bodyUpdateOperator();
        abort_unless($actor instanceof AdminUser, 403);
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) $actor->id, 403);

        $guide = $this->getRecord()->fresh();
        if (! $guide instanceof CareerGuide || ! $this->isPublicMarkdownGuide($guide)) {
            return 'existing_public_markdown_guide_required';
        }
        $before = UpdateExistingCareerGuideBody::state($guide);
        if (! hash_equals(UpdateExistingCareerGuideBody::digest($before), (string) ($data['baseline'] ?? ''))) {
            return 'before_state_drift';
        }

        $body = (string) $data['body_md'];
        $snapshot = $before['native_snapshot'];
        $snapshot['guide']['body_md'] = $body;
        $package = json_encode([
            'schema' => 'career_guide_existing_body_update.v1',
            'identity' => $guide->only(['id', 'org_id', 'guide_code', 'slug', 'locale']),
            'before' => [
                'attributes_sha256' => $before['attributes_sha256'],
                'native_snapshot_sha256' => UpdateExistingCareerGuideBody::digest($before['native_snapshot']),
                'revision_history_sha256' => UpdateExistingCareerGuideBody::digest($before['revisions']),
            ],
            'candidate' => ['body_md' => $body, 'body_md_sha256' => hash('sha256', $body), 'native_snapshot' => $snapshot],
        ], JSON_THROW_ON_ERROR);
        $file = tempnam(sys_get_temp_dir(), 'career-guide-body-');
        if ($file === false) {
            return 'package_required';
        }

        try {
            if (file_put_contents($file, $package) !== strlen($package)) {
                return 'package_required';
            }
            $hash = hash('sha256', $package);
            $releaseFile = dirname(base_path()).'/REVISION';
            $output = new BufferedOutput;
            $status = Artisan::call('career-guides:update-existing-body', [
                '--file' => $file,
                '--sha256' => $hash,
                '--execute' => true,
                '--deployed-sha' => is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '',
                '--admin-user-id' => (int) $actor->id,
                '--confirm' => $hash,
            ], $output);
            $result = json_decode($output->fetch(), true);

            return $status === 0 && ($result['ok'] ?? false) === true
                ? ($result['followup_error'] ?? null)
                : ($result['error'] ?? 'native_update_failed');
        } finally {
            unlink($file);
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('ops.actions.save_changes'))
            ->icon('heroicon-o-check-circle');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label(__('ops.resources.career_guides.actions.back_to_list'))
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
            'workspace_related_jobs' => CareerGuideWorkspace::workspaceRelatedJobsFromRecord($this->getRecord()),
            'workspace_related_personality_profiles' => CareerGuideWorkspace::workspaceRelatedPersonalityProfilesFromRecord($this->getRecord()),
            'workspace_seo' => CareerGuideWorkspace::workspaceSeoFromRecord($this->getRecord()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $locale = CareerGuideWorkspace::normalizeLocale((string) ($data['locale'] ?? 'en'));

        $this->workspaceRelatedJobsState = CareerGuideWorkspace::normalizeRelatedJobRows(
            is_array($data['workspace_related_jobs'] ?? null) ? $data['workspace_related_jobs'] : [],
            $locale,
        );
        $this->workspaceRelatedPersonalityProfilesState = CareerGuideWorkspace::normalizeRelatedPersonalityRows(
            is_array($data['workspace_related_personality_profiles'] ?? null) ? $data['workspace_related_personality_profiles'] : [],
            $locale,
        );
        $this->workspaceSeoState = is_array($data['workspace_seo'] ?? null) ? $data['workspace_seo'] : [];

        unset(
            $data['workspace_related_jobs'],
            $data['workspace_related_personality_profiles'],
            $data['workspace_seo'],
        );

        $guideCode = CareerGuideWorkspace::normalizeGuideCode(
            (string) ($data['guide_code'] ?? ''),
            (string) ($data['slug'] ?? ''),
        );

        $data['org_id'] = 0;
        $data['guide_code'] = $guideCode;
        $data['slug'] = CareerGuideWorkspace::normalizeSlug((string) ($data['slug'] ?? ''), $guideCode);
        $data['locale'] = $locale;
        $data['related_industry_slugs_json'] = CareerGuideWorkspace::normalizeIndustrySlugs($data['related_industry_slugs_json'] ?? []);
        $data['schema_version'] = 'v1';

        return $data;
    }

    protected function afterSave(): void
    {
        CareerGuideWorkspace::syncRelatedJobs($this->getRecord(), $this->workspaceRelatedJobsState);
        CareerGuideWorkspace::syncRelatedPersonalityProfiles($this->getRecord(), $this->workspaceRelatedPersonalityProfilesState);
        CareerGuideWorkspace::syncWorkspaceSeo($this->getRecord(), $this->workspaceSeoState);
        CareerGuideWorkspace::createRevision($this->getRecord(), __('ops.resources.common.revisions.workspace_update'));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('ops.resources.career_guides.notifications.updated');
    }

    protected function getRedirectUrl(): string
    {
        return CareerGuideResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
