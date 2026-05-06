<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2PublicSurfacePolicyTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/public_surface_policy/v0_1';

    public function test_public_surface_policy_is_advisory_only_and_not_runtime(): void
    {
        $policy = $this->jsonFile('big5_public_surface_disabled_or_pending_policy_v0_1.json');
        $summary = $this->jsonFile('big5_public_surface_disabled_or_pending_summary_v0_1.json');

        foreach ([$policy, $summary] as $document) {
            $this->assertSame('public_surface_disabled_or_pending_policy_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertSame('no_go', $document['public_pilot_status'] ?? null);
            $this->assertSame('no_go', $document['production_status'] ?? null);
        }
    }

    public function test_secondary_surfaces_are_disabled_or_pending_and_cannot_count_as_pass(): void
    {
        $policy = $this->jsonFile('big5_public_surface_disabled_or_pending_policy_v0_1.json');
        $surfaces = $this->surfacesByKey($policy);

        $this->assertSame([
            'compare',
            'history',
            'pdf',
            'share_card',
        ], array_keys($surfaces));

        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('disabled_or_pending', $surface['policy_status'] ?? null, $surfaceKey);
            $this->assertSame('pending_surface', $surface['rendered_status'] ?? null, $surfaceKey);
            $this->assertSame('disabled', $surface['public_pilot_default'] ?? null, $surfaceKey);
            $this->assertFalse((bool) ($surface['can_count_as_pass'] ?? true), $surfaceKey);
            $this->assertSame([], $surface['evidence'] ?? null, $surfaceKey);
            $this->assertNotSame('', (string) ($surface['blocker'] ?? ''), $surfaceKey);
            $this->assertNotSame([], $surface['required_before_pass'] ?? [], $surfaceKey);
        }

        $this->assertSame([
            'pass' => 0,
            'disabled_or_pending' => 4,
            'pending_surface' => 4,
            'fail' => 0,
        ], $policy['status_counts'] ?? null);
    }

    public function test_policy_keeps_public_and_production_readiness_blocked(): void
    {
        $policy = $this->jsonFile('big5_public_surface_disabled_or_pending_policy_v0_1.json');
        $summary = $this->jsonFile('big5_public_surface_disabled_or_pending_summary_v0_1.json');

        $this->assertTrue((bool) ($policy['public_pilot_requires_explicit_result_page_only_scope'] ?? false));
        $this->assertTrue((bool) ($summary['public_pilot_requires_explicit_result_page_only_scope'] ?? false));
        $this->assertTrue((bool) ($summary['production_blocked'] ?? false));
        $this->assertTrue((bool) ($summary['no_runtime_change'] ?? false));
        $this->assertTrue((bool) ($summary['no_frontend_fallback'] ?? false));
        $this->assertTrue((bool) ($summary['no_body_generated'] ?? false));
        $this->assertSame(4, $summary['disabled_or_pending_surface_count'] ?? null);
        $this->assertSame(4, $summary['pending_surface_count'] ?? null);
        $this->assertSame(0, $summary['pass_surface_count'] ?? null);
    }

    public function test_forbidden_public_fields_are_listed_and_not_surface_evidence(): void
    {
        $policy = $this->jsonFile('big5_public_surface_disabled_or_pending_policy_v0_1.json');
        $forbidden = (array) ($policy['forbidden_public_fields'] ?? []);

        foreach ([
            'frontend_fallback',
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'production_use_allowed',
            'runtime_use',
            '[object Object]',
        ] as $term) {
            $this->assertContains($term, $forbidden);
        }

        foreach ($this->surfacesByKey($policy) as $surface) {
            $surfaceJson = json_encode($surface['evidence'] ?? [], JSON_THROW_ON_ERROR);
            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString((string) $term, $surfaceJson, (string) $term);
            }
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
     * @return array<string,array<string,mixed>>
     */
    private function surfacesByKey(array $policy): array
    {
        $surfaces = [];
        foreach ((array) ($policy['surfaces'] ?? []) as $surface) {
            $surfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($surfaces);

        return $surfaces;
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
