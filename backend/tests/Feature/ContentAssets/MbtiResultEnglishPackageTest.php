<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAssets;

use App\Services\Report\Pdf\Mbti\MbtiPdfPayloadBuilder;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class MbtiResultEnglishPackageTest extends TestCase
{
    private const PACKAGE_DIRECTORY = __DIR__.'/../../../content_assets/en-content-parity/W1-mbti/result-content';

    private const INVENTORY_SHA = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    private const PACKAGE_SHA = '21a6d77b6f3232a01aeb3c0ffc391c6fde15dfc6d40661506c1d4ebca561c536';

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
        $approvalEnvelope = $this->readPackageJson('approval_envelope.json');

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
        self::assertSame(
            'fermatmind.en_parity.immutable_package_approval_envelope.v1',
            $approvalEnvelope['schema_version'],
        );
        foreach ([
            'package_id',
            'lane_id',
            'asset_id',
            'inventory_package_id',
            'inventory_package_sha256',
            'source_ledger_sha256',
            'status',
            'locale',
            'inventory_row_count',
            'preserved_control_count',
            'candidate_asset_count',
            'w9_fixture_target_count',
            'producer_target_count',
            'quality_gates',
            'permissions',
        ] as $boundManifestField) {
            self::assertArrayHasKey($boundManifestField, $manifest);
            self::assertSame($manifest[$boundManifestField], $approvalEnvelope[$boundManifestField]);
        }

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
        $pdfFixtureMapping = $this->readPackageJson('pdf_reader_fixture_mapping.json');
        $assets = $package['assets'];
        $fixtureSlots = $pdfFixtureMapping['synthetic_slot_values'];
        self::assertSame('package-frozen private-safe non-user fixture', $fixtureSlots['source']);
        unset($fixtureSlots['source']);
        self::assertSame([
            'type_code' => 'INTJ-A',
            'identity_variant' => 'Assertive',
            'axis_label' => 'Assertive–Turbulent (A/T)',
            'side_label' => 'Assertive (A)',
            'opposite_side_label' => 'Turbulent (T)',
            'delta' => '2',
            'neighbor_type' => 'INTP',
            'adjacent_axis_label' => 'Judging–Perceiving (J/P)',
            'adjacent_side_label' => 'Judging (J)',
            'adjacent_opposite_side_label' => 'Perceiving (P)',
        ], $fixtureSlots);

        self::assertSame(21, $package['asset_count']);
        self::assertCount(21, $assets);
        self::assertSame(self::EXPECTED_CANDIDATE_ROW_IDS, array_column($assets, 'row_id'));
        self::assertFalse($package['template_contract']['runtime_import_slot_values_stored_in_this_package']);
        self::assertTrue($package['template_contract']['w9_synthetic_slot_values_stored_in_pdf_fixture_mapping']);
        self::assertSame('same_fields', $package['template_contract']['mobile_desktop_authority']);
        self::assertSame([
            'type_code',
            'identity_variant',
            'axis_label',
            'side_label',
            'opposite_side_label',
            'delta',
            'neighbor_type',
            'adjacent_axis_label',
            'adjacent_side_label',
            'adjacent_opposite_side_label',
        ], $package['template_contract']['allowed_slots']);
        self::assertSame(
            'App\\Services\\ContentImport\\MbtiResultEnglishPackageImporter',
            $package['template_contract']['renderer_binding']['owner'],
        );
        self::assertSame(
            'EN-PARITY-W1-MBTI-RESULT-IMPORTER-01',
            $package['template_contract']['renderer_binding']['implementation_pr'],
        );
        self::assertSame(
            'reject every unresolved token except the eight registered result-runtime slots; preserve those slots for the separately gated result renderer',
            $package['template_contract']['renderer_binding']['unresolved_token_policy'],
        );
        self::assertFalse($package['template_contract']['renderer_binding']['import_without_renderer_allowed']);
        self::assertFalse($package['template_contract']['renderer_binding']['runtime_template_rendering_added_by_this_package']);
        self::assertSame(
            'App\\Services\\Mbti\\MbtiResultPersonalizationService',
            $package['template_contract']['result_runtime_binding']['target_owner'],
        );
        self::assertFalse($package['template_contract']['result_runtime_binding']['existing_runtime_contract']);
        self::assertTrue($package['template_contract']['result_runtime_binding']['implementation_required_before_activation']);
        self::assertSame(
            'separate runtime-renderer prerequisite PR before activation; not this content-asset PR and not CMS import',
            $package['template_contract']['result_runtime_binding']['implementation_scope'],
        );
        self::assertSame([
            'traits.close_call_axes',
            'traits.adjacent_type_contrast',
        ], $package['template_contract']['result_runtime_binding']['sections']);
        self::assertSame([
            'axis_label',
            'side_label',
            'opposite_side_label',
            'delta',
            'neighbor_type',
            'adjacent_axis_label',
            'adjacent_side_label',
            'adjacent_opposite_side_label',
        ], array_keys($package['template_contract']['result_runtime_binding']['slot_sources']));
        self::assertFalse($package['template_contract']['result_runtime_binding']['cms_or_import_time_result_inference_allowed']);
        $sectionProjection = $package['template_contract']['canonical_projection_contract']['sections'];
        self::assertSame('inactive_or_draft_authority_only', $sectionProjection['storage_visibility']);
        self::assertFalse($sectionProjection['public_share_projection_allowed']);
        self::assertFalse($sectionProjection['public_personality_projection_allowed']);
        self::assertContains(
            'public share section allowlist or explicit package-section exclusion',
            $sectionProjection['activation_prerequisites'],
        );
        self::assertContains(
            'result-only authority isolation or explicit public-personality section exclusion',
            $sectionProjection['activation_prerequisites'],
        );
        self::assertContains(
            'separate result-specific runtime renderer with real-projection substitution and unresolved-token rejection tests',
            $sectionProjection['activation_prerequisites'],
        );
        self::assertFalse(
            $package['template_contract']['canonical_projection_contract']['published_cms_share_authority_write_allowed'],
        );
        self::assertFalse(
            $package['template_contract']['canonical_projection_contract']['published_public_personality_authority_write_allowed'],
        );
        self::assertFalse(
            $package['template_contract']['canonical_projection_contract']['runtime_or_entitlement_policy_change_allowed'],
        );

        $stableIdentities = [];
        $readerCopyFingerprints = [];

        foreach ($assets as $asset) {
            self::assertSame('same_authority_fields', $asset['mobile_desktop_consumption']);
            self::assertNotSame('', trim($asset['stable_asset_identity']));
            self::assertNotSame('', trim($asset['authority_field']));
            self::assertIsArray($asset['content']);
            self::assertNotEmpty($asset['content']);

            if ($asset['asset_kind'] === 'offer_copy_family') {
                self::assertSame('commercial_spec.variants[].cta_copy', $asset['authority_field']);
                self::assertSame('offer_set.cta', $asset['consumer_field']);
                self::assertSame('locked_upsell_only', $asset['entitlement_level']);
                self::assertSame([
                    'variant_id' => 'mbti_report_paywall_default_v1',
                    'default' => true,
                    'upgrade_sku_anchor' => 'MBTI_REPORT_FULL',
                    'upgrade_sku' => 'MBTI_REPORT_FULL_199',
                    'resolver' => 'OfferResolver::resolveCtaCopy',
                ], $asset['variant_selector']);
                self::assertSame(
                    ['title', 'subtitle', 'primary_label', 'secondary_label', 'benefit_bullets', 'badge'],
                    array_keys($asset['content']),
                );
                self::assertNotEmpty($asset['content']['benefit_bullets']);
            }

            $stableIdentities[] = $asset['stable_asset_identity'];
            $readerCopy = json_encode($asset['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $readerCopy);
            self::assertGreaterThan(180, strlen($readerCopy));
            $readerCopyFingerprints[] = hash('sha256', $readerCopy);

            preg_match_all('/\{\{([a-z_]+)\}\}/', $readerCopy, $matches);
            foreach ($matches[1] as $slot) {
                self::assertContains($slot, $package['template_contract']['allowed_slots']);
            }

            $renderedContent = $this->renderTemplateValue($asset['content'], $fixtureSlots);
            self::assertIsArray($renderedContent);
            self::assertStringNotContainsString(
                '{{',
                json_encode($renderedContent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
            if (($asset['section_key'] ?? null) === 'traits.close_call_axes') {
                self::assertStringContainsString('{{axis_label}}', $readerCopy);
                self::assertStringContainsString('{{delta}}', $readerCopy);
                self::assertStringContainsString('{{side_label}}', $readerCopy);
                self::assertStringContainsString('{{opposite_side_label}}', $readerCopy);
                $renderedReaderCopy = json_encode(
                    $renderedContent,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                self::assertStringContainsString('Assertive–Turbulent (A/T)', $renderedReaderCopy);
                self::assertStringContainsString('2-point margin', $renderedReaderCopy);
                self::assertStringContainsString('Assertive (A)', $renderedReaderCopy);
                self::assertStringContainsString('Turbulent (T)', $renderedReaderCopy);
            }
            if (($asset['section_key'] ?? null) === 'traits.adjacent_type_contrast') {
                self::assertStringContainsString('{{neighbor_type}}', $readerCopy);
                self::assertStringContainsString('{{adjacent_axis_label}}', $readerCopy);
                self::assertStringContainsString('{{adjacent_side_label}}', $readerCopy);
                self::assertStringContainsString('{{adjacent_opposite_side_label}}', $readerCopy);
                $renderedReaderCopy = json_encode(
                    $renderedContent,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                self::assertStringContainsString('INTP', $renderedReaderCopy);
                self::assertStringContainsString('INTJ-A', $renderedReaderCopy);
                self::assertStringContainsString('Judging–Perceiving (J/P)', $renderedReaderCopy);
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

                if ($definition['bucket'] === MbtiCanonicalSectionRegistry::BUCKET_PREMIUM_TEASER) {
                    self::assertArrayHasKey('teaser', $asset['content']);
                    self::assertNotSame('', trim($asset['content']['teaser']));
                    self::assertSame($asset['content']['summary_template'], $asset['content']['teaser']);
                    $projected = [
                        'title' => $renderedContent['title'],
                        'teaser' => $renderedContent['teaser'],
                        'payload' => null,
                    ];
                    self::assertStringNotContainsString('{{', $projected['teaser']);
                    self::assertNull($projected['payload']);
                    self::assertFalse(
                        $package['template_contract']['canonical_projection_contract']['premium_teaser']['protected_full_content']['public_projection_allowed'],
                    );
                    self::assertSame(
                        'inactive_or_draft_authority_only',
                        $package['template_contract']['canonical_projection_contract']['premium_teaser']['protected_full_content']['storage_visibility'],
                    );
                    self::assertTrue(
                        $package['template_contract']['canonical_projection_contract']['premium_teaser']['entitlement_filter_is_not_implemented_by_this_package'],
                    );
                    $protectedContract = $package['template_contract']['canonical_projection_contract']['premium_teaser']['protected_full_content'];
                    self::assertSame('result_only_authority.premium_full', $protectedContract['authority_bucket']);
                    self::assertSame('asset.section_key', $protectedContract['section_key_from']);
                    $resultPageAccess = [
                        'mbti_access_hub_v1.access_state' => 'ready',
                        'access_level' => 'full',
                        'variant' => 'full',
                        'locked' => false,
                        'mbti_access_hub_v1.report_access.can_view_report' => true,
                    ];
                    $pdfAccess = [
                        ...$resultPageAccess,
                        'mbti_access_hub_v1.pdf_access.can_download_pdf' => true,
                    ];
                    self::assertSame([
                        'full_result.result_page_sections' => $resultPageAccess,
                        'mbti_pdf_payload.result_page_sections' => $pdfAccess,
                    ], $protectedContract['consumer_access_contracts']);
                    $reportReadyPdfPending = [
                        ...$resultPageAccess,
                        'mbti_access_hub_v1.pdf_access.can_download_pdf' => false,
                    ];
                    $matchesAccessContract = static fn (array $actual, array $required): bool => array_all(
                        $required,
                        static fn (mixed $expected, string $key): bool => array_key_exists($key, $actual)
                            && $actual[$key] === $expected,
                    );
                    self::assertTrue($matchesAccessContract($reportReadyPdfPending, $resultPageAccess));
                    self::assertFalse($matchesAccessContract($reportReadyPdfPending, $pdfAccess));
                    $entitledProjection = [
                        'title' => $renderedContent['title'],
                        'body' => implode("\n\n", [
                            $renderedContent['summary_template'],
                            ...$renderedContent['body_template'],
                        ]),
                        'payload' => [
                            'summary_template' => $renderedContent['summary_template'],
                            'reflection_prompts' => $renderedContent['reflection_prompts'],
                        ],
                    ];
                    self::assertStringNotContainsString('{{', $entitledProjection['body']);
                    self::assertNotSame('', trim($entitledProjection['body']));
                    self::assertNotEmpty($entitledProjection['payload']['reflection_prompts']);
                } else {
                    $projected = [
                        'title' => $renderedContent['title'],
                        'body' => implode("\n\n", [
                            $renderedContent['summary_template'],
                            ...$renderedContent['body_template'],
                        ]),
                        'payload' => [
                            'summary_template' => $renderedContent['summary_template'],
                            'reflection_prompts' => $renderedContent['reflection_prompts'],
                        ],
                    ];
                    self::assertStringNotContainsString('{{', $projected['body']);
                    self::assertStringContainsString($renderedContent['summary_template'], $projected['body']);
                    foreach ($renderedContent['body_template'] as $paragraph) {
                        self::assertStringContainsString($paragraph, $projected['body']);
                    }
                    self::assertSame(
                        $renderedContent['reflection_prompts'],
                        $projected['payload']['reflection_prompts'],
                    );
                }
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
        self::assertFalse(
            $matrix['cross_surface_isolation']['public_personality_profiles']['package_candidate_projection_allowed'],
        );
        self::assertFalse(
            $matrix['cross_surface_isolation']['public_personality_profiles']['published_personality_profile_variant_section_write_allowed'],
        );
        self::assertSame(
            'result-only authority isolation or explicit public-personality section exclusion',
            $matrix['cross_surface_isolation']['public_personality_profiles']['activation_gate'],
        );
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

        $surfacesByName = collect($matrix['surfaces'])->keyBy('surface');
        self::assertNotContains('offer_set', $surfacesByName['preview_result']['authority_fields']);
        self::assertStringContainsString(
            'no preview offer candidate',
            $surfacesByName['preview_result']['package_behavior'],
        );
        self::assertContains('offer_set', $surfacesByName['locked_result']['authority_fields']);
        self::assertContains(
            'result_only_authority.premium_full',
            $surfacesByName['full_result']['authority_fields'],
        );
        self::assertStringContainsString(
            'locked-upsell offer copy',
            $surfacesByName['module_and_cta_labels']['allowed_content'],
        );
        self::assertStringContainsString(
            'all package candidate section body or payload',
            $surfacesByName['share_public_summary']['blocked_content'],
        );
        self::assertStringContainsString(
            'outside published CMS share authority',
            $surfacesByName['share_public_summary']['package_behavior'],
        );

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
        self::assertSame([], $actualMatches);
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
        self::assertSame('entitled_report', $mapping['fixture_contract']['fixture_state_label']);
        self::assertSame([
            'mbti_access_hub_v1.access_state' => 'ready',
            'access_level' => 'full',
            'variant' => 'full',
            'locked' => false,
            'mbti_access_hub_v1.report_access.can_view_report' => true,
            'mbti_access_hub_v1.pdf_access.can_download_pdf' => true,
        ], $mapping['fixture_contract']['required_runtime_access']);
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
        self::assertSame([
            'source' => 'package-frozen private-safe non-user fixture',
            'type_code' => 'INTJ-A',
            'identity_variant' => 'Assertive',
            'axis_label' => 'Assertive–Turbulent (A/T)',
            'side_label' => 'Assertive (A)',
            'opposite_side_label' => 'Turbulent (T)',
            'delta' => '2',
            'neighbor_type' => 'INTP',
            'adjacent_axis_label' => 'Judging–Perceiving (J/P)',
            'adjacent_side_label' => 'Judging (J)',
            'adjacent_opposite_side_label' => 'Perceiving (P)',
        ], $mapping['synthetic_slot_values']);
        self::assertSame(
            'canonical_section_to_pdf_group_card_v1',
            $mapping['card_shape_adapter']['adapter_id'],
        );
        self::assertSame(
            ['traits', 'career', 'growth', 'relationships'],
            $mapping['card_shape_adapter']['grouping']['group_order'],
        );
        self::assertSame([
            'card_key_from' => 'asset.row_id',
            'title_from' => 'rendered content.title',
            'description_from' => 'rendered content.summary_template',
            'bullets_from' => 'rendered content.body_template',
            'tips_from' => 'rendered content.reflection_prompts',
            'tags' => [],
        ], $mapping['card_shape_adapter']['card_projection']);
        self::assertTrue($mapping['card_shape_adapter']['fail_if_description_bullets_or_tips_are_empty']);
        self::assertTrue($mapping['required_w9_adapter_contract']['must_read_exact_frozen_package']);
        self::assertTrue($mapping['required_w9_adapter_contract']['must_use_exact_synthetic_slot_values']);
        self::assertTrue(
            $mapping['required_w9_adapter_contract']['must_project_protected_premium_content_only_under_synthetic_entitled_state'],
        );
        self::assertTrue($mapping['required_w9_adapter_contract']['must_match_exact_pdf_runtime_access_contract']);
        self::assertTrue($mapping['required_w9_adapter_contract']['must_preserve_candidate_row_id_as_card_key']);
        self::assertTrue($mapping['required_w9_adapter_contract']['must_fail_if_any_pdf_candidate_asset_is_not_exercised']);
        self::assertFalse($mapping['required_w9_adapter_contract']['runtime_or_cms_activation_allowed']);
        self::assertCount(4, $mapping['projection']);
        self::assertSame(
            ['type', 'axis_scores', 'result_page_sections', 'document'],
            array_column($mapping['projection'], 'pdf_payload_field'),
        );
        self::assertSame(
            ['sections', 'premium_teaser', 'result_only_authority.premium_full'],
            $mapping['projection'][2]['package_authority_buckets'],
        );
        self::assertSame(20, $mapping['projection'][3]['required_pdf_candidate_row_count']);
        self::assertFalse($mapping['projection'][3]['legacy_only_render_is_acceptable']);
        self::assertSame([[
            'row_id' => 'W1-RESULT-CORE-05-OFFER-CTA',
            'reason' => 'The PDF document contract does not consume commercial offer copy.',
            'required_qa_surface' => 'result_page_locked_upsell',
            'authority_field' => 'commercial_spec.variants[].cta_copy',
            'consumer_field' => 'offer_set.cta',
            'must_be_reviewed_separately_from_pdf' => true,
        ]], $mapping['non_pdf_candidate_coverage']);
        self::assertCount(13, $mapping['w9_assertions']);

        $assets = $this->readPackageJson('assets.json')['assets'];
        $fixtureSlots = $mapping['synthetic_slot_values'];
        unset($fixtureSlots['source']);
        $pdfGroups = [];
        $expectedReaderText = [];
        $expectedCardKeys = [];
        foreach ($assets as $asset) {
            if ($asset['asset_kind'] !== 'canonical_section_family') {
                continue;
            }

            $rendered = $this->renderTemplateValue($asset['content'], $fixtureSlots);
            self::assertIsArray($rendered);
            $groupKey = explode('.', $asset['section_key'], 2)[0];
            $pdfGroups[$groupKey] ??= [
                'title' => $mapping['card_shape_adapter']['grouping']['group_titles'][$groupKey],
                'cards' => [],
            ];
            $card = [
                'card_key' => $asset['row_id'],
                'title' => $rendered['title'],
                'description' => $rendered['summary_template'],
                'bullets' => $rendered['body_template'],
                'tips' => $rendered['reflection_prompts'],
                'tags' => [],
            ];
            self::assertNotSame('', trim($card['description']));
            self::assertNotEmpty($card['bullets']);
            self::assertNotEmpty($card['tips']);
            $pdfGroups[$groupKey]['cards'][] = $card;
            $expectedCardKeys[] = $asset['row_id'];
            $expectedReaderText = [
                ...$expectedReaderText,
                $card['title'],
                $card['description'],
                ...$card['bullets'],
                ...$card['tips'],
            ];
        }
        self::assertSame(
            ['traits', 'career', 'growth', 'relationships'],
            array_keys($pdfGroups),
        );
        self::assertCount(20, array_merge(...array_column($pdfGroups, 'cards')));
        $emittedCards = array_merge(...array_column($pdfGroups, 'cards'));
        self::assertSame($expectedCardKeys, array_column($emittedCards, 'card_key'));
        self::assertSame($expectedCardKeys, array_values(array_unique(array_column($emittedCards, 'card_key'))));
        $readerText = json_encode(
            $pdfGroups,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        foreach ($expectedReaderText as $expectedText) {
            self::assertStringContainsString($expectedText, $readerText);
        }

        $runtimeBuilderForbiddenKeys = (new ReflectionClass(MbtiPdfPayloadBuilder::class))
            ->getReflectionConstant('FORBIDDEN_KEYS')
            ?->getValue();
        self::assertIsArray($runtimeBuilderForbiddenKeys);
        self::assertSame(
            array_values(array_unique([
                ...self::FORBIDDEN_PAYLOAD_KEYS,
                ...$runtimeBuilderForbiddenKeys,
            ])),
            $mapping['excluded_fixture_fields'],
        );
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
        $offerCopyCheck = collect($review['checks'])->firstWhere('id', 'offer_copy');
        self::assertIsArray($offerCopyCheck);
        self::assertSame('pass', $offerCopyCheck['status']);
        self::assertStringContainsString('commercial_spec.variants[].cta_copy', $offerCopyCheck['evidence']);
        self::assertStringContainsString('offer_set.cta', $offerCopyCheck['evidence']);
        self::assertStringContainsString('no preview variant', $offerCopyCheck['evidence']);
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
     * @param  array<string, string>  $slots
     */
    private function renderTemplateValue(mixed $value, array $slots): mixed
    {
        if (is_string($value)) {
            $replacements = [];
            foreach ($slots as $slot => $replacement) {
                $replacements['{{'.$slot.'}}'] = $replacement;
            }

            return strtr($value, $replacements);
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(
            fn (mixed $nested): mixed => $this->renderTemplateValue($nested, $slots),
            $value,
        );
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
