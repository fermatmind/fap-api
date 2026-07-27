<?php

declare(strict_types=1);

namespace App\Services\Cms;

use RuntimeException;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiIndex52ProjectionRepairPackage
{
    public const PACKAGE_PATH = 'content_assets/personality_public/mbti-index52-comparison-projection-repair-2026-07-27.json';

    public const AUTHORIZATION_PATH = 'content_assets/personality_public/mbti-index52-comparison-projection-repair-operator-authorization-2026-07-27.json';

    public const AT_SOURCE_PRESTATE_PATH = 'content_assets/personality_public/mbti-index52-at-source-prestate-2026-07-27.json';

    public const PACKAGE_SHA256 = '09ccf33ba462b53da57087667e948069f8b22d7a4f48fa4134a357d71716d95f';

    public const AUTHORIZATION_SHA256 = 'e3d256d930135bd228055b40a4bf9c6441a35e3e89252f08028065e490e8b402';

    public const AT_SLUGS = [
        'intj-a-vs-intj-t', 'intp-a-vs-intp-t', 'entj-a-vs-entj-t', 'entp-a-vs-entp-t',
        'infj-a-vs-infj-t', 'infp-a-vs-infp-t', 'enfj-a-vs-enfj-t', 'enfp-a-vs-enfp-t',
        'istj-a-vs-istj-t', 'isfj-a-vs-isfj-t', 'estj-a-vs-estj-t', 'esfj-a-vs-esfj-t',
        'istp-a-vs-istp-t', 'isfp-a-vs-isfp-t', 'estp-a-vs-estp-t', 'esfp-a-vs-esfp-t',
    ];

    public const CROSS_SLUGS = [
        'intj-vs-intp', 'entj-vs-intj', 'infj-vs-infp', 'istj-vs-isfj',
        'enfp-vs-entp', 'estj-vs-entj', 'isfp-vs-infp',
    ];

    /** @return list<array<string,mixed>> */
    public function validate(array $package, array $authorization): array
    {
        $sourcePrestate = (array) json_decode(
            (string) file_get_contents(base_path(self::AT_SOURCE_PRESTATE_PATH)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $sourcePrestateCore = $sourcePrestate;
        unset($sourcePrestateCore['asset_sha256']);
        if (! hash_equals((string) ($sourcePrestate['asset_sha256'] ?? ''), $this->sha($sourcePrestateCore))
            || ! hash_equals(
                (string) data_get($package, 'source_prestate.asset_sha256', ''),
                (string) ($sourcePrestate['asset_sha256'] ?? ''),
            )
        ) {
            throw new RuntimeException('INDEX-52 A/T source pre-state asset mismatch.');
        }
        $sourcePrestateBySlug = collect((array) ($sourcePrestate['records'] ?? []))
            ->keyBy('slug');

        $packageCore = $package;
        unset($packageCore['package_sha256']);
        if (! hash_equals(self::PACKAGE_SHA256, $this->sha($packageCore))
            || ! hash_equals(self::PACKAGE_SHA256, (string) ($package['package_sha256'] ?? ''))
            || ($package['schema_version'] ?? null) !== 'mbti.index52.comparison_projection_repair.v1'
        ) {
            throw new RuntimeException('INDEX-52 projection repair package hash or contract mismatch.');
        }
        $authorizationCore = $authorization;
        unset($authorizationCore['authorization_sha256']);
        if (! hash_equals(self::AUTHORIZATION_SHA256, $this->sha($authorizationCore))
            || ! hash_equals(self::AUTHORIZATION_SHA256, (string) ($authorization['authorization_sha256'] ?? ''))
            || ! hash_equals(self::PACKAGE_SHA256, (string) ($authorization['approved_package_sha256'] ?? ''))
            || ($authorization['decision'] ?? null) !== 'APPROVED_EXACT_23_PROJECTION_REPAIR_FOR_PREFLIGHT_AND_DRY_RUN_ONLY'
        ) {
            throw new RuntimeException('INDEX-52 projection repair authorization mismatch.');
        }
        foreach ([
            'production_write_authorized',
            'publication_or_indexability_mutation_authorized',
            'sitemap_or_llms_mutation_authorized',
            'search_submission_authorized',
        ] as $held) {
            if (($authorization[$held] ?? null) !== false) {
                throw new RuntimeException('Projection repair authorization must remain dry-run only.');
            }
        }

        $records = $package['records'] ?? null;
        $exactSlugs = [...self::AT_SLUGS, ...self::CROSS_SLUGS];
        if (! is_array($records) || ! array_is_list($records) || count($records) !== 23
            || array_column($records, 'slug') !== $exactSlugs
            || ($authorization['exact_slugs'] ?? null) !== $exactSlugs
            || (int) ($authorization['record_count'] ?? 0) !== 23
        ) {
            throw new RuntimeException('Projection repair must bind the exact ordered 23-record cohort.');
        }

        foreach ($records as $index => $record) {
            if (! is_array($record) || ($record['locale'] ?? null) !== 'zh-CN') {
                throw new RuntimeException("Projection repair record {$index} identity mismatch.");
            }
            $isAt = $index < 16;
            $patch = $record['patch'] ?? null;
            $expectedKeys = $isAt ? ['claim_boundary'] : ['internal_links', 'answer_surface_v1'];
            if (($record['record_kind'] ?? null) !== ($isAt ? 'at_comparison' : 'cross_type_comparison')
                || ! is_array($patch)
                || array_keys($patch) !== $expectedKeys
                || (int) ($record['expected_runtime_sections_count'] ?? 0) < 1
                || ! is_array($record['expected_runtime_sections'] ?? null)
                || count($record['expected_runtime_sections']) !== (int) $record['expected_runtime_sections_count']
                || ! hash_equals(
                    (string) ($record['expected_runtime_sections_sha256'] ?? ''),
                    $this->sha($record['expected_runtime_sections']),
                )
                || preg_match('/^[a-f0-9]{64}$/', (string) ($record['expected_runtime_sections_sha256'] ?? '')) !== 1
            ) {
                throw new RuntimeException("Projection repair record {$index} patch boundary mismatch.");
            }
            if ($isAt && trim((string) ($patch['claim_boundary'] ?? '')) === '') {
                throw new RuntimeException("Projection repair A/T record {$index} lacks claim boundary.");
            }
            if ($isAt) {
                $sourceRecord = $sourcePrestateBySlug->get((string) $record['slug']);
                if (! is_array($sourceRecord)
                    || ! hash_equals(
                        (string) ($record['source_revision_sha256'] ?? ''),
                        $this->sha($sourceRecord['payload_json'] ?? []),
                    )
                ) {
                    throw new RuntimeException("Projection repair A/T record {$index} source revision mismatch.");
                }
            }
            if (! $isAt) {
                $links = $patch['internal_links'] ?? null;
                $surface = $patch['answer_surface_v1'] ?? null;
                $englishGap = $record['english_alternate_authority_gap'] ?? null;
                $expectedLinks = in_array($record['slug'], array_slice(self::CROSS_SLUGS, 4), true) ? 7 : 5;
                if (! is_array($links) || count($links) !== $expectedLinks
                    || ! is_array($surface)
                    || ! is_array($surface['summary_blocks'] ?? null)
                    || ! is_array($surface['faq_blocks'] ?? null)
                    || ! is_array($surface['compare_blocks'] ?? null)
                    || ! is_array($surface['next_step_blocks'] ?? null)
                    || ! is_array($englishGap)
                    || ($englishGap['status'] ?? null) !== 'held_missing_en_backend_record'
                    || ($englishGap['expected_en_canonical'] ?? null) !== 'https://fermatmind.com/en/personality/'.$record['slug']
                    || ($englishGap['production_write_authorized'] ?? null) !== false
                ) {
                    throw new RuntimeException("Projection repair cross record {$index} shape mismatch.");
                }
            }
        }

        /** @var list<array<string,mixed>> $records */
        return $records;
    }

    public function sha(mixed $value): string
    {
        return hash('sha256', (string) json_encode(
            $this->stable($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function stable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->stable($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->stable($item);
        }

        return $value;
    }
}
