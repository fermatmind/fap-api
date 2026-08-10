<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\MeasurementEventContract;
use App\Services\Analytics\MeasurementFailureEventContract;
use PHPUnit\Framework\TestCase;

final class MeasurementFailureEventContractTest extends TestCase
{
    public function test_failure_events_have_event_specific_privacy_contracts(): void
    {
        foreach (MeasurementFailureEventContract::EVENT_NAMES as $eventName) {
            $definition = MeasurementFailureEventContract::definition($eventName);
            $measurementDefinition = MeasurementEventContract::definition($eventName);

            $this->assertNotNull($definition);
            $this->assertNotNull($measurementDefinition);
            $this->assertSame('browser_observation', $definition['producer_authority'] ?? null);
            $this->assertSame('public_ingest_privacy_sanitized', $definition['exposure'] ?? null);
            $this->assertSame(MeasurementFailureEventContract::ALLOWED_PROPERTIES, $definition['allowed_properties'] ?? null);
            $this->assertSame(MeasurementFailureEventContract::ALLOWED_PROPERTIES, $measurementDefinition['allowed_properties'] ?? null);
            $this->assertNotSame(MeasurementEventContract::ALLOWED_PROPERTIES, $measurementDefinition['allowed_properties'] ?? null);
            $this->assertStringContainsString('exact', (string) ($definition['internal_correlation'] ?? ''));
            $this->assertStringContainsString('partial', (string) ($definition['coverage'] ?? ''));
        }
    }

    public function test_sanitizer_returns_only_fixed_safe_enumerations(): void
    {
        $safe = MeasurementFailureEventContract::sanitizeProperties([
            'scale_code' => 'big5_ocean',
            'form_code' => 'BIG5_90',
            'locale' => 'zh-cn',
            'device_class' => 'mobile',
            'browser_class' => 'chrome',
            'stage' => 'submit_attempt',
            'route' => '/api/v0.3/attempts/private-attempt/submit?token=secret',
            'status_group' => '500',
            'status_code' => 503,
            'error_code' => 'INTERNAL_SERVER_ERROR',
            'retry_bucket' => 'one',
            'attempt_id' => 'private-attempt',
            'request_id' => 'private-request',
            'url' => 'https://example.com/private?token=secret',
            'answers' => ['Q1' => 'A'],
            'message' => 'raw exception text',
        ]);

        $this->assertSame(MeasurementFailureEventContract::ALLOWED_PROPERTIES, array_keys($safe));
        $this->assertSame('BIG5_OCEAN', $safe['scale_code']);
        $this->assertSame('big5_90', $safe['form_code']);
        $this->assertSame('zh-CN', $safe['locale']);
        $this->assertSame('attempt_submit', $safe['stage']);
        $this->assertSame('attempt_submit', $safe['endpoint_class']);
        $this->assertSame('server_5xx', $safe['status_group']);
        $this->assertSame('server_error', $safe['error_class']);

        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['private-attempt', 'private-request', 'token=secret', 'Q1', 'raw exception text', '/api/'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_unknown_values_fail_closed_instead_of_passing_through(): void
    {
        $safe = MeasurementFailureEventContract::sanitizeProperties([
            'device_class' => 'person@example.com',
            'browser_class' => 'Custom Browser 123',
            'endpoint_class' => 'private-endpoint',
            'stage' => 'private stage',
            'status_group' => '799',
            'error_code' => 'person@example.com',
            'retry_bucket' => 'many',
        ]);

        foreach (['device_class', 'browser_class', 'endpoint_class', 'stage', 'status_group', 'error_class', 'retry_bucket'] as $field) {
            $this->assertSame('unknown', $safe[$field] ?? null);
        }
    }
}
