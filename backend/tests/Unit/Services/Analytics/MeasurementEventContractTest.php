<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\MeasurementEventContract;
use PHPUnit\Framework\TestCase;

final class MeasurementEventContractTest extends TestCase
{
    public function test_contract_freezes_aliases_authorities_and_privacy_boundary(): void
    {
        self::assertSame('test_start', MeasurementEventContract::canonicalize('start_test'));
        self::assertSame('test_complete', MeasurementEventContract::canonicalize('test_submit'));
        self::assertSame('test_complete', MeasurementEventContract::canonicalize('complete_test'));
        self::assertSame('result_view', MeasurementEventContract::canonicalize('view_result'));
        self::assertNotSame(
            MeasurementEventContract::canonicalize('test_submit'),
            MeasurementEventContract::canonicalize('result_ready'),
        );

        $resultReady = MeasurementEventContract::definition('result_ready');
        self::assertIsArray($resultReady);
        self::assertSame('backend_result_state', $resultReady['producer_authority'] ?? null);
        self::assertSame('server_only', $resultReady['exposure'] ?? null);
        self::assertSame('active', $resultReady['implementation'] ?? null);
        self::assertSame('distinct_internal_attempt', $resultReady['deduplication'] ?? null);
        self::assertContains('result_state', $resultReady['allowed_properties'] ?? []);
        self::assertContains('answers_or_score_detail', $resultReady['forbidden_data_classes'] ?? []);
        self::assertNotContains('attempt_id', $resultReady['allowed_properties'] ?? []);
        self::assertNotContains('url', $resultReady['allowed_properties'] ?? []);
    }
}
