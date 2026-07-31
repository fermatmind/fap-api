<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAssets;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MbtiComparisonEnglishPackageTest extends TestCase
{
    private const PACKAGE_DIRECTORY = __DIR__.'/../../../content_assets/en-content-parity/W1-mbti/comparisons';

    private const INVENTORY_SHA = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    private const EXPECTED_SLUGS = [
        'enfp-vs-entp',
        'entj-vs-intj',
        'estj-vs-entj',
        'infj-vs-infp',
        'intj-vs-intp',
        'isfp-vs-infp',
        'istj-vs-isfj',
    ];

    private const EXPECTED_ROW_IDS = [
        'W1-COMP-01-ENFP-VS-ENTP',
        'W1-COMP-02-ENTJ-VS-INTJ',
        'W1-COMP-03-ESTJ-VS-ENTJ',
        'W1-COMP-04-INFJ-VS-INFP',
        'W1-COMP-05-INTJ-VS-INTP',
        'W1-COMP-06-ISFP-VS-INFP',
        'W1-COMP-07-ISTJ-VS-ISFJ',
    ];

    private const FORBIDDEN_KEYS = [
        'attempt_id',
        'attempt_uuid',
        'report_token',
        'result_lookup_token',
        'share_token',
        'user_id',
        'account_id',
        'email',
        'phone',
        'user_scores',
        'raw_scores',
        'answers',
        'answer_key',
        'orders',
        'payments',
        'recovery_data',
        'internal_generation_rules',
        'internal_asset_hashes',
        'secret',
        'cookie',
        'authorization',
    ];

    #[Test]
    public function it_freezes_the_exact_seven_asset_package_and_hashes(): void
    {
        $manifest = $this->readPackageJson('package_manifest.json');
        $package = $this->readPackageJson('assets.json');

        self::assertSame('fermatmind.en_parity.immutable_content_package_manifest.v1', $manifest['schema_version']);
        self::assertSame('EN-PARITY-W1-MBTI-COMPARISON-ASSETS-W9-CORRECTION-2026-07-31', $manifest['package_id']);
        self::assertSame(self::INVENTORY_SHA, $manifest['inventory_package_sha256']);
        self::assertSame('unpublished_candidate', $manifest['status']);
        self::assertSame(7, $manifest['asset_count']);
        self::assertSame(self::EXPECTED_SLUGS, $manifest['exact_slugs']);
        self::assertSame(7, $package['asset_count']);
        self::assertCount(7, $package['assets']);

        $packageHashInput = '';
        foreach ($manifest['files'] as $file) {
            $path = self::PACKAGE_DIRECTORY.'/'.$file['path'];
            self::assertFileExists($path);
            self::assertSame($file['sha256'], hash_file('sha256', $path));
            $packageHashInput .= $file['path']."\0".$file['sha256']."\n";
        }

        self::assertSame($manifest['package_sha256'], hash('sha256', $packageHashInput));
        self::assertSame('3325d3999edda87e3c6e374136e0571308641f0006ffa447e15f946acabe9975', $manifest['package_sha256']);
    }

    #[Test]
    public function it_binds_every_asset_to_the_frozen_inventory_and_source_authority(): void
    {
        $package = $this->readPackageJson('assets.json');
        $assets = $package['assets'];

        self::assertSame(self::INVENTORY_SHA, $package['inventory_package_sha256']);
        self::assertSame(self::EXPECTED_ROW_IDS, array_column($assets, 'row_id'));
        self::assertSame(
            self::EXPECTED_SLUGS,
            array_map(static fn (array $asset): string => $asset['payload']['comparison_slug'], $assets),
        );

        foreach ($assets as $asset) {
            $slug = $asset['payload']['comparison_slug'];
            self::assertSame(
                'cohort:mbti:cross-type-comparison:'.$slug,
                $asset['translation_pair_identity'],
            );
            self::assertSame(
                'mbti_cross_type_comparison_authorities:org0:en:'.$slug,
                $asset['target_stable_asset_identity'],
            );
            self::assertSame('zh-CN', $asset['source']['locale']);
            self::assertSame('MbtiCrossTypeComparisonAuthority', $asset['source']['authority']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $asset['source']['sha256']);

            if ($asset['translation_contract']['source_binding'] === 'backend_revision') {
                $this->assertBackendSourceRevisionExists($slug, $asset['source']['sha256']);
            } else {
                $sourcePath = dirname(__DIR__, 4).'/'.$asset['source']['path'];
                self::assertFileExists($sourcePath);
                self::assertSame($asset['source']['sha256'], hash_file('sha256', $sourcePath));
            }
        }
    }

    #[Test]
    public function it_preserves_source_structure_and_public_payload_alignment(): void
    {
        $package = $this->readPackageJson('assets.json');
        $translationMap = $this->readPackageJson('translation_map.json');

        self::assertSame('source_structured_editorial_translation', $translationMap['relationship']);
        self::assertCount(7, $translationMap['assets']);

        foreach ($package['assets'] as $asset) {
            $payload = $asset['payload'];
            self::assertSame('mbti.cross_type_comparison.public.v1', $payload['comparison_contract_version']);
            self::assertSame('mbti_cross_type', $payload['comparison_type']);
            self::assertSame('MBTI', $payload['scale_code']);
            self::assertSame('en', $payload['locale']);
            self::assertSame('cross-type-comparison', $payload['public_route_type']);
            self::assertSame(
                [$payload['left_type'], $payload['right_type']],
                $payload['base_type_codes'],
            );
            self::assertNotSame($payload['left_type'], $payload['right_type']);
            self::assertSame(
                'https://fermatmind.com/en/personality/'.$payload['comparison_slug'],
                $payload['canonical_url'],
            );
            self::assertCount($asset['source']['section_count'], $payload['sections']);
            self::assertCount($asset['source']['faq_count'], $payload['faq']);
            self::assertGreaterThanOrEqual(4, count($payload['internal_links']));
            self::assertSame('source_structured_editorial_translation', $asset['translation_contract']['mode']);
            self::assertTrue($asset['translation_contract']['structure_preserved']);

            foreach ($payload['internal_links'] as $link) {
                self::assertStringStartsWith('/en/', $link['href']);
            }

            $mapRows = array_values(array_filter(
                $translationMap['assets'],
                static fn (array $row): bool => $row['row_id'] === $asset['row_id'],
            ));
            self::assertCount(1, $mapRows);
            self::assertTrue($mapRows[0]['structure_preserved']);

            if ($mapRows[0]['source_ref_kind'] === 'backend_revision_sha256') {
                self::assertSame('backend_revision_sha256', $mapRows[0]['source_ref_kind']);
                $source = $this->readBackendSourceRevision(
                    $payload['comparison_slug'],
                    $asset['source']['sha256'],
                );
            } else {
                $source = $this->readJsonFromRepository($asset['source']['path']);
            }

            self::assertCount(count($source['sections']), $payload['sections']);

            foreach ($source['sections'] as $index => $sourceSection) {
                $targetSection = $payload['sections'][$index];
                self::assertSame($sourceSection['id'], $targetSection['id']);
                self::assertSame(
                    count($sourceSection['body'] ?? []),
                    count($targetSection['body'] ?? []),
                    $asset['row_id'].' '.$sourceSection['id'].' body cardinality drifted.',
                );
                self::assertSame(
                    count($sourceSection['groups'] ?? []),
                    count($targetSection['groups'] ?? []),
                    $asset['row_id'].' '.$sourceSection['id'].' group cardinality drifted.',
                );
                self::assertSame(
                    array_map(static fn (array $group): int => count($group['items']), $sourceSection['groups'] ?? []),
                    array_map(static fn (array $group): int => count($group['items']), $targetSection['groups'] ?? []),
                    $asset['row_id'].' '.$sourceSection['id'].' nested item cardinality drifted.',
                );
                self::assertSame(
                    count($sourceSection['items'] ?? []),
                    count($targetSection['items'] ?? []),
                    $asset['row_id'].' '.$sourceSection['id'].' item cardinality drifted.',
                );
                $sourceRows = count($sourceSection['rows'] ?? []);
                $projectionRows = $sourceRows > 0
                    ? $sourceRows
                    : array_sum(array_map(
                        static fn (array $group): int => count($group['items']),
                        $sourceSection['groups'] ?? [],
                    )) + count($sourceSection['items'] ?? []);
                self::assertSame(
                    $projectionRows,
                    count($targetSection['rows'] ?? []),
                    $asset['row_id'].' '.$sourceSection['id'].' runtime projection row cardinality drifted.',
                );

                if (($targetSection['groups'] ?? []) !== []) {
                    $expectedRows = [];
                    foreach ($targetSection['groups'] as $group) {
                        foreach ($group['items'] as $item) {
                            $expectedRows[] = ['group' => $group['title'], 'item' => $item];
                        }
                    }
                    self::assertSame($expectedRows, $targetSection['rows']);
                } elseif (($targetSection['items'] ?? []) !== []) {
                    self::assertSame(
                        array_map(
                            static fn (string $item): array => ['item' => $item],
                            $targetSection['items'],
                        ),
                        $targetSection['rows'],
                    );
                }

            }
        }
    }

    #[Test]
    public function it_keeps_reader_copy_english_complete_and_claim_bounded(): void
    {
        foreach ($this->readPackageJson('assets.json')['assets'] as $asset) {
            $payload = $asset['payload'];
            $serialized = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $serialized);
            self::assertGreaterThanOrEqual(120, strlen($payload['summary']));
            self::assertGreaterThanOrEqual(40, strlen($payload['seo_description']));
            self::assertSame(
                'Preference-based educational comparison only; no diagnosis, fixed identity, screening decision, compatibility guarantee, or outcome prediction.',
                $payload['claim_boundary'],
            );

            foreach ($payload['sections'] as $section) {
                self::assertNotSame('', trim($section['id']));
                self::assertNotSame('', trim($section['title']));
                self::assertTrue(
                    count($section['body'] ?? []) > 0
                    || count($section['rows'] ?? []) > 0
                    || count($section['groups'] ?? []) > 0
                    || count($section['items'] ?? []) > 0,
                    $asset['row_id'].' section '.$section['id'].' must contain body text, rows, groups, or items.',
                );

                foreach ($section['groups'] ?? [] as $group) {
                    self::assertNotSame('', trim($group['title']));
                    self::assertNotEmpty($group['items']);
                }
            }

            foreach ($payload['faq'] as $faq) {
                self::assertGreaterThanOrEqual(20, strlen($faq['question']));
                self::assertGreaterThanOrEqual(60, strlen($faq['answer']));
            }
        }
    }

    #[Test]
    public function it_includes_the_required_translation_and_producer_review_artifacts(): void
    {
        $package = $this->readPackageJson('assets.json');
        $translationMap = $this->readPackageJson('translation_map.json');
        $claimBoundaryReport = $this->readPackageJson('claim_boundary_report.json');
        $editorialReview = $this->readPackageJson('editorial_review.json');

        self::assertSame($package['package_id'], $translationMap['package_id']);
        self::assertSame($package['package_id'], $claimBoundaryReport['package_id']);
        self::assertSame($package['package_id'], $editorialReview['package_id']);
        self::assertCount(7, $claimBoundaryReport['rows']);
        self::assertCount(7, $editorialReview['rows']);
        self::assertFalse($claimBoundaryReport['independent_w9']);
        self::assertFalse($editorialReview['independent_w9']);
        self::assertFalse($claimBoundaryReport['controlled_actions_authorized']);

        foreach ($claimBoundaryReport['rows'] as $row) {
            self::assertSame('pass_producer_self_check_only', $row['result']);
            self::assertNotContains('fail', array_values($row['checks']));
        }
        foreach ($editorialReview['rows'] as $row) {
            self::assertNotContains('fail', array_values($row['checks']));

            self::assertSame('pass', $row['checks']['source_bound']);
            self::assertSame('ready_for_independent_w9', $row['disposition']);
        }

        $intjVsIntp = array_values(array_filter(
            $package['assets'],
            static fn (array $asset): bool => $asset['payload']['comparison_slug'] === 'intj-vs-intp',
        ));
        self::assertCount(1, $intjVsIntp);
        self::assertArrayNotHasKey('evidence_limitations', $intjVsIntp[0]['source']);
    }

    #[Test]
    public function it_excludes_private_fields_and_keeps_every_controlled_permission_false(): void
    {
        $manifest = $this->readPackageJson('package_manifest.json');
        $package = $this->readPackageJson('assets.json');

        self::assertNotEmpty($manifest['permissions']);
        self::assertNotEmpty($package['permissions']);
        self::assertNotContains(true, array_values($manifest['permissions']));
        self::assertNotContains(true, array_values($package['permissions']));

        foreach ($package['assets'] as $asset) {
            self::assertSame('unpublished_candidate', $asset['publication']['status']);
            self::assertSame('pending_independent_w9', $asset['publication']['review_status']);
            self::assertSame('blocked', $asset['publication']['indexability_status']);
            self::assertSame([], array_values(array_intersect(
                self::FORBIDDEN_KEYS,
                $this->recursiveKeys($asset),
            )));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageJson(string $file): array
    {
        $decoded = json_decode(
            (string) file_get_contents(self::PACKAGE_DIRECTORY.'/'.$file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFromRepository(string $path): array
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4).'/'.$path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function assertBackendSourceRevisionExists(string $slug, string $sourceRevisionSha): void
    {
        $this->readBackendSourceRevision($slug, $sourceRevisionSha);
    }

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    private function readBackendSourceRevision(string $slug, string $sourceRevisionSha): array
    {
        $source = $this->readJsonFromRepository(
            'backend/content_assets/personality_public/mbti-index52-comparison-projection-repair-2026-07-27.json',
        );
        $records = array_values(array_filter(
            $source['records'],
            static fn (array $record): bool => ($record['slug'] ?? null) === $slug,
        ));

        self::assertCount(1, $records);
        self::assertSame($sourceRevisionSha, $records[0]['source_revision_sha256']);

        return ['sections' => $records[0]['expected_runtime_sections']];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function recursiveKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $keys[] = strtolower($key);
            }
            if (is_array($nested)) {
                $keys = [...$keys, ...$this->recursiveKeys($nested)];
            }
        }

        return array_values(array_unique($keys));
    }
}
