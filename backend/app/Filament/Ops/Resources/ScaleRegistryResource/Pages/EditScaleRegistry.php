<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Models\AuditLog;
use App\Models\ScaleRegistryV2;
use App\Services\Scale\ScaleRegistryWriter;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditScaleRegistry extends EditRecord
{
    protected static string $resource = ScaleRegistryResource::class;

    // The existing writer owns its complete transaction, then closes caches after commit.
    protected ?bool $hasDatabaseTransactions = false;

    #[Locked]
    public array $faqEditBaseline = [];

    #[Locked]
    public string $faqFormBaselineJson = '';

    #[Locked]
    public array $faqSaveReceipt = [];

    protected function afterFill(): void
    {
        $record = $this->getRecord();
        $content = json_decode($record->getRawOriginal('content_i18n_json') ?? '{}', false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($content->zh->faq ?? null) || $content->zh->faq === []) {
            $this->faqEditBaseline = [];
            $this->faqFormBaselineJson = '';

            return;
        }
        $form = $this->form->getState(shouldCallHooksBefore: false);
        $form['org_id'] = (int) $record->org_id;
        $form['code'] = (string) $record->code;
        // A string preserves opaque JSON types across Livewire's snapshot codec.
        $this->faqFormBaselineJson = json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->faqEditBaseline = [];
        $readingLegacy = false;
        try {
            $rows = DB::transaction(function () use ($record, &$readingLegacy): array {
                $rows = [];
                foreach (['scales_registry_v2', 'scales_registry'] as $table) {
                    $readingLegacy = $table === 'scales_registry';
                    $found = DB::table($table)->where('org_id', $record->org_id)->where('code', $record->code)->get();
                    $readingLegacy = false;
                    if ($found->count() !== 1) {
                        return [];
                    }
                    $rows[] = (array) $found->first();
                }

                return $rows;
            });
        } catch (QueryException $exception) {
            $info = $exception->errorInfo ?? [];
            $connection = DB::connection($exception->getConnectionName());
            $driver = $connection->getDriverName();
            $legacyMissingMessage = sprintf("Table '%s.%s' doesn't exist", $connection->getDatabaseName(), $connection->getTablePrefix().'scales_registry');
            $missingLegacy = $readingLegacy && (
                ($driver === 'mysql' && ($info[0] ?? null) === '42S02' && (int) ($info[1] ?? 0) === 1146
                    && ($info[2] ?? null) === $legacyMissingMessage)
                || ($driver === 'sqlite' && ($info[0] ?? null) === 'HY000' && (int) ($info[1] ?? 0) === 1
                    && ($info[2] ?? null) === 'no such table: scales_registry')
            );
            if (! $missingLegacy) {
                throw $exception;
            }

            return;
        }
        // Keep ordinary editing available for v2-only/org-local authority.
        // FAQ changes require a dual-table baseline and cannot fall back to wide writes.
        if ($rows === [] || $rows[0] !== $record->getRawOriginal() || $rows[0]['content_i18n_json'] !== $rows[1]['content_i18n_json']) {
            return;
        }
        [$v2, $legacy] = $rows;
        $this->faqEditBaseline = [
            'target' => ['org_id' => (int) $record->org_id, 'id' => (int) $record->getKey(), 'code' => $record->code, 'primary_slug' => $record->primary_slug],
            'baseline' => $this->rowFingerprints($v2, $legacy),
        ];
    }

    private function rowFingerprints(array $v2, array $legacy): array
    {
        return array_map(fn (array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), ['v2' => $v2, 'legacy' => $legacy]);
    }

    private function assertCacheReceipt(ScaleRegistryV2 $record): void
    {
        DB::transaction(function () use ($record): void {
            $rows = [];
            foreach (['scales_registry_v2', 'scales_registry'] as $table) {
                $found = DB::table($table)->where('org_id', $record->org_id)->where('code', $record->code)->get();
                if ($found->count() !== 1) {
                    throw new \DomainException('The saved FAQ authority changed; verify the change before refreshing.');
                }
                $rows[] = (array) $found->first();
            }
            $audit = AuditLog::withoutGlobalScopes()->find($this->faqSaveReceipt['audit_id'] ?? 0);
            $meta = $audit?->meta_json ?? [];
            if (! $audit || (int) $audit->org_id !== (int) $record->org_id || $audit->action !== 'scale_registry.faq.updated'
                || $audit->target_type !== 'scale_registry' || (string) $audit->target_id !== (string) $record->getKey()
                || ($meta['target']['code'] ?? null) !== $record->code || ($meta['target']['primary_slug'] ?? null) !== $record->primary_slug
                || ($meta['operation_id'] ?? null) !== ($this->faqSaveReceipt['operation_id'] ?? null)
                || ($meta['candidate_sha256'] ?? null) !== ($this->faqSaveReceipt['candidate_sha256'] ?? null)
                || hash('sha256', $meta['after_faq_json'] ?? '') !== ($this->faqSaveReceipt['candidate_sha256'] ?? null)
                || ($meta['post_baseline'] ?? null) !== ($this->faqSaveReceipt['post_baseline'] ?? null)
                || $this->rowFingerprints(...$rows) !== ($this->faqSaveReceipt['post_baseline'] ?? null)) {
                throw new \DomainException('The saved FAQ or its audit changed; do not close the previous cache operation.');
            }
        });
    }

    protected function getSavedNotification(): ?Notification
    {
        return match ($this->faqSaveReceipt['status'] ?? null) {
            'PARTIAL_CACHE_CLOSEOUT' => Notification::make()->danger()->title('FAQ saved; cache refresh needs retry')->body('Save again without changing the form to retry the refresh. Do not repeat the content edit.'),
            'APPLIED' => Notification::make()->success()->title('FAQ saved')->body('Change #'.$this->faqSaveReceipt['audit_id'].'. Public page verification is still required.'),
            'UNCHANGED' => Notification::make()->success()->title('No content changes')->body('No content was written. This does not verify a previous cache refresh.'),
            default => parent::getSavedNotification(),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateEqIntro')
                ->label('Update English EQ intro')
                ->visible(fn (): bool => $this->canUpdateEqIntro())
                ->fillForm(function (): array {
                    abort_unless($this->canUpdateEqIntro(), 403);
                    $baseline = app(ScaleRegistryWriter::class)->eqIntroBaseline(ScaleRegistryWriter::EQ_INTRO_TARGET);

                    return [...$baseline, 'after' => $baseline['before']];
                })
                ->form([
                    Hidden::make('baseline.v2'),
                    Hidden::make('baseline.legacy'),
                    Textarea::make('before')->label('Current intro')->readOnly()->rows(4)->required(),
                    Textarea::make('after')->label('Intro with appended text')->rows(8)->required()->maxLength(8192),
                ])
                ->modalSubmitActionLabel('Apply intro')
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('planEqIntro', arguments: ['plan' => true])->label('Validate only'),
                ])
                ->action(function (array $data, array $arguments): void {
                    abort_unless($this->canUpdateEqIntro(), 403);
                    try {
                        $result = app(ScaleRegistryWriter::class)->updateEqIntro([
                            'target' => ScaleRegistryWriter::EQ_INTRO_TARGET,
                            'baseline' => $data['baseline'],
                            'before' => $data['before'],
                            'after' => $data['after'],
                        ], ! ($arguments['plan'] ?? false));
                    } catch (\DomainException $exception) {
                        throw ValidationException::withMessages([$this->getMountedActionForm()->getStatePath().'.after' => $exception->getMessage()]);
                    }
                    if ($result['status'] === 'PARTIAL_CACHE_CLOSEOUT') {
                        Notification::make()->danger()->title('Intro saved; cache closeout failed')
                            ->body('Do not repeat the content update. Public readback is still required.')->send();
                    } else {
                        Notification::make()->success()->title($result['status'] === 'PLAN_READY'
                            ? 'Validation passed; no content written' : 'Intro saved; public readback required')->send();
                    }
                    $this->getRecord()->refresh();
                }),
        ];
    }

    private function canUpdateEqIntro(): bool
    {
        $record = $this->getRecord();

        return ScaleRegistryResource::canEdit($record)
            && (int) $record->org_id === 0
            && (int) $record->getKey() === 3
            && $record->code === 'EQ_60'
            && $record->primary_slug === ScaleRegistryWriter::EQ_INTRO_TARGET['primary_slug'];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ScaleRegistryV2, 404);
        abort_unless(ScaleRegistryResource::canEdit($record), 403);

        $data['org_id'] = (int) $record->org_id;
        $data['code'] = (string) $record->code;
        $writer = app(ScaleRegistryWriter::class);
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $originalContent = json_decode($record->getRawOriginal('content_i18n_json') ?? '{}', false, 512, JSON_THROW_ON_ERROR);
        if ($this->faqFormBaselineJson === '' && is_array($originalContent->zh->faq ?? null) && $originalContent->zh->faq !== []) {
            // A pre-deployment form cannot acquire a trusted snapshot from its payload.
            throw ValidationException::withMessages(['data.content_i18n_json.zh.faq' => 'Reload the editor before saving this FAQ.']);
        }
        if ($this->faqFormBaselineJson !== '') {
            if (json_encode($data, $flags) === $this->faqFormBaselineJson) {
                if (($this->faqSaveReceipt['status'] ?? null) === 'PARTIAL_CACHE_CLOSEOUT') {
                    try {
                        $record->refresh();
                        $this->assertCacheReceipt($record);
                    } catch (\DomainException $exception) {
                        throw ValidationException::withMessages(['data.content_i18n_json.zh.faq' => $exception->getMessage()]);
                    }
                    try {
                        $writer->invalidatePublicContentProjection((int) $record->org_id, (string) $record->code, array_values(array_unique([
                            (string) $record->primary_slug, ...(array) $record->slugs_json,
                        ])));
                        $this->faqSaveReceipt['status'] = 'APPLIED';
                    } catch (\Throwable) {
                        // The previous content commit remains authoritative; retry cache only.
                    }
                } else {
                    $this->faqSaveReceipt = ['status' => 'UNCHANGED'];
                }

                return $record->refresh();
            }
            $beforeFaq = json_decode($this->faqFormBaselineJson, false, 512, JSON_THROW_ON_ERROR)->content_i18n_json->zh->faq ?? [];
            $afterFaq = $data['content_i18n_json']['zh']['faq'] ?? [];
            if (json_encode($beforeFaq, $flags) !== json_encode($afterFaq, $flags)) {
                if ($this->faqEditBaseline === []) {
                    throw ValidationException::withMessages(['data.content_i18n_json.zh.faq' => 'An exact dual-table FAQ baseline is unavailable. Keep the FAQ unchanged or reconcile authority first.']);
                }
                try {
                    $saved = $writer->upsertScale($data, [...$this->faqEditBaseline, 'form_before_json' => $this->faqFormBaselineJson]);
                } catch (\DomainException $exception) {
                    throw ValidationException::withMessages(['data.content_i18n_json.zh.faq' => $exception->getMessage()]);
                }
                $this->faqSaveReceipt = $saved->getRelation('faqEditReceipt');
                $record->refresh();
                $this->fillForm();

                return $record;
            }
        }
        $this->faqSaveReceipt = [];
        $writer->upsertScale($data);
        $record->refresh();
        $this->fillForm();

        return $record;
    }
}
