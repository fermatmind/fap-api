<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\Bundles\CareerJobPublicAllowlist;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerPublicAllowlistContractTest extends TestCase
{
    public function testSanitizeProvenanceMetaRedactsAllInternalIdentifiers(): void
    {
        $meta = [
            'content_version' => 'v4.2',
            'data_version' => '2026-q1',
            'logic_version' => 'career.protocol.v1',
            'compiler_version' => '3.1.0',
            'compiled_at' => '2026-01-01T00:00:00Z',
            'truth_metric_id' => 'uuid-1',
            'trust_manifest_id' => 'uuid-2',
            'index_state_id' => 'uuid-3',
            'compile_run_id' => 'run-4',
            'import_run_id' => 'import-5',
            'source_trace_id' => 'trace-6',
        ];

        $sanitized = CareerJobPublicAllowlist::sanitizeProvenanceMeta($meta);

        // Safe keys preserved
        $this->assertArrayHasKey('content_version', $sanitized);
        $this->assertArrayHasKey('data_version', $sanitized);
        $this->assertArrayHasKey('logic_version', $sanitized);
        $this->assertArrayHasKey('compiler_version', $sanitized);
        $this->assertArrayHasKey('compiled_at', $sanitized);

        // Internal ID keys redacted
        $this->assertArrayNotHasKey('truth_metric_id', $sanitized);
        $this->assertArrayNotHasKey('trust_manifest_id', $sanitized);
        $this->assertArrayNotHasKey('index_state_id', $sanitized);
        $this->assertArrayNotHasKey('compile_run_id', $sanitized);
        $this->assertArrayNotHasKey('import_run_id', $sanitized);
        $this->assertArrayNotHasKey('source_trace_id', $sanitized);
    }

    public function testFilterTitlesForEnLocaleRemovesZhFields(): void
    {
        $titles = [
            'canonical_en' => 'Software Developer',
            'canonical_zh' => '软件开发人员',
            'search_h1_zh' => '软件开发人员工作内容',
            'short_title_en' => 'Developer',
            'short_title_zh' => '开发',
        ];

        $filtered = CareerJobPublicAllowlist::filterTitlesForLocale($titles, 'en');

        $this->assertArrayHasKey('canonical_en', $filtered);
        $this->assertArrayHasKey('short_title_en', $filtered);
        $this->assertArrayNotHasKey('canonical_zh', $filtered);
        $this->assertArrayNotHasKey('search_h1_zh', $filtered);
        $this->assertArrayNotHasKey('short_title_zh', $filtered);
    }

    public function testFilterTitlesForZhLocaleKeepsValidZhFields(): void
    {
        $titles = [
            'canonical_en' => 'Software Developer',
            'canonical_zh' => '软件开发人员',
            'search_h1_zh' => '软件开发人员工作内容',
            'short_title_zh' => '开发',
        ];

        $filtered = CareerJobPublicAllowlist::filterTitlesForLocale($titles, 'zh-CN');

        $this->assertArrayHasKey('canonical_zh', $filtered);
        $this->assertArrayHasKey('search_h1_zh', $filtered);
        $this->assertArrayHasKey('short_title_zh', $filtered);
    }

    public function testFilterTitlesForZhLocaleDropsEmptyOrNonCjkZhFields(): void
    {
        $titles = [
            'canonical_en' => 'Actuaries',
            'canonical_zh' => '',
            'search_h1_zh' => 'Hello world',
            'short_title_zh' => null,
        ];

        $filtered = CareerJobPublicAllowlist::filterTitlesForLocale($titles, 'zh-CN');

        $this->assertArrayNotHasKey('canonical_zh', $filtered);
        $this->assertArrayNotHasKey('search_h1_zh', $filtered);
        $this->assertArrayNotHasKey('short_title_zh', $filtered);
    }

    public function testSanitizeCompileRefsRedactsInternalKeys(): void
    {
        $refs = [
            [
                'source_system' => 'BLS',
                'source_code' => '15-1252',
                'cms_job_id' => 12345,
                'source_trace_id' => 'trace-1',
                'display_asset_id' => 'asset-1',
                'source_docx' => '/internal/docs/file.docx',
                'runtime_publish_projection' => 'active',
            ],
        ];

        $sanitized = CareerJobPublicAllowlist::sanitizeCompileRefs($refs);

        $this->assertArrayHasKey('source_system', $sanitized[0]);
        $this->assertArrayHasKey('source_code', $sanitized[0]);
        $this->assertArrayNotHasKey('cms_job_id', $sanitized[0]);
        $this->assertArrayNotHasKey('source_trace_id', $sanitized[0]);
        $this->assertArrayNotHasKey('display_asset_id', $sanitized[0]);
        $this->assertArrayNotHasKey('source_docx', $sanitized[0]);
        $this->assertArrayNotHasKey('runtime_publish_projection', $sanitized[0]);
    }

    public function testIsValidZhContentRejectsEmptyAndNonCjk(): void
    {
        $this->assertFalse(CareerJobPublicAllowlist::isValidZhContent(null));
        $this->assertFalse(CareerJobPublicAllowlist::isValidZhContent(''));
        $this->assertFalse(CareerJobPublicAllowlist::isValidZhContent('   '));
        $this->assertFalse(CareerJobPublicAllowlist::isValidZhContent('Hello'));
        $this->assertFalse(CareerJobPublicAllowlist::isValidZhContent('123'));
        $this->assertTrue(CareerJobPublicAllowlist::isValidZhContent('软件开发'));
        $this->assertTrue(CareerJobPublicAllowlist::isValidZhContent('软件 Engineer 2024'));
    }
}
