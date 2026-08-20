<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use JsonException;

final class CareerTenBlockCompiler
{
    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate', 'release_gates', 'qa_risk', 'admin_review_state', 'tracking_json',
        'raw_ai_exposure_score', 'compile_run_id', 'import_run_id', 'source_trace_id',
        'provenance_meta', 'lineage_id', 'lineage_json', 'private_answers', 'score_vector',
        'percentile', 'attempt_url', 'report_url', 'user_id', 'order_id', 'payment_id',
    ];

    public function __construct(
        private readonly CareerTenBlockSchemaDetector $detector,
        private readonly CareerTenBlockNormalizer $normalizer,
    ) {}

    /** @return array{ir:array<string,mixed>,row:?array<string,mixed>,receipt:array<string,mixed>} */
    public function compile(
        string $sourceRoot,
        string $slug,
        string $lookupPath,
        string $baselineAssetsPath,
        ?string $evidencePath = null,
    ): array {
        $detected = $this->detector->detect($sourceRoot, $slug);
        $blocks = $detected['blocks'];
        $identity = $blocks['identity.json'];
        if (($identity['slug'] ?? null) !== $slug) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SLUG_MISMATCH');
        }
        [$lookup, $lookupDigest] = $this->lookup($lookupPath, $slug);
        foreach (['canonical_slug' => 'slug', 'soc_code' => 'soc', 'onet_code' => 'onet', 'ai_score' => 'ai_score'] as $lookupKey => $inputKey) {
            if (($lookup[$lookupKey] ?? null) !== ($identity[$inputKey] ?? null)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_LOOKUP_MISMATCH');
            }
        }
        [$baseline, $baselineDigest] = $this->baseline($baselineAssetsPath, $slug);
        [$evidence, $evidenceDigest, $blockers] = $this->evidence($evidencePath, $slug);
        $ir = $this->normalizer->normalize($blocks, $detected['profile']);
        $row = $blockers === [] ? $this->candidateRow($baseline, $blocks, $evidence) : null;
        $rowDigest = $row === null ? null : CareerCurrentAuthorityPackage::hashValue($row);

        return [
            'ir' => $ir,
            'row' => $row,
            'receipt' => [
                'contract_version' => 'career.ten_block.compile_receipt.v1',
                'compiler_version' => 'career.ten_block.compiler.v1',
                'schema_version' => CareerTenBlockInputSchema::VERSION,
                'input_digest' => $detected['input_digest'],
                'lookup_digest' => $lookupDigest,
                'evidence_digest' => $evidenceDigest,
                'current_baseline_digest' => $baselineDigest,
                'slug' => $slug,
                'profile' => $detected['profile'],
                'locale_count' => 2,
                'component_count' => count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                'mapped_file_count' => count($blocks),
                'orphan_fields' => [],
                'omitted_fields' => [],
                'blocked_fields' => $blockers,
                'claim_blockers' => $blockers,
                'source_blockers' => $blockers,
                'review_blockers' => $blockers,
                'internal_link_canonicalization' => ['input' => count($blocks['compare-links.json']['internal_links']), 'rewritten' => 0],
                'output_row_digest' => $rowDigest,
                'publication_eligible' => $row !== null,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'generated_at' => null,
            ],
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function lookup(string $path, string $slug): array
    {
        $value = $this->readJsonObject($path, 'TEN_BLOCK_LOOKUP_INVALID');
        $row = $value['by_slug'][$slug] ?? null;
        if (! is_array($row)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_LOOKUP_MISMATCH');
        }

        return [$row, hash_file('sha256', $path) ?: throw new CareerTenBlockCompileFailure('TEN_BLOCK_LOOKUP_INVALID')];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function baseline(string $path, string $slug): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_BASELINE_INVALID');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_BASELINE_INVALID');
        }
        $matched = null;
        while (($line = fgets($handle)) !== false) {
            try {
                $row = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                fclose($handle);
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_BASELINE_INVALID');
            }
            if (is_array($row) && ($row['canonical_slug'] ?? null) === $slug) {
                if ($matched !== null) {
                    fclose($handle);
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_CROSSWALK_MISMATCH');
                }
                $matched = $row;
            }
        }
        fclose($handle);
        if (! is_array($matched)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_CROSSWALK_MISMATCH');
        }

        return [$matched, hash_file('sha256', $path) ?: throw new CareerTenBlockCompileFailure('TEN_BLOCK_BASELINE_INVALID')];
    }

    /** @return array{0:array<string,mixed>,1:?string,2:list<array{code:string,field:string}>} */
    private function evidence(?string $path, string $slug): array
    {
        if ($path === null || $path === '') {
            return [[], null, [['code' => 'TEN_BLOCK_EVIDENCE_MISSING', 'field' => 'claim_bindings']]];
        }
        $evidence = $this->readJsonObject($path, 'TEN_BLOCK_EVIDENCE_INVALID');
        if (($evidence['contract_version'] ?? null) !== 'career.claim_evidence.fixture.v1'
            || ($evidence['canonical_slug'] ?? null) !== $slug
            || ! is_array($evidence['sources'] ?? null)
            || ! is_array($evidence['claims'] ?? null)
            || $evidence['sources'] === []
            || $evidence['claims'] === []
            || ! is_string($evidence['reviewed_at'] ?? null)
            || ! is_string($evidence['expires_at'] ?? null)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        foreach ($evidence['sources'] as $source) {
            if (! is_array($source)
                || ! is_string($source['source_key'] ?? null)
                || ! is_string($source['url'] ?? null)
                || preg_match('/\Ahttps:\/\//', $source['url']) !== 1
                || ! is_string($source['authority'] ?? null)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
            }
        }
        foreach ($evidence['claims'] as $claim) {
            if (! is_array($claim)
                || ! is_string($claim['claim_key'] ?? null)
                || ! is_string($claim['source_key'] ?? null)
                || ! is_string($claim['confidence'] ?? null)
                || ! is_string($claim['captured_at'] ?? null)
                || ! is_string($claim['expires_at'] ?? null)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
            }
        }

        return [$evidence, hash_file('sha256', $path) ?: throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID'), []];
    }

    /** @param array<string,mixed> $baseline @param array<string,array<string,mixed>> $blocks @param array<string,mixed> $evidence @return array<string,mixed> */
    private function candidateRow(array $baseline, array $blocks, array $evidence): array
    {
        $identity = $blocks['identity.json'];
        $definition = $blocks['definition.json'];
        $faqItems = array_map(
            static fn (array $item): array => ['answer' => $item['a'], 'question' => $item['q']],
            $blocks['faq.json']['faq'],
        );
        $zh = $baseline['page_payload_json']['page']['zh'];
        $zh['hero']['h1'] = $identity['title_zh'];
        $zh['hero']['title'] = $identity['title_zh'];
        $zh['hero']['quick_answer'] = $blocks['page-meta.json']['hero_lead'];
        $zh['definition_block'] = $definition['definition'];
        $zh['responsibilities_block'] = $definition['duties'];
        $zh['work_context_block'] = $definition['work_scene'];
        $zh['faq_block'] = ['items' => $faqItems];
        $baseline['page_payload_json']['page']['zh'] = $zh;
        $baseline['seo_payload_json']['zh']['h1'] = $identity['title_zh'];
        $baseline['seo_payload_json']['zh']['title'] = $blocks['page-meta.json']['meta_title'];
        $baseline['seo_payload_json']['zh']['description'] = $blocks['page-meta.json']['meta_description'];
        $baseline['structured_data_json']['faq_page']['zh'] = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                'name' => $item['q'],
            ], $blocks['faq.json']['faq']),
        ];
        $baseline['sources_json']['references'] = array_map(static fn (array $source): array => [
            'label' => $source['source_key'],
            'source_type' => $source['authority'],
            'url' => $source['url'],
            'usage' => $source['usage'] ?? null,
        ], $evidence['sources']);
        foreach (['en', 'zh'] as $locale) {
            $baseline['page_payload_json']['page'][$locale]['review_validity_card'] = [
                'last_reviewed' => $evidence['reviewed_at'],
                'next_review_due' => $evidence['expires_at'],
            ];
        }
        $this->assertNoForbiddenKeys($baseline['page_payload_json']);
        $this->assertNoForbiddenKeys($baseline['sources_json']);
        $this->assertNoForbiddenKeys($baseline['structured_data_json']);

        return $baseline;
    }

    private function assertNoForbiddenKeys(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach (self::FORBIDDEN_PUBLIC_KEYS as $key) {
            if (array_key_exists($key, $value)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_FORBIDDEN_PUBLIC_FIELD');
            }
        }
        foreach ($value as $child) {
            $this->assertNoForbiddenKeys($child);
        }
    }

    /** @return array<string,mixed> */
    private function readJsonObject(string $path, string $safeCode): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure($safeCode);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerTenBlockCompileFailure($safeCode);
        }

        return $value;
    }
}
