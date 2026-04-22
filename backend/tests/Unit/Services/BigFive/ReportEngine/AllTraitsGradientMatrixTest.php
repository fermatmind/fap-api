<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\AtomicBlockResolver;
use App\Services\BigFive\ReportEngine\Resolver\ModifierInjector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AllTraitsGradientMatrixTest extends TestCase
{
    private const TRAITS = ['O', 'C', 'E', 'A', 'N'];

    private const REQUIRED_INJECTIONS = [
        'hero_summary.headline_extension',
        'domain_deep_dive.intensity_sentence',
        'core_portrait.load_sentence',
        'norms_comparison.compare_sentence',
        'action_plan.urgency_sentence',
    ];

    /**
     * @return iterable<string,array{0:string,1:string,2:int}>
     */
    public static function traitGradientProvider(): iterable
    {
        foreach (self::TRAITS as $traitCode) {
            foreach (range(1, 5) as $index) {
                yield "{$traitCode} g{$index}" => [$traitCode, strtolower($traitCode).'_g'.$index, $index];
            }
        }
    }

    #[DataProvider('traitGradientProvider')]
    public function test_each_trait_gradient_injects_sentence_level_slots(string $targetTrait, string $gradientId, int $gradientIndex): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = $this->contextFor($targetTrait, $gradientId, $gradientIndex);

        $blocks = app(ModifierInjector::class)->inject(
            $context,
            app(AtomicBlockResolver::class)->resolve($context, $registry),
            $registry
        );

        foreach (self::REQUIRED_INJECTIONS as $slot) {
            [$sectionKey, $injectionKey] = explode('.', $slot, 2);
            $block = collect($blocks[$sectionKey] ?? [])->first(
                static fn ($candidate): bool => $candidate->analytics['trait_code'] === $targetTrait
            );

            $this->assertNotNull($block, "{$sectionKey} missing {$targetTrait}");
            $payload = $block->toArray();
            $sentence = (string) ($payload['resolved_copy']['injections'][$injectionKey] ?? '');
            $this->assertNotSame('', trim($sentence));
            $this->assertGreaterThan(12, mb_strlen($sentence));
            $this->assertContains("modifiers/{$targetTrait}.json#gradients.{$gradientId}", $payload['provenance']['modifier_refs']);
        }
    }

    private function contextFor(string $targetTrait, string $gradientId, int $gradientIndex): ReportContext
    {
        $domains = [];
        foreach (self::TRAITS as $traitCode) {
            $domains[$traitCode] = [
                'percentile' => 50,
                'band' => 'mid',
                'gradient_id' => strtolower($traitCode).'_g3',
            ];
        }
        $domains[$targetTrait] = [
            'percentile' => [10, 30, 50, 70, 90][$gradientIndex - 1],
            'band' => match ($gradientIndex) {
                1, 2 => 'low',
                4, 5 => 'high',
                default => 'mid',
            },
            'gradient_id' => $gradientId,
        ];

        return new ReportContext('zh-CN', 'BIG5_OCEAN', 'big5_90', $domains, []);
    }
}
