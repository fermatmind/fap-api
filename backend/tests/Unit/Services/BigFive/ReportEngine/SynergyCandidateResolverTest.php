<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\SynergyCandidateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SynergyCandidateResolverTest extends TestCase
{
    #[DataProvider('contextProvider')]
    public function test_context_fixtures_collect_expected_synergy_candidates(string $fixtureName): void
    {
        $fixture = $this->fixture($fixtureName);
        $registry = app(RegistryLoader::class)->load();

        $candidates = app(SynergyCandidateResolver::class)->collect(ReportContext::fromArray($fixture), $registry);

        $this->assertSame(
            $fixture['expected_synergy_candidates'],
            array_map(static fn ($match): string => $match->synergyId, $candidates),
            $fixtureName
        );
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function contextProvider(): iterable
    {
        foreach ([
            'context_n_high_e_low',
            'context_o_high_c_low',
            'context_o_high_n_high',
            'context_c_high_n_high',
            'context_e_high_a_low',
            'context_multi_hit_conflict',
            'context_balanced_no_synergy',
        ] as $fixtureName) {
            yield $fixtureName => [$fixtureName];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $fixtureName): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/contexts/{$fixtureName}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
