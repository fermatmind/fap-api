<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerEntityContextArtifactReader;
use App\Domain\Career\Audit\CareerIndexStateContextArtifactReader;
use PHPUnit\Framework\TestCase;

final class CareerEntityIndexContextArtifactReaderTest extends TestCase
{
    public function test_entity_context_reader_parses_stable_artifact(): void
    {
        $artifact = CareerEntityContextArtifactReader::fromPath($this->writeJson('entity', [
            'schema_version' => 'career_entity_context.v1',
            'source' => ['type' => 'read_only_db_export', 'environment' => 'local'],
            'rows' => [
                [
                    'canonical_slug' => 'actuaries',
                    'occupation_exists' => true,
                    'occupation_id' => 123,
                    'title_en' => 'Actuaries',
                    'title_zh' => '精算师',
                    'family' => 'math',
                    'crosswalks' => [],
                    'missing_entity_fields' => [],
                    'evidence' => ['export_id' => 'test'],
                ],
            ],
        ]));

        $this->assertSame([], $artifact->byReason());
        $this->assertSame('career_entity_context.v1', $artifact->schemaVersion);
        $this->assertSame(['actuaries'], array_keys($artifact->rowsBySlug()));
        $this->assertSame([
            'source_path',
            'schema_version',
            'source',
            'by_reason',
            'rows',
            'issues',
        ], array_keys($artifact->toArray()));
    }

    public function test_entity_context_reader_reports_duplicate_slug(): void
    {
        $artifact = CareerEntityContextArtifactReader::fromPath($this->writeJson('entity-duplicate', [
            'rows' => [
                ['canonical_slug' => 'actuaries', 'occupation_exists' => true],
                ['canonical_slug' => 'actuaries', 'occupation_exists' => true],
            ],
        ]));

        $this->assertSame(['entity_context_slug_duplicate' => 1], $artifact->byReason());
    }

    public function test_index_context_reader_parses_stable_artifact(): void
    {
        $artifact = CareerIndexStateContextArtifactReader::fromPath($this->writeJson('index', [
            'schema_version' => 'career_index_state_context.v1',
            'source' => ['type' => 'read_only_db_export', 'environment' => 'local'],
            'rows' => [
                [
                    'canonical_slug' => 'actuaries',
                    'latest_index_state' => 'indexed',
                    'public_facing_state' => 'indexed',
                    'index_eligible' => true,
                    'changed_at' => '2026-05-13T00:00:00Z',
                    'reason_codes' => [],
                    'evidence' => ['export_id' => 'test'],
                ],
            ],
        ]));

        $this->assertSame([], $artifact->byReason());
        $this->assertSame('career_index_state_context.v1', $artifact->schemaVersion);
        $this->assertSame(['actuaries'], array_keys($artifact->rowsBySlug()));
        $this->assertSame([
            'source_path',
            'schema_version',
            'source',
            'by_reason',
            'rows',
            'issues',
        ], array_keys($artifact->toArray()));
    }

    public function test_index_context_reader_reports_duplicate_slug(): void
    {
        $artifact = CareerIndexStateContextArtifactReader::fromPath($this->writeJson('index-duplicate', [
            'rows' => [
                ['canonical_slug' => 'actuaries', 'latest_index_state' => 'indexed', 'index_eligible' => true],
                ['canonical_slug' => 'actuaries', 'latest_index_state' => 'indexed', 'index_eligible' => true],
            ],
        ]));

        $this->assertSame(['index_context_slug_duplicate' => 1], $artifact->byReason());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = sys_get_temp_dir().'/career-context-reader-'.$prefix.'-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }
}
