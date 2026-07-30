<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAssets;

use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MbtiResultEnglishPackageTest extends TestCase
{
    private const PACKAGE_DIRECTORY = __DIR__.'/../../../content_assets/en-content-parity/W1-mbti/result-content';

    private const INVENTORY_SHA = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    private const PACKAGE_SHA = '8662bc3cd22f3a00b0bb188e47fdb4247c87bca93ba355485155405af058358f';

    private const EXPECTED_CANDIDATE_ROW_IDS = [
        'W1-RESULT-CORE-05-OFFER-CTA',
        'W1-RESULT-SECTION-04-TRAITS-AT_DIFFERENCE',
        'W1-RESULT-SECTION-06-TRAITS-WHY_THIS_TYPE',
        'W1-RESULT-SECTION-07-TRAITS-CLOSE_CALL_AXES',
        'W1-RESULT-SECTION-08-TRAITS-ADJACENT_TYPE_CONTRAST',
        'W1-RESULT-SECTION-09-TRAITS-DECISION_STYLE',
        'W1-RESULT-SECTION-11-CAREER-COLLABORATION_FIT',
        'W1-RESULT-SECTION-12-CAREER-WORK_ENVIRONMENT',
        'W1-RESULT-SECTION-13-CAREER-WORK_EXPERIMENTS',
        'W1-RESULT-SECTION-17-CAREER-NEXT_STEP',
        'W1-RESULT-SECTION-20-GROWTH-STABILITY_CONFIDENCE',
        'W1-RESULT-SECTION-21-GROWTH-NEXT_ACTIONS',
        'W1-RESULT-SECTION-22-GROWTH-WEEKLY_EXPERIMENTS',
        'W1-RESULT-SECTION-25-GROWTH-STRESS_RECOVERY',
        'W1-RESULT-SECTION-26-GROWTH-WATCHOUTS',
        'W1-RESULT-SECTION-27-GROWTH-MOTIVATORS',
        'W1-RESULT-SECTION-28-GROWTH-DRAINERS',
        'W1-RESULT-SECTION-32-RELATIONSHIPS-COMMUNICATION_STYLE',
        'W1-RESULT-SECTION-33-RELATIONSHIPS-TRY_THIS_WEEK',
        'W1-RESULT-SECTION-34-RELATIONSHIPS-REL_ADVANTAGES',
        'W1-RESULT-SECTION-35-RELATIONSHIPS-REL_RISKS',
    ];

    private const EXPECTED_CONTROL_ROW_IDS = [
        'W1-RESULT-CORE-01-PROFILE',
        'W1-RESULT-CORE-02-SUMMARY-CARD',
        'W1-RESULT-CORE-03-DIMENSIONS',
        'W1-RESULT-CORE-04-ACCESS-ENVELOPE',
        'W1-RESULT-SECTION-01-LETTERS_INTRO',
        'W1-RESULT-SECTION-02-OVERVIEW',
        'W1-RESULT-SECTION-03-TRAIT_OVERVIEW',
        'W1-RESULT-SECTION-05-FAQ',
        'W1-RESULT-SECTION-10-CAREER-SUMMARY',
        'W1-RESULT-SECTION-14-CAREER-ADVANTAGES',
        'W1-RESULT-SECTION-15-CAREER-WEAKNESSES',
        'W1-RESULT-SECTION-16-CAREER-PREFERRED_ROLES',
        'W1-RESULT-SECTION-18-CAREER-UPGRADE_SUGGESTIONS',
        'W1-RESULT-SECTION-19-GROWTH-SUMMARY',
        'W1-RESULT-SECTION-23-GROWTH-STRENGTHS',
        'W1-RESULT-SECTION-24-GROWTH-WEAKNESSES',
        'W1-RESULT-SECTION-29-RELATIONSHIPS-SUMMARY',
        'W1-RESULT-SECTION-30-RELATIONSHIPS-STRENGTHS',
        'W1-RESULT-SECTION-31-RELATIONSHIPS-WEAKNESSES',
        'W1-RESULT-SURFACE-01-SHARE-PUBLIC-SUMMARY',
        'W1-RESULT-SURFACE-03-HISTORY',
        'W1-RESULT-SURFACE-04-RESULT-RENDERER-LABELS',
        'W1-RESULT-SURFACE-05-LIFECYCLE-LABELS',
        'W1-RESULT-SURFACE-06-SHARE-RENDERER-LABELS',
    ];

    private const FORBIDDEN_PAYLOAD_KEYS = [
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
    public function it_freezes_the_exact_result_package_hash(): void
    {
        $manifest = $this->readPackageJson('package_manifest.json');

        self::assertSame('fermatmind.en_parity.immutable_content_package_manifest.v1', $manifest['schema_version']);
        self::assertSame('EN-PARITY-W1-MBTI-RESULT-ASSETS-2026-07-31', $manifest['package_id']);
        self::assertSame(self::INVENTORY_SHA, $manifest['inventory_package_sha256']);
        self::assertSame('unpublished_candidate', $manifest['status']);
        self::assertSame(46, $manifest['inventory_row_count']);
        self::assertSame(24, $manifest['preserved_control_count']);
        self::assertSame(21, $manifest['candidate_asset_count']);
        self::assertSame(1, $manifest['w9_fixture_target_count']);
        self::assertSame(22, $manifest['producer_target_count']);

        $packageHashInput = '';
        foreach ($manifest['files'] as $file) {
            $path = self::PACKAGE_DIRECTORY.'/'.$file['path'];
            self::assertFileExists($path);
            self::assertSame($file['sha256'], hash_file('sha256', $path));
            $packageHashInput .= $file['path']."\0".$file['sha256']."\n";
        }

        self::assertSame(self::PACKAGE_SHA, hash('sha256', $packageHashInput));
        self::assertSame(self::PACKAGE_SHA, $manifest['package_sha256']);

        foreach ($manifest['permissions'] as $permission) {
            self::assertFalse($permission);
        }
    }

    #[Test]
    public function it_reconciles_all_46_rows_without_regenerating_the_24_controls(): void
    {
        $reconciliation = $this->readPackageJson('inventory_reconciliation.json');
        $rows = $reconciliation['rows'];

        self::assertCount(46, $rows);
        self::assertCount(46, array_unique(array_column($rows, 'row_id')));
        self::assertSame('46 = 24 + 21 + 1', $reconciliation['reconciliation']['equation']);

        $controls = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['disposition'] === 'preserved_reference',
        ));
        $candidates = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['disposition'] === 'candidate_asset',
        ));
        $fixtureTargets = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['disposition'] === 'w9_fixture_target',
        ));

        self::assertSame(self::EXPECTED_CONTROL_ROW_IDS, array_column($controls, 'row_id'));
        self::assertSame(self::EXPECTED_CANDIDATE_ROW_IDS, array_column($candidates, 'row_id'));
        self::assertSame(['W1-RESULT-SURFACE-02-PDF'], array_column($fixtureTargets, 'row_id'));

        foreach ($controls as $control) {
            self::assertSame('complete_control', $control['inventory_verdict']);
            self::assertFalse($control['content_in_package']);
        }

        foreach ($candidates as $candidate) {
            self::assertTrue($candidate['content_in_package']);
        }

        self::assertFalse($fixtureTargets[0]['content_in_package']);
    }

    #[Test]
    public function it_provides_distinct_english_assets_for_all_21_content_targets(): void
    {
        $package = $this->readPackageJson('assets.json');
        $assets = $package['assets'];

        self::assertSame(21, $package['asset_count']);
        self::assertCount(21, $assets);
        self::assertSame(self::EXPECTED_CANDIDATE_ROW_IDS, array_column($assets, 'row_id'));
        self::assertSame('same_fields', $package['template_contract']['mobile_desktop_authority']);

        $stableIdentities = [];
        $readerCopyFingerprints = [];

        foreach ($assets as $asset) {
            self::assertSame('same_authority_fields', $asset['mobile_desktop_consumption']);
            self::assertNotSame('', trim($asset['stable_asset_identity']));
            self::assertNotSame('', trim($asset['authority_field']));
            self::assertIsArray($asset['content']);
            self::assertNotEmpty($asset['content']);

            $stableIdentities[] = $asset['stable_asset_identity'];
            $readerCopy = json_encode($asset['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $readerCopy);
            self::assertGreaterThan(180, strlen($readerCopy));
            $readerCopyFingerprints[] = hash('sha256', $readerCopy);

            preg_match_all('/\{\{([a-z_]+)\}\}/', $readerCopy, $matches);
            foreach ($matches[1] as $slot) {
                self::assertContains($slot, $package['template_contract']['allowed_slots']);
            }

            if ($asset['asset_kind'] === 'canonical_section_family') {
                self::assertArrayHasKey('title', $asset['content']);
                self::assertArrayHasKey('summary_template', $asset['content']);
                self::assertCount(2, $asset['content']['body_template']);
                self::assertCount(2, $asset['content']['reflection_prompts']);
            }

            if (isset($asset['section_key'])) {
                $definition = MbtiCanonicalSectionRegistry::definition($asset['section_key']);
                self::assertNotNull($definition);
                self::assertSame(
                    $definition['bucket'].'.'.$asset['section_key'],
                    $asset['authority_field'],
                );
            }
        }

        self::assertCount(21, array_unique($stableIdentities));
        self::assertCount(21, array_unique($readerCopyFingerprints));
    }

    #[Test]
    public function it_covers_the_entitlement_and_reader_surface_matrix_without_policy_changes(): void
    {
        $matrix = $this->readPackageJson('entitlement_matrix.json');

        self::assertSame('same_fields', $matrix['mobile_desktop_authority']);
        self::assertSame([
            'free_result',
            'preview_result',
            'locked_result',
            'full_result',
            'entitlement_envelope',
            'share_public_summary',
            'pdf_reader',
            'history_account_reentry',
            'module_and_cta_labels',
            'processing_empty_error_expired_access_denied',
        ], array_column($matrix['surfaces'], 'surface'));

        foreach ($matrix['surfaces'] as $surface) {
            self::assertNotEmpty($surface['authority_fields']);
            self::assertNotSame('', trim($surface['allowed_content']));
            self::assertNotSame('', trim($surface['blocked_content']));
            self::assertNotSame('', trim($surface['package_behavior']));
        }

        $assets = $this->readPackageJson('assets.json')['assets'];
        $premiumRows = array_column(array_values(array_filter(
            $assets,
            static fn (array $asset): bool => $asset['entitlement_level'] === 'premium_full',
        )), 'row_id');

        self::assertSame([
            'W1-RESULT-SECTION-27-GROWTH-MOTIVATORS',
            'W1-RESULT-SECTION-28-GROWTH-DRAINERS',
            'W1-RESULT-SECTION-34-RELATIONSHIPS-REL_ADVANTAGES',
            'W1-RESULT-SECTION-35-RELATIONSHIPS-REL_RISKS',
        ], $premiumRows);
    }

    #[Test]
    public function it_excludes_private_payload_and_forbidden_claims(): void
    {
        $assets = $this->readPackageJson('assets.json');
        $this->assertNoForbiddenKeys($assets);

        $serialized = strtolower(json_encode($assets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        foreach ([
            'guaranteed career',
            'perfect job',
            'hiring suitability',
            'salary prediction',
            'relationship compatibility guarantee',
            'clinical diagnosis',
            'officially certified',
            'most accurate',
            'predicts your future',
        ] as $forbiddenClaim) {
            self::assertStringNotContainsString($forbiddenClaim, $serialized);
        }

        self::assertStringContainsString('not diagnoses, fixed identities, ability judgments, or outcome predictions', $serialized);

        $claimReport = $this->readPackageJson('claim_boundary_report.json');
        self::assertSame('pass_producer_self_check_only', $claimReport['result']);
        self::assertFalse($claimReport['independent_w9']);

        $actualMatches = [];
        foreach ($claimReport['forbidden_claim_scan']['phrases'] as $phrase) {
            if (str_contains($serialized, strtolower($phrase))) {
                $actualMatches[] = $phrase;
            }
        }
        self::assertSame(
            $actualMatches,
            array_column($claimReport['forbidden_claim_scan']['matches'], 'phrase'),
        );
        self::assertSame(['fixed identity'], $actualMatches);
        self::assertSame('negated_boundary', $claimReport['forbidden_claim_scan']['matches'][0]['context']);
        self::assertSame('allowed_explicit_rejection', $claimReport['forbidden_claim_scan']['matches'][0]['disposition']);
    }

    #[Test]
    public function it_freezes_a_private_safe_pdf_fixture_target_for_independent_w9(): void
    {
        $mapping = $this->readPackageJson('pdf_reader_fixture_mapping.json');

        self::assertSame('W1-RESULT-SURFACE-02-PDF', $mapping['row_id']);
        self::assertSame('w9_fixture_target', $mapping['status']);
        self::assertSame('synthetic_private_safe', $mapping['fixture_contract']['fixture_kind']);
        self::assertFalse($mapping['fixture_contract']['production_payload_read_allowed']);
        self::assertFalse($mapping['fixture_contract']['live_private_url_allowed']);
        self::assertFalse($mapping['fixture_contract']['database_read_allowed']);
        self::assertSame('en', $mapping['fixture_contract']['required_locale']);
        self::assertSame('entitled_report', $mapping['fixture_contract']['required_access_state']);
        self::assertSame('same_fields', $mapping['fixture_contract']['mobile_desktop_authority']);
        self::assertFalse($mapping['fixture_contract']['current_runtime_consumes_candidate_package']);
        self::assertSame('mbti_pdf_payload', $mapping['runtime_payload_contract']['payload_root']);
        self::assertSame(
            ['type', 'axis_scores', 'result_page_sections', 'document'],
            $mapping['runtime_payload_contract']['document_service_consumed_fields'],
        );
        self::assertSame(
            'exact_package_to_mbti_pdf_fixture_v1',
            $mapping['required_w9_adapter_contract']['adapter_id'],
        );
        self::assertTrue($mapping['required_w9_adapter_contract']['must_read_exact_frozen_package']);
        self::assertTrue($mapping['required_w9_adapter_contract']['must_fail_if_any_candidate_asset_is_not_exercised']);
        self::assertFalse($mapping['required_w9_adapter_contract']['runtime_or_cms_activation_allowed']);
        self::assertCount(4, $mapping['projection']);
        self::assertSame(
            ['type', 'axis_scores', 'result_page_sections', 'document'],
            array_column($mapping['projection'], 'pdf_payload_field'),
        );
        self::assertSame(
            ['sections', 'premium_teaser'],
            $mapping['projection'][2]['package_authority_buckets'],
        );
        self::assertSame(21, $mapping['projection'][3]['required_candidate_row_count']);
        self::assertFalse($mapping['projection'][3]['legacy_only_render_is_acceptable']);
        self::assertCount(9, $mapping['w9_assertions']);

        self::assertCount(13, $mapping['excluded_fixture_fields']);
        foreach ($mapping['excluded_fixture_fields'] as $excludedField) {
            self::assertContains($excludedField, self::FORBIDDEN_PAYLOAD_KEYS);
        }
    }

    #[Test]
    public function it_marks_producer_review_as_non_human_and_keeps_release_gates_closed(): void
    {
        $review = $this->readPackageJson('editorial_review.json');
        $manifest = $this->readPackageJson('package_manifest.json');

        self::assertSame('producer_self_review', $review['review_kind']);
        self::assertFalse($review['human_editorial_approval']);
        self::assertFalse($review['independent_w9']);
        self::assertSame(46, $review['coverage']['inventory_rows_reviewed']);
        self::assertSame(24, $review['coverage']['preserved_controls_reviewed_for_disposition']);
        self::assertSame(21, $review['coverage']['candidate_assets_reviewed']);
        self::assertSame(1, $review['coverage']['pdf_fixture_targets_reviewed']);
        self::assertNotEmpty($review['known_holds']);
        self::assertSame('pending', $manifest['quality_gates']['independent_w9']);
        self::assertSame('pending_future_pr', $manifest['quality_gates']['exact_package_dry_run']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageJson(string $filename): array
    {
        $json = file_get_contents(self::PACKAGE_DIRECTORY.'/'.$filename);
        self::assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function assertNoForbiddenKeys(array $value): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                self::assertNotContains($key, self::FORBIDDEN_PAYLOAD_KEYS);
            }

            if (is_array($nested)) {
                $this->assertNoForbiddenKeys($nested);
            }
        }
    }
}
