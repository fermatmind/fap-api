<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ScaleRegistryResource\Pages;

use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Models\ScaleRegistryV2;
use App\Services\Scale\ScaleRegistryWriter;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditScaleRegistry extends EditRecord
{
    protected static string $resource = ScaleRegistryResource::class;

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

        $data['org_id'] = (int) $record->org_id;
        $data['code'] = (string) $record->code;
        app(ScaleRegistryWriter::class)->upsertScale($data);

        return $record->refresh();
    }
}
