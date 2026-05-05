<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use Tests\TestCase;

final class BigFiveResultPageV2RouteMatrixParserTest extends TestCase
{
    public function test_parser_loads_all_five_route_matrix_shards(): void
    {
        $result = app(BigFiveV2RouteMatrixParser::class)->parse();

        $this->assertTrue($result->isValid(), implode("\n", $result->errors));
        $this->assertSame(3125, $result->rowCount());
        $this->assertSame([
            'O1' => 625,
            'O2' => 625,
            'O3' => 625,
            'O4' => 625,
            'O5' => 625,
        ], $result->rowCountsByShard);
    }

    public function test_parser_validates_full_combination_key_coverage_and_o59_row(): void
    {
        $result = app(BigFiveV2RouteMatrixParser::class)->parse();
        $row = $result->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);

        $this->assertNotNull($row);
        $this->assertSame('O3_C2_E1_A3_N4', $row->combinationKey);
        $this->assertSame('sensitive_independent_thinker', $row->profileKey);
        $this->assertSame('sensitive_independent_thinker', $row->profileFamily);
        $this->assertSame('high_tension_or_mixed', $row->interpretationScope);
        $this->assertSame('敏锐的独立思考者', $row->data['nearest_canonical_profile_label_zh'] ?? null);
        $this->assertSame('conditional', $row->data['profile_label_public_allowed'] ?? null);
    }

    public function test_parser_validates_section_routes_staging_flags_and_no_body_copy(): void
    {
        $result = app(BigFiveV2RouteMatrixParser::class)->parse();

        foreach ($result->rowsByCombinationKey as $row) {
            $this->assertSame('staging_only', $row->data['runtime_use'] ?? null, $row->combinationKey);
            $this->assertFalse((bool) ($row->data['production_use_allowed'] ?? true), $row->combinationKey);
            $this->assertFalse((bool) ($row->data['ready_for_pilot'] ?? true), $row->combinationKey);
            $this->assertSame([
                'hero_summary',
                'domains_overview',
                'domain_deep_dive',
                'facet_details',
                'core_portrait',
                'norms_comparison',
                'action_plan',
                'methodology_and_access',
            ], array_keys((array) ($row->data['section_route'] ?? [])), $row->combinationKey);
            $this->assertStringNotContainsString('body_zh', json_encode($row->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function test_parser_fails_closed_for_missing_shards_and_invalid_rows(): void
    {
        $root = sys_get_temp_dir().'/big5-v2-route-matrix-test-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root.'/big5_3125_route_matrix_O1_v0_1_1.jsonl', json_encode([
            'combination_key' => 'O1_C1_E1_A1_N1',
            'section_route' => [],
            'runtime_use' => 'runtime',
            'production_use_allowed' => true,
            'ready_for_pilot' => true,
            'body_zh' => 'not allowed',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        try {
            $result = app(BigFiveV2RouteMatrixParser::class)->parse($root);
            $this->assertFalse($result->isValid());
            $errors = implode("\n", $result->errors);
            $this->assertStringContainsString('missing route matrix shard O2', $errors);
            $this->assertStringContainsString('route matrix shard O1 row_count must be 625', $errors);
            $this->assertStringContainsString('route matrix total unique row count must be 3125', $errors);
            $this->assertStringContainsString('O1_C1_E1_A1_N1.runtime_use must not be runtime or production', $errors);
            $this->assertStringContainsString('O1_C1_E1_A1_N1.production_use_allowed must not be true', $errors);
            $this->assertStringContainsString('O1_C1_E1_A1_N1 must not contain body_zh', $errors);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
