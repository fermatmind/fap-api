<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Models\PersonalityProfile;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Locked;

final class CreatePersonalityVariantCloneContent extends CreateRecord
{
    protected static string $resource = PersonalityVariantCloneContentResource::class;

    #[Locked]
    public int $personalityProfileId = 0;

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
