<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditPersonalityVariantCloneContent extends EditRecord
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreReviewedEnglishClone')
                ->label('恢复接纳前状态')
                ->color('gray')
                ->visible(fn (): bool => PersonalityVariantCloneContentResource::canEdit($this->getRecord())
                    && app(PersonalityVariantCloneContentResource::class)->reviewedCloneRecoveryHash($this->getRecord()) !== null)
                ->modalDescription('仅当 clone 与独立 intro 仍为该次接纳版本时，保留 clone 字节并转为 draft，同时恢复原 intro 正文；不会删除记录或覆盖后续编辑。')
                ->fillForm(fn (): array => ['expected_hash' => app(PersonalityVariantCloneContentResource::class)->reviewedCloneRecoveryHash($this->getRecord())])
                ->form([Hidden::make('expected_hash')->required()])
                ->action(function (array $data): void {
                    app(PersonalityVariantCloneContentResource::class)->restoreReviewedClone($this->getRecord(), (string) $data['expected_hash']);
                    $this->fillForm();
                    Notification::make()->success()->title('已恢复接纳前状态，保留完整历史')->send();
                }),
        ];
    }
}
