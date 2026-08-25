<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CareerShardedCurrentAssemblerTest extends TestCase
{
    private string $repoRoot;

    private string $assetsPath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 6);
        require_once $this->repoRoot.'/.agents/skills/fap-api-career-canonical-builder/scripts/assemble_sharded_current.php';
        $this->assetsPath = $this->repoRoot.'/backend/content_assets/career/current/assets.jsonl';
        $this->manifestPath = $this->repoRoot.'/backend/content_assets/career/current/manifest.json';
    }

    public function test_it_assembles_all_candidate_shards_deterministically_and_proves_every_projection_equivalent(): void
    {
        ini_set('memory_limit', '1536M');
        $candidateRoot = $this->temporaryDirectory('career-assembler-candidate-');
        $outputRoot = $this->temporaryDirectory('career-assembler-output-');
        $statusBefore = $this->repositoryStatus();
        try {
            $split = (new \CareerLegacyCurrentSharder)->split(
                $this->repoRoot,
                $this->assetsPath,
                $this->manifestPath,
                $candidateRoot,
            );
            $assembler = new \CareerShardedCurrentAssembler;
            $first = $assembler->assemble($this->repoRoot, $candidateRoot, $this->assetsPath, $outputRoot);
            $firstDigests = $this->fileDigests($outputRoot);
            $second = $assembler->assemble($this->repoRoot, $candidateRoot, $this->assetsPath, $outputRoot);
            $secondDigests = $this->fileDigests($outputRoot);

            self::assertSame($first, $second);
            self::assertSame($firstDigests, $secondDigests);
            self::assertCount(4, $secondDigests);
            self::assertSame($split['manifest']['aggregate_sha256'], $second['assembly_manifest']['candidate_aggregate_sha256']);
            self::assertSame(hash_file('sha256', $this->assetsPath), $second['assembly_manifest']['assets']['sha256']);
            self::assertSame(1046, $second['equivalence_report']['assembled_rows']);
            self::assertSame(2092, $second['equivalence_report']['locale_pages']);
            self::assertSame(2092, $second['equivalence_report']['public_projection_hash_identical']);
            self::assertSame(2092, $second['equivalence_report']['seo_hash_identical']);
            self::assertSame(2092, $second['equivalence_report']['faq_and_schema_hash_identical']);
            self::assertSame(2092, $second['equivalence_report']['sources_and_claim_bindings_hash_identical']);
            self::assertSame(2092, $second['equivalence_report']['cta_and_internal_links_identical']);
            self::assertSame(2092, $second['equivalence_report']['component_order_and_payload_identical']);
            self::assertSame(1046, $second['equivalence_report']['zh_presentation_v1_projection_identical']);
            self::assertSame(array_fill_keys([
                'breadcrumb_list', 'claim_bindings', 'cta', 'faq_page', 'internal_links', 'occupation', 'source_card',
            ], 2092), $second['equivalence_report']['derived_dependency_validation']);
            self::assertTrue($second['equivalence_report']['row_bytes_identical']);
            self::assertSame(0, $second['repository_zero_write_receipt']['repository_writes']);

            $firstShard = $candidateRoot.'/'.$split['manifest']['shards'][0]['path'];
            $originalShard = (string) file_get_contents($firstShard);
            file_put_contents($firstShard, '['.substr($originalShard, 1));
            $this->assertFailure(
                'SHARD_HASH_MISMATCH',
                fn () => $assembler->assemble($this->repoRoot, $candidateRoot, $this->assetsPath, $outputRoot),
            );
            file_put_contents($firstShard, $originalShard);

            $manifestPath = $candidateRoot.'/manifest.json';
            $originalManifest = (string) file_get_contents($manifestPath);
            $manifest = json_decode($originalManifest, true, 512, JSON_THROW_ON_ERROR);
            $manifest['aggregate_sha256'] = str_repeat('0', 64);
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->assertFailure(
                'AGGREGATE_HASH_MISMATCH',
                fn () => $assembler->assemble($this->repoRoot, $candidateRoot, $this->assetsPath, $outputRoot),
            );
            file_put_contents($manifestPath, $originalManifest);

            $ownershipPath = $candidateRoot.'/field-ownership-report.json';
            $originalOwnership = (string) file_get_contents($ownershipPath);
            $ownership = json_decode($originalOwnership, true, 512, JSON_THROW_ON_ERROR);
            $ownership['unowned_fields'] = 1;
            file_put_contents($ownershipPath, json_encode($ownership, JSON_THROW_ON_ERROR));
            $this->assertFailure(
                'OWNERSHIP_REPORT_INVALID',
                fn () => $assembler->assemble($this->repoRoot, $candidateRoot, $this->assetsPath, $outputRoot),
            );
            file_put_contents($ownershipPath, $originalOwnership);
        } finally {
            $this->deleteTemporaryDirectory($candidateRoot);
            $this->deleteTemporaryDirectory($outputRoot);
        }
        self::assertSame($statusBefore, $this->repositoryStatus());
    }

    public function test_it_rejects_missing_unknown_duplicate_and_conflicting_module_facts_without_legacy_fallback(): void
    {
        $line = rtrim((string) fgets(fopen($this->assetsPath, 'rb')), "\r\n");
        $legacy = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        [$records] = (new \CareerLegacyCurrentSharder)->splitRow($legacy);
        $assembler = new \CareerShardedCurrentAssembler;

        self::assertSame($line, \CareerLegacyCurrentSharder::canonicalJson($assembler->assembleRecords($records)));

        $missingLocale = $records;
        unset($missingLocale['zh-CN']);
        $this->assertFailure('LOCALE_PAIR_INVALID', fn () => $assembler->assembleRecords($missingLocale));

        $missingModule = $records;
        unset($missingModule['en']['risk']);
        $this->assertFailure('MODULE_COMPLETENESS_INVALID', fn () => $assembler->assembleRecords($missingModule));

        $unknownField = $records;
        $unknownField['en']['salary']['content']['unknown'] = true;
        $this->assertFailure('MODULE_CONTENT_FIELD_SET_INVALID', fn () => $assembler->assembleRecords($unknownField));

        $missingComponent = $records;
        unset($missingComponent['en']['faq']['content']['page']['faq_block']);
        $this->assertFailure('MODULE_COMPONENT_SET_INVALID', fn () => $assembler->assembleRecords($missingComponent));

        $duplicateFact = $records;
        $bindingModule = $this->firstBindingModule($duplicateFact, 'source_bindings');
        $duplicateFact['en'][$bindingModule]['source_bindings'][] = $duplicateFact['en'][$bindingModule]['source_bindings'][0];
        $this->assertFailure('DUPLICATE_BINDING_FACT', fn () => $assembler->assembleRecords($duplicateFact));

        $claimConflict = $records;
        $claimConflict['en']['definition']['claim_bindings'][0]['input_jsonpaths'][0] = '$.salary.conflict';
        $this->assertFailure('CLAIM_MODULE_CONFLICT', fn () => $assembler->assembleRecords($claimConflict));

        $faqConflict = $records;
        $faqConflict['zh-CN']['faq']['content']['structured_faq_page']['mainEntity'][0]['acceptedAnswer']['text'] .= ' conflict';
        $this->assertFailure('FAQ_DERIVATION_CONFLICT', fn () => $assembler->assembleRecords($faqConflict));

        $rowFallback = $records;
        $rowFallback['en']['page-meta']['content']['row']['unmapped_legacy_fallback'] = true;
        $this->assertFailure('ROW_CONTRACT_INVALID', fn () => $assembler->assembleRecords($rowFallback));
    }

    /** @param array<string,array<string,array<string,mixed>>> $records */
    private function firstBindingModule(array $records, string $key): string
    {
        foreach (\CareerLegacyCurrentSharder::MODULES as $module) {
            if ($records['en'][$module][$key] !== []) {
                return $module;
            }
        }

        self::fail('Expected at least one '.$key);
    }

    /** @return array<string,string> */
    private function fileDigests(string $root): array
    {
        $digests = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $digests[substr($entry->getPathname(), strlen($root) + 1)] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($digests, SORT_STRING);

        return $digests;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        unlink($path);
        self::assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function deleteTemporaryDirectory(string $root): void
    {
        $real = realpath($root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (! is_string($real) || ! is_string($temporaryRoot) || ! str_starts_with($real, $temporaryRoot.'/')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($real);
    }

    private function repositoryStatus(): string
    {
        $lines = [];
        $exitCode = 0;
        exec(sprintf('git -C %s status --porcelain=v1 --untracked-files=all', escapeshellarg($this->repoRoot)), $lines, $exitCode);
        self::assertSame(0, $exitCode);

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private function assertFailure(string $safeCode, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected '.$safeCode);
        } catch (RuntimeException $failure) {
            self::assertSame($safeCode, $failure->getMessage());
        }
    }
}
