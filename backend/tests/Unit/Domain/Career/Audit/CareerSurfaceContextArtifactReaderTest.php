<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerSurfaceContextArtifactReader;
use PHPUnit\Framework\TestCase;

final class CareerSurfaceContextArtifactReaderTest extends TestCase
{
    public function test_surface_context_reader_parses_stable_artifact(): void
    {
        $artifact = CareerSurfaceContextArtifactReader::fromPath($this->writeJson('surface', [
            'schema_version' => 'career_surface_context.v1',
            'source' => ['type' => 'read_only_surface_export', 'environment' => 'local'],
            'rows' => [
                [
                    'canonical_slug' => 'actuaries',
                    'locale' => 'en',
                    'api_canonical_path' => '/en/career/jobs/actuaries',
                    'api_indexable' => true,
                    'evidence' => ['export_id' => 'test'],
                ],
            ],
        ]));

        $this->assertSame([], $artifact->byReason());
        $this->assertSame('career_surface_context.v1', $artifact->schemaVersion);
        $this->assertSame(['actuaries|en'], array_keys($artifact->rowsByKey()));
        $this->assertSame([
            'source_path',
            'schema_version',
            'source',
            'by_reason',
            'rows',
            'issues',
        ], array_keys($artifact->toArray()));
    }

    public function test_surface_context_reader_reports_duplicate_slug_locale(): void
    {
        $artifact = CareerSurfaceContextArtifactReader::fromPath($this->writeJson('surface-duplicate', [
            'rows' => [
                ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries', 'api_indexable' => true],
                ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries', 'api_indexable' => true],
            ],
        ]));

        $this->assertSame(['surface_context_slug_locale_duplicate' => 1], $artifact->byReason());
    }

    public function test_surface_context_reader_reports_missing_required_api_indexable(): void
    {
        $artifact = CareerSurfaceContextArtifactReader::fromPath($this->writeJson('surface-missing-field', [
            'rows' => [
                ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries'],
            ],
        ]));

        $this->assertSame(['surface_context_required_field_missing' => 1], $artifact->byReason());
        $this->assertSame([], $artifact->rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = sys_get_temp_dir().'/career-surface-context-reader-'.$prefix.'-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }
}
