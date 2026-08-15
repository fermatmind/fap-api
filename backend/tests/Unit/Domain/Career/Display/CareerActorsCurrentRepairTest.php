<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerActorsCurrentRepair;
use App\Domain\Career\Display\CareerActorsCurrentRepairFailure;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CareerActorsCurrentRepairTest extends TestCase
{
    public function test_it_locks_sources_and_deterministically_builds_real_bilingual_components(): void
    {
        $package = $this->repair()->buildTargetPackage($this->backendRoot());

        self::assertSame(CareerActorsCurrentRepair::SOURCE_SHA256, $package['hashes']['source_sha256']);
        self::assertSame(CareerActorsCurrentRepair::WORKBUDDY_SHA256, $package['hashes']['workbuddy_sha256']);
        self::assertSame(
            CareerActorsCurrentRepair::SOURCE_SHA256,
            hash_file('sha256', $this->backendRoot().'/'.CareerActorsCurrentRepair::SOURCE_RELATIVE_PATH),
        );
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $package['hashes']['page_sha256']);
        foreach (['en', 'zh'] as $locale) {
            $page = $package['page'][$locale];
            $encodedPage = json_encode($page, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('sections', $page);
            self::assertStringNotContainsString('pending_reviewed_locale_content', $encodedPage);
            self::assertStringNotContainsString('component_order_contract', $encodedPage);
            foreach (CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER as $component) {
                self::assertArrayHasKey($component, $page);
                self::assertNotSame([], $page[$component]);
            }
            self::assertFalse($page['market_signal_card']['current_market_claim_allowed']);
            self::assertSame('expired_snapshot', $page['market_signal_card']['signal_status']);
            self::assertSame('overdue', $page['review_validity_card']['review_status']);
            self::assertTrue($page['review_validity_card']['refresh_required']);
            self::assertSame('actors', $page['primary_cta']['subject_key']);
            self::assertSame('actors', $page['final_cta']['subject_key']);
            self::assertSame('start_riasec_test', $page['primary_cta']['target_action']);
            self::assertNotEmpty($page['secondary_cta']['hrefs']);
            self::assertNotEmpty($page['related_next_pages']['secondary_tests']);
            $sections = [];
            foreach ($package['source']['page'][$locale]['sections'] as $section) {
                $sections[$section['id']] = $section;
            }
            self::assertSame($sections['fermat_quick_fit'], $page['fermat_decision_card']);
            self::assertSame($sections['definition'], $page['definition_block']);
            self::assertSame($sections['faq'], $page['faq_block']);
        }
        self::assertSame('china_snapshot', $package['page']['zh']['career_snapshot_primary_locale']['id']);
        self::assertSame('us_bls_snapshot', $package['page']['zh']['career_snapshot_secondary_locale']['id']);
        self::assertSame('us_bls_snapshot', $package['page']['en']['career_snapshot_primary_locale']['id']);
        self::assertSame('china_reference', $package['page']['en']['career_snapshot_secondary_locale']['id']);
    }

    public function test_it_uses_exact_workbuddy_blocks_without_numeric_rating_residue(): void
    {
        $package = $this->repair()->buildTargetPackage($this->backendRoot());

        foreach (['en' => 'en', 'zh' => 'zh-CN'] as $pageLocale => $packageLocale) {
            self::assertSame(
                $package['workbuddy'][$packageLocale]['career_ai_description_block'],
                $package['page'][$pageLocale]['career_ai_description_block'],
            );
            self::assertSame(
                $package['workbuddy'][$packageLocale]['career_path_block'],
                $package['page'][$pageLocale]['career_path_block'],
            );
            self::assertDoesNotMatchRegularExpression(
                '/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u',
                json_encode($package['page'][$pageLocale]['career_ai_description_block'], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_it_classifies_only_exact_initial_and_applied_states(): void
    {
        $repair = $this->repair();
        $package = $repair->buildTargetPackage($this->backendRoot());
        $metadata = ['existing' => 'preserved'];
        $targetMetadata = $this->invoke($repair, 'targetMetadata', [$metadata, $package]);
        $initialPages = $package['source']['page'];
        foreach (['en' => 'en', 'zh' => 'zh-CN'] as $pageLocale => $packageLocale) {
            $initialPages[$pageLocale] = array_merge(
                $initialPages[$pageLocale],
                $package['workbuddy'][$packageLocale],
            );
        }
        $target = [
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => $package['page'],
            'metadata_json' => $targetMetadata,
        ];
        $initial = [
            'id' => 'row-id',
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => $initialPages,
            'metadata_json' => $metadata,
            'updated_at' => '2026-08-15 00:00:00.000000',
        ];

        self::assertSame('initial', $this->invoke($repair, 'classifyState', [$initial, $target, $package]));
        $applied = array_merge($initial, $target);
        self::assertSame('applied', $this->invoke($repair, 'classifyState', [$applied, $target, $package]));

        $initial['page_payload_json']['en']['sections'][0]['heading'] = 'drift';
        $this->expectException(CareerActorsCurrentRepairFailure::class);
        $this->expectExceptionMessage('ACTORS_INITIAL_STATE_INVALID');
        $this->invoke($repair, 'classifyState', [$initial, $target, $package]);
    }

    public function test_repair_orchestration_keeps_cache_and_database_compensation_in_one_boundary(): void
    {
        $source = file_get_contents($this->backendRoot().'/app/Domain/Career/Display/CareerActorsCurrentRepair.php');

        self::assertIsString($source);
        self::assertStringContainsString('preparePublishedJobDetailReplacement', $source);
        self::assertStringContainsString('activatePreparedJobDetailPayloadsForExposure', $source);
        self::assertStringContainsString('restorePreparedJobDetailExposurePointers', $source);
        self::assertStringContainsString('forgetPreparedJobDetailCandidates', $source);
        self::assertStringContainsString('restoreDatabase($before, $target)', $source);
        self::assertStringContainsString("'ACTORS_REPAIR_COMPENSATION_FAILED'", $source);
        self::assertStringNotContainsString('Occupation::', $source);
        self::assertStringNotContainsString('Generation::', $source);
    }

    public function test_existing_deploy_workflow_serializes_repair_before_read_only_export(): void
    {
        $repositoryRoot = dirname($this->backendRoot());
        $workflow = file_get_contents($repositoryRoot.'/.github/workflows/deploy.yml');
        $workflowFiles = glob($repositoryRoot.'/.github/workflows/*.{yml,yaml}', GLOB_BRACE);

        self::assertIsString($workflow);
        self::assertIsArray($workflowFiles);
        self::assertSame(4, count($workflowFiles));
        self::assertStringContainsString('career-actors-current-repair:', $workflow);
        self::assertStringContainsString(
            'needs: [policy, production, career-actors-current-repair]',
            $workflow,
        );
        self::assertLessThan(
            strpos($workflow, 'career-current-export:'),
            strpos($workflow, 'career-actors-current-repair:'),
        );
        self::assertStringNotContainsString('workflow_dispatch:', $workflow);
        self::assertStringNotContainsString('Occupation::', $workflow);
    }

    private function repair(): CareerActorsCurrentRepair
    {
        return (new ReflectionClass(CareerActorsCurrentRepair::class))->newInstanceWithoutConstructor();
    }

    private function backendRoot(): string
    {
        return dirname(__DIR__, 5);
    }

    private function invoke(CareerActorsCurrentRepair $repair, string $method, array $arguments): mixed
    {
        return (new ReflectionClass($repair))->getMethod($method)->invokeArgs($repair, $arguments);
    }
}
