<?php

declare(strict_types=1);

namespace App\Services\Career\SalaryAssets;

use App\Models\CareerJobSalaryAsset;

final class CareerSalaryAssetImportStateMachine
{
    public const STATE_DRY_RUN = 'dry_run';

    public const STATE_STAGING_PREVIEW = CareerJobSalaryAsset::STATUS_STAGING_PREVIEW;

    public const STATE_EDITORIAL_REVIEW = CareerJobSalaryAsset::STATUS_EDITORIAL_REVIEW;

    public const STATE_APPROVED = CareerJobSalaryAsset::STATUS_APPROVED;

    public const STATE_PRODUCTION_IMPORTED = CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED;

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return [
            self::STATE_DRY_RUN,
            self::STATE_STAGING_PREVIEW,
            self::STATE_EDITORIAL_REVIEW,
            self::STATE_APPROVED,
            self::STATE_PRODUCTION_IMPORTED,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function transitions(): array
    {
        return [
            self::STATE_DRY_RUN => [self::STATE_STAGING_PREVIEW],
            self::STATE_STAGING_PREVIEW => [self::STATE_EDITORIAL_REVIEW],
            self::STATE_EDITORIAL_REVIEW => [self::STATE_APPROVED, self::STATE_STAGING_PREVIEW],
            self::STATE_APPROVED => [self::STATE_PRODUCTION_IMPORTED, self::STATE_EDITORIAL_REVIEW],
            self::STATE_PRODUCTION_IMPORTED => [],
        ];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions()[$from] ?? [], true);
    }

    public function canWriteStagingPreviewFrom(?string $currentStatus): bool
    {
        if ($currentStatus === null) {
            return true;
        }

        return $currentStatus === self::STATE_STAGING_PREVIEW
            || $this->canTransition($currentStatus, self::STATE_STAGING_PREVIEW);
    }

    public function canProductionImportFrom(?string $currentStatus): bool
    {
        return $currentStatus === self::STATE_APPROVED
            && $this->canTransition(self::STATE_APPROVED, self::STATE_PRODUCTION_IMPORTED);
    }

    public function canApproveFrom(?string $currentStatus): bool
    {
        return $currentStatus === self::STATE_EDITORIAL_REVIEW
            || $currentStatus === self::STATE_STAGING_PREVIEW;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'states' => $this->states(),
            'transitions' => $this->transitions(),
            'production_import_requires_from_status' => self::STATE_APPROVED,
            'production_import_without_approved_allowed' => false,
        ];
    }
}
