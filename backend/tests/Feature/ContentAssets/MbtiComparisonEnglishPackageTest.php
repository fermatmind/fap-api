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
        self::assertSame('EN-PARITY-W1-MBTI-COMPARISON-ASSETS-2026-07-30', $manifest['package_id']);
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
        self::assertSame('8bf265c76d9a1cae3a6cb57a0edd94c4b14852a5c1e7b0606e17edb75d2c840e', $manifest['package_sha256']);
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

            if ($slug === 'istj-vs-isfj') {
                $this->assertIstjSourceRevisionExists($asset['source']['sha256']);
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
        foreach ($this->readPackageJson('assets.json')['assets'] as $asset) {
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

            foreach ($payload['internal_links'] as $link) {
                self::assertStringStartsWith('/en/', $link['href']);
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
                    count($section['body']) > 0 || count($section['rows'] ?? []) > 0,
                    $asset['row_id'].' section '.$section['id'].' must contain body text or rows.',
                );
            }

            foreach ($payload['faq'] as $faq) {
                self::assertGreaterThanOrEqual(20, strlen($faq['question']));
                self::assertGreaterThanOrEqual(60, strlen($faq['answer']));
            }
        }
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

    private function assertIstjSourceRevisionExists(string $sourceRevisionSha): void
    {
        $source = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4).'/backend/content_assets/personality_public/mbti-index52-comparison-projection-repair-2026-07-27.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $records = array_values(array_filter(
            $source['records'],
            static fn (array $record): bool => ($record['slug'] ?? null) === 'istj-vs-isfj',
        ));

        self::assertCount(1, $records);
        self::assertSame($sourceRevisionSha, $records[0]['source_revision_sha256']);
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
