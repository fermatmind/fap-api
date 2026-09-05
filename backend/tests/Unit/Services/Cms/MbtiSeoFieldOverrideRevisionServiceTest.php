<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\MbtiSeoFieldOverrideRevisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MbtiSeoFieldOverrideRevisionServiceTest extends TestCase
{
    public function test_v1_writer_preserves_existing_marker_bytes(): void
    {
        $this->assertSame('2b1db6006c9146f08463333108bf4dc12882f98a9b79930c96a991c597f8284f', $this->snapshot()['snapshot_sha256']);
    }

    public function test_database_object_key_reordering_preserves_the_existing_digest(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['target'] = array_reverse($snapshot['target'], true);
        $snapshot['change'] = array_reverse($snapshot['change'], true);
        $snapshot = array_reverse($snapshot, true);

        $this->assertSame($snapshot['snapshot_sha256'], (new MbtiSeoFieldOverrideRevisionService)->snapshotSha256($snapshot));
    }

    public function test_digest_still_distinguishes_values_and_scalar_types(): void
    {
        $service = new MbtiSeoFieldOverrideRevisionService;
        $snapshot = $this->snapshot();
        $changedValue = $snapshot;
        $changedValue['change']['promoted'] = 'Different title';
        $changedType = $snapshot;
        $changedType['target']['org_id'] = '0';

        $this->assertNotSame($snapshot['snapshot_sha256'], $service->snapshotSha256($changedValue));
        $this->assertNotSame($snapshot['snapshot_sha256'], $service->snapshotSha256($changedType));
    }

    #[DataProvider('invalidFields')]
    public function test_missing_or_extra_fields_cannot_be_discarded_during_normalization(string $part, string $field, bool $remove): void
    {
        $snapshot = $this->snapshot();
        if ($remove) {
            unset($snapshot[$part][$field]);
        } else {
            $snapshot[$part][$field] = 'unexpected';
        }

        $this->expectException(RuntimeException::class);
        (new MbtiSeoFieldOverrideRevisionService)->snapshotSha256($snapshot);
    }

    public static function invalidFields(): array
    {
        return [
            'target missing' => ['target', 'route', true],
            'target extra' => ['target', 'extra', false],
            'change missing' => ['change', 'previous', true],
            'change extra' => ['change', 'extra', false],
        ];
    }

    private function snapshot(): array
    {
        return (new MbtiSeoFieldOverrideRevisionService)->markerSnapshot(
            MbtiSeoFieldOverrideRevisionService::STATUS_PROMOTED_LIVE,
            'fixture-promotion',
            str_repeat('a', 64),
            ['org_id' => 0, 'framework' => 'MBTI', 'locale' => 'en', 'runtime_type_code' => 'INTP-A', 'route' => '/en/personality/intp-a'],
            'Original title',
            'Promoted title',
        );
    }
}
