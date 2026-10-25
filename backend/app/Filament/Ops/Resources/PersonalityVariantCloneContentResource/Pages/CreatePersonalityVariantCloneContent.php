<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Models\PersonalityProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Locked;

final class CreatePersonalityVariantCloneContent extends CreateRecord
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;

    #[Locked]
    public int $personalityProfileId = 0;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adoptReviewedEnglishClone')
                ->label('接纳已审英文结果包')
                ->visible(fn (): bool => $this->personalityProfileId > 0 && PersonalityVariantCloneContentResource::canCreate())
                ->modalDescription('仅创建当前档案下尚不存在的英文 clone，并在同一事务更新独立 intro。两份文件须为已审完整 JSON 的 Base64 与原始 SHA-256；不会重新生成正文。')
                ->form([
                    TextInput::make('variant_id')->label('Qualified variant ID')->integer()->minValue(1)->required(),
                    Textarea::make('clone_package_base64')->label('Reviewed clone package (Base64)')->required()->maxLength(1400000)->rows(5),
                    TextInput::make('clone_sha256')->label('Clone file SHA-256')->required()->regex('/^[a-f0-9]{64}$/D'),
                    Textarea::make('intro_package_base64')->label('Reviewed independent intro (Base64)')->required()->maxLength(1400000)->rows(5),
                    TextInput::make('intro_sha256')->label('Intro file SHA-256')->required()->regex('/^[a-f0-9]{64}$/D'),
                ])
                ->action(function (array $data): void {
                    abort_unless(PersonalityVariantCloneContentResource::canCreate(), 403);
                    $record = app(PersonalityVariantCloneContentResource::class)->adoptReviewedClone($this->personalityProfileId, (int) $data['variant_id'], $data);
                    $this->redirect(PersonalityVariantCloneContentResource::getUrl('edit', ['record' => $record]));
                }),
        ];
    }

    public function mount(): void
    {
        $this->personalityProfileId = max(0, (int) request()->query('profile', 0));

        parent::mount();

        if ($this->personalityProfileId > 0) {
            abort_unless(PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
                ->whereKey($this->personalityProfileId)
                ->exists(), 404);
        }
    }
}
