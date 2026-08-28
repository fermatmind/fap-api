<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Lifecycle;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use InvalidArgumentException;

final class ContentLifecycleReviewPolicy
{
    public const VERSION = 'seo.content_lifecycle_review.v1';

    public const CLAIM_RISKS = ['low', 'medium', 'high'];

    public function __construct(
        private readonly PageFamilyPolicyRegistry $families = new PageFamilyPolicyRegistry,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(string $family, string $locale, string $claimRisk): array
    {
        $definition = $this->families->families()[$family] ?? null;
        if (! is_array($definition) || ($definition['public_family'] ?? false) !== true) {
            throw new InvalidArgumentException('Lifecycle review policy requires a public Page Family.');
        }
        if (! in_array($locale, (array) data_get($definition, 'locale_policy.supported', []), true)) {
            throw new InvalidArgumentException('Lifecycle review policy requires an independently supported locale.');
        }
        if (! in_array($claimRisk, self::CLAIM_RISKS, true)) {
            throw new InvalidArgumentException('Lifecycle review policy requires low, medium, or high claim risk.');
        }

        $baseCycle = (int) $definition['review_cycle_days'];
        $cycle = match ($claimRisk) {
            'high' => min($baseCycle, 30),
            'medium' => min($baseCycle, 90),
            default => $baseCycle,
        };

        return [
            'schema_version' => self::VERSION,
            'page_family_policy_version' => PageFamilyPolicyRegistry::VERSION,
            'page_family_policy_hash' => $this->families->policyHash(),
            'page_family' => $family,
            'locale' => $locale,
            'claim_risk' => $claimRisk,
            'base_review_cycle_days' => $baseCycle,
            'review_cycle_days' => $cycle,
            'translation_fallback_allowed' => false,
        ];
    }
}
