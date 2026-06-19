<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

use App\Models\CareerJobAiImpactAsset;

final class CareerAiImpactAssetImportStateMachine
{
    public const STATE_DRY_RUN = 'dry_run';

    public const STATE_STAGING_PREVIEW = CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW;

    public const STATE_EDITORIAL_REVIEW = CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW;

    public const STATE_APPROVED = CareerJobAiImpactAsset::STATUS_APPROVED;

    public const STATE_PRODUCTION_IMPORTED = CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED;

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
