<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

final class SensitiveDataRedactorTest extends TestCase
{
    public function test_top_level_sensitive_key_is_redacted(): void
    {
        $redactor = new SensitiveDataRedactor();

        $data = [
            'api_key' => 'sk_test_123',
            'name' => 'demo',
        ];

        $result = $redactor->redact($data);

        $this->assertSame('[REDACTED]', $result['api_key']);
        $this->assertSame('demo', $result['name']);
    }

    public function test_nested_sensitive_key_is_redacted(): void
    {
        $redactor = new SensitiveDataRedactor();

        $data = [
            'meta' => [
                'client_secret' => 'cs_test_123',
                'headers' => [
                    'stripe-signature' => 'sig_123',
                ],
            ],
            'items' => [
                [
                    'private_key' => 'pk_123',
                ],
            ],
        ];

        $result = $redactor->redact($data);

        $this->assertSame('[REDACTED]', $result['meta']['client_secret']);
        $this->assertSame('[REDACTED]', $result['meta']['headers']['stripe-signature']);
        $this->assertSame('[REDACTED]', $result['items'][0]['private_key']);
    }

    public function test_non_sensitive_keys_are_kept(): void
    {
        $redactor = new SensitiveDataRedactor();

        $data = [
            'age' => 28,
            'display_name' => 'alice',
            'nested' => [
                'score' => 96,
                'enabled' => true,
            ],
        ];

        $result = $redactor->redact($data);

        $this->assertSame($data, $result);
    }

    public function test_psych_privacy_array_key_is_replaced_with_summary(): void
    {
        $redactor = new SensitiveDataRedactor();

        $data = [
            'meta' => [
                'answers' => [
                    ['question_id' => 'Q1', 'code' => 'A'],
                    ['question_id' => 'Q2', 'code' => 'B'],
                ],
            ],
        ];

        $result = $redactor->redact($data);

        $this->assertTrue((bool) ($result['meta']['answers']['__redacted__'] ?? false));
        $this->assertSame('psych_privacy', $result['meta']['answers']['reason'] ?? null);
        $this->assertGreaterThan(0, (int) ($result['meta']['answers']['count'] ?? 0));
    }

    public function test_redact_with_meta_reports_count_and_version_for_psych_keys(): void
    {
        $redactor = new SensitiveDataRedactor();

        $data = [
            'report_json' => 'very sensitive report content',
            'nested' => [
                'psychometrics' => [
                    'dimension' => 'EI',
                    'score' => 88,
                ],
            ],
        ];

        $result = $redactor->redactWithMeta($data);

        $this->assertSame('v2', $result['version']);
        $this->assertGreaterThan(0, (int) ($result['count'] ?? 0));
        $this->assertSame('[REDACTED_PSYCH]', $result['data']['report_json']);
        $this->assertTrue((bool) ($result['data']['nested']['psychometrics']['__redacted__'] ?? false));
    }
}
