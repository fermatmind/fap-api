<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Models\Sku;
use App\Services\Report\ReportAccess;
use Throwable;

final class ReportUnlockOptionResolver
{
    public function __construct(
        private readonly SkuCatalog $skus,
        private readonly ReportUnlockProductCatalog $products,
        private readonly BigFiveReportUnlockRolloutGate $bigFiveRollout,
    ) {}

    /**
     * @param  list<string>  $modulesAllowed
     * @param  list<string>  $modulesOffered
     * @return array<string,mixed>
     */
    public function resolve(
        string $scaleCode,
        string $locale,
        int $orgId,
        string $unlockStage,
        string $legacyUnlockSource,
        array $modulesAllowed,
        array $modulesOffered,
        ?string $threeChannelUnlockSource = null,
        ?Attempt $attempt = null,
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));
        $locale = trim($locale);
        $unlockStage = ReportAccess::normalizeUnlockStage($unlockStage);
        $rolloutEnabled = $this->rolloutEnabled($scaleCode, $locale, $attempt);
        $isLocked = $unlockStage !== ReportAccess::UNLOCK_STAGE_FULL;
        $modulesOffered = ReportAccess::normalizeModules($modulesOffered);
        $modulesAllowed = $this->modulesAllowed(
            $scaleCode,
            $unlockStage,
            $modulesAllowed,
            $modulesOffered
        );
        $sku = $rolloutEnabled ? $this->resolveExactSku($scaleCode, $orgId) : null;
        $paymentProviders = $this->paymentProviders();
        $paymentAvailable = $sku instanceof Sku && $paymentProviders !== [];

        $rewardedAvailable = $rolloutEnabled
            && $isLocked
            && (bool) config('report_unlock.providers.rewarded_ad.available', false);
        $selfPurchaseAvailable = $rolloutEnabled && $isLocked && $paymentAvailable;
        $giftAvailable = $selfPurchaseAvailable
            && (bool) config('report_unlock.providers.gift_purchase.available', false);

        return [
            'contract_version' => (string) config('report_unlock.contract_version', 'report_unlock.v1'),
            'scope' => (string) config('report_unlock.scope', 'attempt'),
            'benefit' => (string) config('report_unlock.benefit', 'full_report'),
            'access_level' => $this->accessLevel($unlockStage),
            'unlock_stage' => $unlockStage,
            'unlock_source' => ReportAccess::normalizeThreeChannelUnlockSource(
                $threeChannelUnlockSource ?? $legacyUnlockSource
            ),
            'legacy_unlock_source' => ReportAccess::normalizeUnlockSource($legacyUnlockSource),
            'modules_allowed' => $modulesAllowed,
            'modules_offered' => $modulesOffered,
            'rollout' => [
                'scale_code' => $scaleCode,
                'locale' => $locale,
                'state' => $rolloutEnabled ? 'enabled' : 'disabled',
                'commercialization_enabled' => $rolloutEnabled,
                'iq_readiness' => false,
            ],
            'unlock_options' => [
                $this->option('rewarded_ad', $rewardedAvailable, $rewardedAvailable ? null : $this->adUnavailableReason($rolloutEnabled, $isLocked)),
                $this->purchaseOption('self_purchase', $selfPurchaseAvailable, $rolloutEnabled, $isLocked, $sku, $paymentProviders),
                $this->purchaseOption('gift_purchase', $giftAvailable, $rolloutEnabled, $isLocked, $sku, $paymentProviders),
            ],
        ];
    }

    private function rolloutEnabled(string $scaleCode, string $locale, ?Attempt $attempt): bool
    {
        if ($scaleCode === '' || ReportAccess::isIqScale($scaleCode)) {
            return false;
        }

        $scales = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) config('report_unlock.rollout_scales', [ReportAccess::SCALE_MBTI])
        )));
        $locales = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('report_unlock.supported_locales', ['zh-CN'])
        )));

        if ($scaleCode === ReportAccess::SCALE_BIG5_OCEAN) {
            return in_array($locale, $locales, true)
                && $attempt instanceof Attempt
                && $this->bigFiveRollout->allows($attempt);
        }

        return in_array($scaleCode, $scales, true) && in_array($locale, $locales, true);
    }

    private function resolveExactSku(string $scaleCode, int $orgId): ?Sku
    {
        $contract = $this->products->forScale($scaleCode);
        if ($contract === null) {
            return null;
        }
        $candidate = (string) $contract['sku'];

        try {
            $meta = $this->skus->resolveSkuMeta($candidate, $scaleCode, $orgId);
            $row = $meta['sku_row'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! $row instanceof Sku) {
            return null;
        }

        $expectedPrice = (int) $contract['price_cents'];
        $expectedCurrency = (string) $contract['currency'];
        $benefitCode = strtoupper(trim((string) ($row->benefit_code ?? '')));
        $scope = strtolower(trim((string) ($row->scope ?? '')));
        $kind = strtolower(trim((string) ($row->kind ?? '')));

        if (
            (int) ($row->price_cents ?? -1) !== $expectedPrice
            || strtoupper(trim((string) ($row->currency ?? ''))) !== $expectedCurrency
            || $scope !== 'attempt'
            || $kind !== 'report_unlock'
            || $benefitCode !== $contract['benefit_code']
        ) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function paymentProviders(): array
    {
        $providers = [];
        foreach (['wechat_mini_virtual', 'apple_iap'] as $provider) {
            if ((bool) config("report_unlock.providers.{$provider}.available", false)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return array<string,mixed>
     */
    private function option(string $method, bool $available, ?string $reason): array
    {
        return [
            'method' => $method,
            'available' => $available,
            'unavailable_reason' => $available ? null : $reason,
        ];
    }

    /**
     * @param  list<string>  $providers
     * @return array<string,mixed>
     */
    private function purchaseOption(
        string $method,
        bool $available,
        bool $rolloutEnabled,
        bool $isLocked,
        ?Sku $sku,
        array $providers
    ): array {
        $base = $this->option(
            $method,
            $available,
            $available ? null : $this->unavailableReason($rolloutEnabled, $isLocked, $sku)
        );

        return array_merge($base, [
            'sku' => $sku?->sku ? (string) $sku->sku : null,
            'price_cents' => $sku instanceof Sku ? (int) $sku->price_cents : null,
            'currency' => $sku instanceof Sku ? strtoupper((string) $sku->currency) : null,
            'display_price' => $sku instanceof Sku ? $this->displayPrice($sku) : null,
            'providers' => $providers,
        ]);
    }

    private function unavailableReason(bool $rolloutEnabled, bool $isLocked, ?Sku $sku): string
    {
        if (! $rolloutEnabled) {
            return 'rollout_disabled';
        }
        if (! $isLocked) {
            return 'already_unlocked';
        }
        if ($sku === null) {
            return 'sku_unavailable';
        }

        return 'provider_unavailable';
    }

    private function adUnavailableReason(bool $rolloutEnabled, bool $isLocked): string
    {
        if (! $rolloutEnabled) {
            return 'rollout_disabled';
        }
        if (! $isLocked) {
            return 'already_unlocked';
        }

        return 'provider_unavailable';
    }

    private function displayPrice(Sku $sku): string
    {
        $currency = strtoupper(trim((string) $sku->currency));
        $amount = number_format(((int) $sku->price_cents) / 100, 2, '.', '');

        return $currency === 'CNY' ? '¥'.$amount : $currency.' '.$amount;
    }

    private function accessLevel(string $unlockStage): string
    {
        return match ($unlockStage) {
            ReportAccess::UNLOCK_STAGE_FULL => ReportAccess::REPORT_ACCESS_FULL,
            ReportAccess::UNLOCK_STAGE_PARTIAL => ReportAccess::REPORT_ACCESS_PARTIAL,
            default => ReportAccess::REPORT_ACCESS_FREE,
        };
    }

    /**
     * @param  list<string>  $modulesAllowed
     * @param  list<string>  $modulesOffered
     * @return list<string>
     */
    private function modulesAllowed(
        string $scaleCode,
        string $unlockStage,
        array $modulesAllowed,
        array $modulesOffered
    ): array {
        if ($unlockStage === ReportAccess::UNLOCK_STAGE_LOCKED) {
            return ReportAccess::defaultModulesAllowedForLocked($scaleCode);
        }

        $modulesAllowed = ReportAccess::normalizeModules($modulesAllowed);
        if ($unlockStage === ReportAccess::UNLOCK_STAGE_FULL) {
            return ReportAccess::normalizeModules(array_merge(
                $modulesAllowed,
                $modulesOffered,
                [
                    ReportAccess::freeModuleForScale($scaleCode),
                    ReportAccess::fullModuleForScale($scaleCode),
                ]
            ));
        }

        return $modulesAllowed;
    }
}
