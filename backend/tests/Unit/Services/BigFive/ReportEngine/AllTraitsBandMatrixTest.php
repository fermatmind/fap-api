<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\AtomicBlockResolver;
use App\Services\BigFive\ReportEngine\Resolver\ModifierInjector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AllTraitsBandMatrixTest extends TestCase
{
    private const TRAITS = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @return iterable<string,array{0:string,1:string,2:int}>
     */
    public static function traitBandProvider(): iterable
    {
        foreach (self::TRAITS as $traitCode) {
            yield "{$traitCode} low" => [$traitCode, 'low', 12];
            yield "{$traitCode} mid" => [$traitCode, 'mid', 50];
            yield "{$traitCode} high" => [$traitCode, 'high', 88];
        }
    }

    #[DataProvider('traitBandProvider')]
    public function test_each_trait_band_resolves_atomic_blocks_with_provenance(string $targetTrait, string $band, int $percentile): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = $this->contextFor($targetTrait, $band, $percentile);

        $blocks = app(AtomicBlockResolver::class)->resolve($context, $registry);
        $blocks = app(ModifierInjector::class)->inject($context, $blocks, $registry);

        foreach (['hero_summary', 'domains_overview', 'domain_deep_dive', 'core_portrait', 'norms_comparison', 'action_plan'] as $sectionKey) {
            $block = collect($blocks[$sectionKey] ?? [])->first(
                static fn ($candidate): bool => $candidate->analytics['trait_code'] === $targetTrait
            );

            $this->assertNotNull($block, "{$sectionKey} missing {$targetTrait}");
            $payload = $block->toArray();
            $this->assertSame("atomic_{$targetTrait}_{$band}", $payload['block_id']);
            $this->assertNotEmpty($payload['resolved_copy']);
            $this->assertContains("atomic/{$targetTrait}.json#bands.{$band}.slots.{$sectionKey}", $payload['provenance']['atomic_refs']);
        }
    }

    private function contextFor(string $targetTrait, string $band, int $percentile): ReportContext
    {
        $domains = [];
        foreach (self::TRAITS as $traitCode) {
            $domains[$traitCode] = [
                'percentile' => $traitCode === $targetTrait ? $percentile : 50,
                'band' => $traitCode === $targetTrait ? $band : 'mid',
                'gradient_id' => strtolower($traitCode).'_g3',
            ];
        }
        $domains[$targetTrait]['gradient_id'] = match ($band) {
            'low' => strtolower($targetTrait).'_g1',
            'high' => strtolower($targetTrait).'_g5',
            default => strtolower($targetTrait).'_g3',
        };

        return new ReportContext('zh-CN', 'BIG5_OCEAN', 'big5_90', $domains, []);
    }
}
