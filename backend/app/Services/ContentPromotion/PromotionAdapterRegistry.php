<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\ContentPromotion\Adapters\ArticleCmsPromotionAdapter;
use App\Services\ContentPromotion\Adapters\CareerGuideCmsPromotionAdapter;
use App\Services\ContentPromotion\Adapters\CareerJobCmsPromotionAdapter;
use App\Services\ContentPromotion\Adapters\LegacyAuditIncompatiblePromotionAdapter;
use App\Services\ContentPromotion\Adapters\MbtiComparisonEnglishPromotionAdapter;
use App\Services\ContentPromotion\Adapters\MbtiResultPromotionAdapter;
use App\Services\ContentPromotion\Adapters\PersonalityCmsPromotionAdapter;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use DomainException;

final class PromotionAdapterRegistry
{
    /** @var list<ExactPackagePromotionAdapter> */
    private array $adapters;

    public function __construct(
        MbtiComparisonEnglishPromotionAdapter $mbtiComparison,
        MbtiResultPromotionAdapter $mbtiResults,
        PersonalityCmsPromotionAuthority $personalityAuthority,
        ArticleCmsPromotionAuthority $articleAuthority,
        CareerCmsPromotionAuthority $careerAuthority,
        PromotionRollbackSnapshotService $snapshots,
    ) {
        $adapters = [
            $mbtiComparison,
            $mbtiResults,
            new PersonalityCmsPromotionAdapter('W2', 'big-five', $personalityAuthority, $snapshots),
            new ArticleCmsPromotionAdapter($articleAuthority, $snapshots),
            new CareerGuideCmsPromotionAdapter($careerAuthority, $snapshots),
            new LegacyAuditIncompatiblePromotionAdapter('w4_riasec_legacy', 'W4', 'riasec'),
            new PersonalityCmsPromotionAdapter('W5', 'enneagram', $personalityAuthority, $snapshots),
            new LegacyAuditIncompatiblePromotionAdapter('w6_iq_legacy', 'W6', 'iq'),
            new LegacyAuditIncompatiblePromotionAdapter('w7_eq_legacy', 'W7', 'eq'),
            new CareerJobCmsPromotionAdapter($careerAuthority, $snapshots),
        ];
        $this->adapters = [];
        $ids = [];
        foreach ($adapters as $adapter) {
            if (isset($ids[$adapter->id()])) {
                throw new DomainException('duplicate_promotion_adapter');
            }
            $ids[$adapter->id()] = true;
            $this->adapters[] = $adapter;
        }
    }

    public function resolve(string $lane, ?string $subscope): ExactPackagePromotionAdapter
    {
        $matches = array_values(array_filter(
            $this->adapters,
            static fn (ExactPackagePromotionAdapter $adapter): bool => $adapter->supports($lane, $subscope),
        ));
        if (count($matches) !== 1) {
            throw new DomainException(count($matches) === 0
                ? 'promotion_adapter_not_audit_compatible'
                : 'promotion_adapter_ambiguous');
        }

        return $matches[0];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (ExactPackagePromotionAdapter $adapter): string => $adapter->id(), $this->adapters);
    }

    /** @return array<string, string> */
    public function capabilities(): array
    {
        $capabilities = [];
        foreach ($this->adapters as $adapter) {
            $capabilities[$adapter->id()] = $adapter->capability();
        }
        ksort($capabilities, SORT_STRING);

        return $capabilities;
    }

    /** @return array<string, string> */
    public function capabilitiesByLaneSubscope(): array
    {
        $capabilities = [];
        foreach ((array) config('content_promotion.adapter_capabilities', []) as $lane => $subscopes) {
            foreach ((array) $subscopes as $subscope => $declaredCapability) {
                $adapter = $this->resolve((string) $lane, (string) $subscope);
                $capabilities[(string) $lane.'/'.(string) $subscope] = $adapter->capability();
                if ($adapter->capability() !== $declaredCapability) {
                    throw new DomainException('promotion_adapter_capability_config_drift');
                }
            }
        }
        ksort($capabilities, SORT_STRING);

        return $capabilities;
    }
}
