<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class CardsCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'cards';
    }

    public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
    {
        $result = new SelfCheckResult($this->name());

        $manifest = $ctx->getManifest();
        $manifestPath = (string) ($ctx->manifestPath ?? '');
        $packId = (string) ($manifest['pack_id'] ?? 'UNKNOWN_PACK');

        if ($manifestPath === '') {
            return $result->addError('manifest path missing');
        }

        $baseDir = dirname($manifestPath);

        $cardFiles = $manifest['assets']['cards'] ?? null;
        if (is_array($cardFiles) && !empty($cardFiles)) {
            $this->absorbLegacy(
                $result,
                'report_cards_*.json',
                $io->checkCards(
                    $baseDir,
                    $cardFiles,
                    $packId,
                    $manifest['schemas']['cards'] ?? null,
                    'cards'
                )
            );
        } else {
            $result->addNote('report_cards_*.json: SKIPPED (no manifest.assets.cards)');
        }

        $fallbackCardFiles = $manifest['assets']['fallback_cards'] ?? null;
        if (is_array($fallbackCardFiles) && !empty($fallbackCardFiles)) {
            $this->absorbLegacy(
                $result,
                'report_cards_fallback_*.json',
                $io->checkCards(
                    $baseDir,
                    $fallbackCardFiles,
                    $packId,
                    $manifest['schemas']['fallback_cards'] ?? null,
                    'fallback_cards'
                )
            );
        } else {
            $result->addNote('report_cards_fallback_*.json: SKIPPED (no manifest.assets.fallback_cards)');
        }

        return $result;
    }
}
