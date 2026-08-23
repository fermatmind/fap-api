<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerCurrentEnBatchPreparer;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Tests\TestCase;

final class CareerCurrentEnBatchPreparerTest extends TestCase
{
    private const SOURCE = '/Users/rainie/Desktop/1046个职业/en-career-pages';

    public function test_it_selects_all_1046_canonical_slugs_without_maturity_filtering(): void
    {
        $slugs = array_map(static fn (int $index): string => sprintf('career-%04d', $index), range(1, 1046));
        $controls = [$slugs[0], $slugs[1], $slugs[2]];

        $plan = app(CareerCurrentEnBatchPreparer::class)->planBatches($slugs, $controls);

        self::assertCount(21, $plan['batches']);
        self::assertSame(array_merge(array_fill(0, 20, 50), [46]), array_column($plan['batches'], 'target_count'));
        self::assertSame(1046, $plan['target_union_count']);
        self::assertSame([], $plan['duplicate_target_slugs']);
        self::assertSame([], $plan['missing_target_slugs']);
        self::assertSame([], $plan['unexpected_target_slugs']);
        self::assertSame($controls, $plan['batches'][0]['control_slugs']);
        self::assertSame(array_values(array_unique(array_merge($controls, $plan['batches'][0]['target_slugs']))), $plan['batches'][1]['control_slugs']);
    }

    public function test_the_en_preparer_has_no_ready_now_or_blocked_candidate_dependency(): void
    {
        $source = (string) file_get_contents(base_path('app/Domain/Career/Compilation/CareerCurrentEnBatchPreparer.php'));

        self::assertStringNotContainsString('READY_NOW', $source);
        self::assertStringNotContainsString('BLOCKED_NO_READY_CANDIDATE', $source);
    }

    public function test_it_maps_only_sealed_english_fields_and_preserves_shared_and_zh_authority(): void
    {
        if (! is_dir(self::SOURCE)) {
            self::markTestSkipped('The sealed English authority is not mounted in CI.');
        }

        $package = app(CareerCurrentAuthorityPackage::class);
        $authority = $package->load(base_path());
        $before = $authority['rows']['accountants-and-auditors'];
        $candidate = app(CareerCurrentEnBatchPreparer::class)->candidateRowForSource(
            self::SOURCE,
            'accountants-and-auditors',
            $before,
        );

        self::assertSame('Accountants and auditors', data_get($candidate, 'page_payload_json.page.en.hero.h1'));
        self::assertSame('Accountants and auditors', data_get($candidate, 'page_payload_json.page.en.breadcrumb.label'));
        self::assertSame('Accountants and auditors', data_get($candidate, 'seo_payload_json.en.h1'));
        self::assertSame(
            CareerCurrentAuthorityPackage::hashValue($package->publicProjection($before, 'zh-CN')),
            CareerCurrentAuthorityPackage::hashValue($package->publicProjection($candidate, 'zh-CN')),
        );
        self::assertSame($before['metadata_json']['presentation_v1'], $candidate['metadata_json']['presentation_v1']);
        self::assertSame($before['component_order_json'], $candidate['component_order_json']);
        self::assertSame(26, count($package->publicProjection($candidate, 'en')['component_order']));

        $hrefs = [];
        $publicProjection = $package->publicProjection($candidate, 'en');
        array_walk_recursive($publicProjection, static function (mixed $value, string|int $key) use (&$hrefs): void {
            if ($key === 'href' && is_string($value)) {
                $hrefs[] = $value;
            }
        });
        self::assertNotEmpty($hrefs);
        self::assertSame([], array_values(array_filter($hrefs, static fn (string $href): bool => str_starts_with($href, '/zh/'))));
    }
}
