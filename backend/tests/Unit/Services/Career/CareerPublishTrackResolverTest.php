<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Dataset\CareerPublishTrackReconciliationReader;
use App\Services\Career\Dataset\CareerPublishTrackResolver;
use RuntimeException;
use Tests\TestCase;

final class CareerPublishTrackResolverTest extends TestCase
{
    public function test_it_resolves_authorities_in_the_locked_priority_order(): void
    {
        $resolver = app(CareerPublishTrackResolver::class);

        $this->assertSame('candidate', $resolver->resolve('actuaries', ['runtime_publish_state' => 'published']));
        $this->assertSame('stable', $resolver->resolve('data-scientists', ['runtime_publish_state' => 'published']));
        $this->assertSame('runtime_publish_projection', $resolver->resolve('runtime-only-career', ['runtime_publish_state' => 'published']));
        $this->assertSame('review_needed', $resolver->resolve('computer-network-architects'));
        $this->assertSame('review_needed', $resolver->resolve('unclassified-career'));
    }

    public function test_historical_first_wave_conflicts_fail_closed_and_held_slugs_are_not_reconciled(): void
    {
        $resolver = app(CareerPublishTrackResolver::class);
        $reconciliation = app(CareerPublishTrackReconciliationReader::class)->bySlug();

        $this->assertSame('review_needed', $resolver->resolve('software-developers'));
        $this->assertSame('review_needed', $resolver->resolve('financial-analysts'));
        $this->assertSame('review_needed', $resolver->resolve('marketing-managers'));
        $this->assertSame('review_needed', $resolver->resolve('elementary-school-teachers-except-special-education'));

        foreach (['software-developers', 'digital-forensics-analysts', 'computer-occupations-all-other'] as $heldSlug) {
            $this->assertArrayNotHasKey($heldSlug, $reconciliation);
        }
    }

    public function test_reconciliation_contains_only_the_three_audited_gaps(): void
    {
        $reconciliation = app(CareerPublishTrackReconciliationReader::class)->bySlug();

        $this->assertSame([
            'computer-network-architects',
            'computer-systems-analysts',
            'information-security-analysts',
        ], array_keys($reconciliation));
        $this->assertSame(
            ['review_needed'],
            array_values(array_unique(array_column($reconciliation, 'publish_track'))),
        );
    }

    public function test_reconciliation_reader_rejects_duplicates_and_count_drift(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'career-track-reconciliation-');
        $this->assertNotFalse($path);

        file_put_contents($path, json_encode([
            'schema_version' => 'career.publish_track_reconciliation.v1',
            'authority_kind' => 'career_publish_track_reconciliation',
            'count_expected' => 2,
            'count_actual' => 2,
            'items' => [
                $this->reconciliationItem('duplicate-career'),
                $this->reconciliationItem('duplicate-career'),
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('blank or duplicate slug');
            app(CareerPublishTrackReconciliationReader::class)->bySlug($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function reconciliationItem(string $slug): array
    {
        return [
            'canonical_slug' => $slug,
            'publish_track' => 'review_needed',
            'reason' => 'No approved authority.',
            'evidence_refs' => ['test'],
        ];
    }
}
