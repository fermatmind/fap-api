<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use JsonException;

final class CareerTenBlockBatchNormalizer
{
    public function __construct(
        private readonly CareerTenBlockVariantSchema $schema,
        private readonly CareerTenBlockVariantNormalizer $normalizer,
        private readonly CareerInternalLinkCanonicalizer $linkCanonicalizer,
    ) {}

    /** @return array{manifest:array<string,mixed>,receipt:array<string,mixed>} */
    public function normalize(string $sourceRoot, string $lookupPath): array
    {
        $root = realpath($sourceRoot);
        if ($root === false || ! is_dir($root) || is_link($sourceRoot)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SOURCE_MISSING');
        }
        $lookupDocument = $this->json($lookupPath, 'TEN_BLOCK_LOOKUP_INVALID');
        $lookup = $lookupDocument['by_slug'] ?? null;
        if (! is_array($lookup) || array_is_list($lookup)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_LOOKUP_INVALID');
        }
        $slugs = array_values(array_filter(scandir($root) ?: [], static fn (string $name): bool => $name !== ''
            && ! str_starts_with($name, '.') && ! str_starts_with($name, '_') && is_dir($root.'/'.$name)));
        sort($slugs, SORT_STRING);
        $inventory = array_fill_keys($slugs, true);
        $profiles = [];
        $profileCounts = [];
        $discriminatorCounts = [];
        $inputLinks = 0;
        $rewrites = 0;
        $coverageCounts = ['mapped' => 0, 'intentional_internal_metadata' => 0, 'omitted_with_reason' => 0, 'blocked' => 0];
        foreach ($slugs as $slug) {
            [$blocks, $inputDigest] = $this->readBlocks($root.'/'.$slug);
            if (($blocks['identity.json']['slug'] ?? null) !== $slug || ! isset($lookup[$slug])) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_LOOKUP_MISMATCH');
            }
            $profile = $this->schema->detectAndValidate($blocks);
            $profileCounts[$profile] = ($profileCounts[$profile] ?? 0) + 1;
            $links = $this->linkCanonicalizer->canonicalize(
                $slug,
                $blocks['compare-links.json']['internal_links'],
                $lookup,
                $inventory,
            );
            $inputLinks += count($links);
            $rewrites += count(array_filter($links, static fn (array $link): bool => $link['rewrite_applied']));
            $ir = $this->normalizer->normalize($blocks, $profile, $links);
            foreach ($ir['field_coverage'] as $coverage) {
                $coverageCounts[$coverage['disposition']]++;
            }
            $discriminators = $this->discriminators($blocks);
            foreach ($discriminators as $key => $value) {
                $label = $key.'='.$value;
                $discriminatorCounts[$label] = ($discriminatorCounts[$label] ?? 0) + 1;
            }
            $profiles[$slug] = [
                'input_profile' => $profile,
                'input_digest' => $inputDigest,
                'ir_digest' => CareerCurrentAuthorityPackage::hashValue($ir),
                'discriminators' => $discriminators,
                'input_link_count' => count($links),
                'variant_rewrite_count' => count(array_filter($links, static fn (array $link): bool => $link['rewrite_applied'])),
                'output_variant_link_count' => 0,
                'field_coverage_count' => count($ir['field_coverage']),
            ];
        }
        ksort($profileCounts, SORT_STRING);
        ksort($discriminatorCounts, SORT_STRING);
        ksort($profiles, SORT_STRING);
        $manifest = [
            'contract_version' => 'career.ten_block.schema_profile_manifest.v1',
            'schema_version' => CareerTenBlockVariantSchema::VERSION,
            'source_root_digest' => CareerCurrentAuthorityPackage::hashValue(array_map(
                static fn (array $profile): string => $profile['input_digest'],
                $profiles,
            )),
            'slug_count' => count($profiles),
            'profiles' => $profiles,
        ];

        return [
            'manifest' => $manifest,
            'receipt' => [
                'contract_version' => 'career.ten_block.batch_normalize_receipt.v1',
                'schema_version' => CareerTenBlockVariantSchema::VERSION,
                'slug_count' => count($profiles),
                'profile_counts' => $profileCounts,
                'discriminator_counts' => $discriminatorCounts,
                'unknown_profile_count' => 0,
                'ambiguous_profile_count' => 0,
                'input_link_count' => $inputLinks,
                'unresolved_link_count' => 0,
                'variant_rewrite_count' => $rewrites,
                'output_variant_link_count' => 0,
                'field_coverage_counts' => $coverageCounts,
                'silent_drop_count' => 0,
                'manifest_digest' => CareerCurrentAuthorityPackage::hashValue($manifest),
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'generated_at' => null,
            ],
        ];
    }

    /** @return array{0:array<string,array<string,mixed>>,1:string} */
    private function readBlocks(string $slugRoot): array
    {
        $actual = array_values(array_filter(scandir($slugRoot) ?: [], static fn (string $file): bool => ! str_starts_with($file, '.')
            && str_ends_with($file, '.json') && $file !== 'content.json'));
        sort($actual, SORT_STRING);
        if ($actual !== CareerTenBlockInputSchema::FILES) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_SET_MISMATCH');
        }
        $blocks = [];
        $context = hash_init('sha256');
        foreach (CareerTenBlockInputSchema::FILES as $file) {
            $path = $slugRoot.'/'.$file;
            if (! is_file($path) || is_link($path)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_SET_MISMATCH');
            }
            $raw = file_get_contents($path);
            if (! is_string($raw)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_UNREADABLE');
            }
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INVALID_JSON');
            }
            if (! is_array($data) || array_is_list($data)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
            }
            $blocks[$file] = $data;
            hash_update($context, $file."\0".$raw."\0");
        }

        return [$blocks, hash_final($context)];
    }

    /** @return array<string,string> */
    private function discriminators(array $blocks): array
    {
        $comparison = array_keys($blocks['compare-links.json']['compare_rows'][0]);
        $definition = $blocks['definition.json']['onet_struct'];

        return [
            'comparison_columns' => implode('|', $comparison),
            'ai_persona_columns' => implode('|', array_keys($blocks['ai-impact.json']['ai_s5_persona'][0])),
            'ai_tool_columns' => implode('|', array_keys($blocks['ai-impact.json']['ai_s6_tools'][0])),
            'risk_path_columns' => implode('|', array_keys($blocks['risk.json']['risk_path_table'][0])),
            'industry_table_columns' => implode('|', array_keys($blocks['salary.json']['china_industry_table'][0])),
            'onet_value2' => count(array_filter($definition, static fn (array $row): bool => array_key_exists('value2', $row))) > 0 ? 'present' : 'absent',
            'salary_min_max' => $blocks['page-meta.json']['oc_salary_min'] === null || $blocks['page-meta.json']['oc_salary_max'] === null
                ? 'nullable' : 'both_present',
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $path, string $safeCode): array
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
