<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2RenderedQaTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/pilot_rendered_qa/v0_1';

    private const PILOT_PAYLOAD_PATH = 'tests/Fixtures/big5_result_page_v2/pilot_o59_staging_payload_v0_1.payload.json';

    public function test_pilot_rendered_qa_package_is_advisory_only_and_not_production(): void
    {
        $matrix = $this->jsonFile('big5_o59_pilot_rendered_qa_surface_matrix_v0_1.json');
        $report = $this->jsonFile('big5_o59_pilot_rendered_qa_report_v0_1.json');

        foreach ([$matrix, $report] as $document) {
            $this->assertSame('pilot_rendered_qa_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        }
    }

    public function test_pilot_surface_matrix_has_explicit_status_without_fake_passes(): void
    {
        $matrix = $this->jsonFile('big5_o59_pilot_rendered_qa_surface_matrix_v0_1.json');
        $surfaces = $this->surfacesByKey($matrix);

        $this->assertSame([
            'compare',
            'history',
            'pdf',
            'result_page_desktop',
            'result_page_mobile',
            'share_card',
        ], array_keys($surfaces));

        $this->assertSame('pass', data_get($surfaces, 'result_page_desktop.status'));
        $this->assertNotSame([], data_get($surfaces, 'result_page_desktop.evidence'));

        foreach (['result_page_mobile', 'pdf', 'share_card', 'history', 'compare'] as $pendingSurface) {
            $this->assertSame('pending_surface', data_get($surfaces, "{$pendingSurface}.status"), $pendingSurface);
            $this->assertSame([], data_get($surfaces, "{$pendingSurface}.evidence"), $pendingSurface);
            $this->assertNotSame('', (string) data_get($surfaces, "{$pendingSurface}.pending_reason"), $pendingSurface);
        }

        $this->assertSame([
            'pass' => 1,
            'pending_surface' => 5,
            'fail' => 0,
        ], $matrix['status_counts'] ?? null);
    }

    public function test_report_keeps_pending_surfaces_blocking_user_surface_release(): void
    {
        $report = $this->jsonFile('big5_o59_pilot_rendered_qa_report_v0_1.json');

        $this->assertSame(['result_page_desktop'], $report['passed_surfaces'] ?? null);
        $this->assertSame([], $report['failed_surfaces'] ?? null);
        $this->assertSame(['result_page_mobile', 'pdf', 'share_card', 'history', 'compare'], $report['pending_surfaces'] ?? null);
        $this->assertTrue((bool) data_get($report, 'pilot_readiness.pilot_runtime_flag_default_off'));
        $this->assertTrue((bool) data_get($report, 'pilot_readiness.pilot_runtime_available_in_allowed_non_production_environment'));
        $this->assertFalse((bool) data_get($report, 'pilot_readiness.all_required_surfaces_passed'));
        $this->assertFalse((bool) data_get($report, 'pilot_readiness.pilot_rendered_qa_complete'));
        $this->assertTrue((bool) data_get($report, 'pilot_readiness.pilot_user_surface_release_blocked'));
        $this->assertTrue((bool) data_get($report, 'pilot_readiness.production_blocked'));
    }

    public function test_public_pilot_payload_visible_fields_do_not_leak_banned_terms(): void
    {
        $matrix = $this->jsonFile('big5_o59_pilot_rendered_qa_surface_matrix_v0_1.json');
        $report = $this->jsonFile('big5_o59_pilot_rendered_qa_report_v0_1.json');
        $visibleText = $this->visibleText($this->pilotPayload());

        $this->assertSame('pass', data_get($report, 'public_payload_banned_scan.status'));
        foreach ((array) ($matrix['banned_rendered_terms_union'] ?? []) as $term) {
            $this->assertStringNotContainsString((string) $term, $visibleText, (string) $term);
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
    private function surfacesByKey(array $matrix): array
    {
        $surfaces = [];
        foreach ((array) ($matrix['surfaces'] ?? []) as $surface) {
            $surfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($surfaces);

        return $surfaces;
    }

    /**
     * @return array<string,mixed>
     */
    private function pilotPayload(): array
    {
        $envelope = $this->decodeJson(self::PILOT_PAYLOAD_PATH);
        $payload = $envelope['big5_result_page_v2'] ?? null;
        $this->assertIsArray($payload);

        return $payload;
    }

    private function visibleText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $text = '';
            foreach ($value as $key => $nested) {
                if (is_string($key) && ! $this->isVisibleField($key)) {
                    continue;
                }
                $text .= ' '.$this->visibleText($nested);
            }

            return $text;
        }

        return '';
    }

    private function isVisibleField(string $field): bool
    {
        return str_ends_with($field, '_zh')
            || in_array($field, ['title', 'subtitle', 'body', 'summary', 'items', 'bullets', 'rows'], true);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        return $this->decodeJson(self::BASE_PATH.'/'.$fileName);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
