<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2AllowlistScopeApprovalTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/allowlist_scope_approval/v0_1';

    public function test_allowlist_scope_shape_is_approved_without_live_values(): void
    {
        $report = $this->jsonFile('big5_allowlist_scope_approval_v0_1.json');
        $summary = $this->jsonFile('big5_allowlist_scope_approval_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('allowlist_scope_approval', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['scope_shape_approved'] ?? false));
            $this->assertFalse((bool) ($document['live_allowlist_values_approved'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_activation'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_scope_dimensions_are_explicit_and_required_values_are_safe_enums(): void
    {
        $report = $this->jsonFile('big5_allowlist_scope_approval_v0_1.json');
        $dimensions = [];
        foreach ((array) ($report['allowlist_dimensions'] ?? []) as $row) {
            $dimensions[(string) ($row['dimension'] ?? '')] = $row;
            $this->assertFalse((bool) ($row['live_value_committed'] ?? true), (string) ($row['dimension'] ?? ''));
        }
        ksort($dimensions);

        $this->assertSame([
            'anonymous_session',
            'attempt',
            'form',
            'locale',
            'organization',
            'scale',
            'tenant',
            'user',
        ], array_keys($dimensions));

        $this->assertSame(['big5_90', 'big5_120'], $dimensions['form']['allowed_values'] ?? null);
        $this->assertSame(['zh', 'zh-CN'], $dimensions['locale']['allowed_values'] ?? null);
        $this->assertSame(['BIG5_OCEAN'], $dimensions['scale']['allowed_values'] ?? null);
    }

    public function test_activation_requires_separate_live_value_authorization_and_live_smoke(): void
    {
        $report = $this->jsonFile('big5_allowlist_scope_approval_v0_1.json');

        $this->assertTrue((bool) data_get($report, 'activation_preconditions.operator_must_authorize_live_values'));
        $this->assertTrue((bool) data_get($report, 'activation_preconditions.post_activation_live_smoke_required_after_values'));
        $this->assertTrue((bool) data_get($report, 'activation_preconditions.public_percentage_bucket_must_remain_zero'));
        $this->assertTrue((bool) data_get($report, 'activation_preconditions.production_percentage_must_remain_disabled'));

        foreach (['live_allowlist_values', 'runtime_flag_change', 'post_activation_live_smoke'] as $deferred) {
            $this->assertContains($deferred, $report['deferred_until_separate_authorization'] ?? []);
        }
    }

    public function test_artifacts_are_redacted_and_do_not_store_private_or_internal_terms(): void
    {
        $serialized = json_encode([
            $this->jsonFile('big5_allowlist_scope_approval_v0_1.json'),
            $this->jsonFile('big5_allowlist_scope_approval_summary_v0_1.json'),
            (string) file_get_contents(base_path(self::BASE_PATH.'/README.md')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([
            'attempt_id',
            'user_id',
            'anon_id',
            'private_url',
            'report_json',
            'report_full_json',
            'report_free_json',
            'Big Five Report Engine',
            'PR3B',
            'AttemptReadController',
            'payload',
            'registry',
            'raw_score',
            'raw_scores',
            'score_vector',
            'percentile',
            'percentiles',
            'internal_metadata',
            '[object Object]',
        ] as $forbiddenTerm) {
            $this->assertStringNotContainsString($forbiddenTerm, $serialized, $forbiddenTerm);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
