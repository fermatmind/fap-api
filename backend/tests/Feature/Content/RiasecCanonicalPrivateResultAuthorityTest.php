<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\RiasecPrivateResultCompileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RiasecCanonicalPrivateResultAuthorityTest extends TestCase
{
    public function test_manifest_declares_one_editable_chinese_private_result_authority(): void
    {
        $compiled = app(RiasecPrivateResultCompileService::class)->compile();
        $manifest = $compiled['manifest'];

        $this->assertSame(RiasecPrivateResultCompileService::AUTHORITY_ID, $manifest['authority_id']);
        $this->assertSame('backend/content_assets/riasec', $manifest['authority_root']);
        $this->assertSame(1, $manifest['editable_authority_count']);
        $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], $manifest['coverage']['dimensions']);
        $this->assertSame(['faq', 'technical_note', 'pdf', 'print', 'history', 'compare', 'share', 'lifecycle'], $manifest['coverage']['secondary_surfaces']);
        $this->assertFalse($manifest['generated']['manual_edit_allowed']);
    }

    public function test_activity_authority_contains_only_reachable_reader_examples(): void
    {
        $assets = app(RiasecPrivateResultCompileService::class)->compile()['payload']['assets'];
        $activities = $assets['activity_task_examples_v1.zh-CN.jsonl'];
        $examples = $assets['occupation_examples_boundary_v1.zh-CN.jsonl'];
        $this->assertCount(18, $activities);
        $this->assertCount(12, $examples);
        $this->assertCount(18, array_unique(array_column($activities, 'activity_key')));
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $dimension) {
            $this->assertCount(3, array_filter($activities, fn ($row) => $row['dimensions'] === [$dimension]));
            $this->assertCount(2, array_filter($examples, fn ($row) => $row['primary_activity_dimension'] === $dimension));
        }
        foreach ($activities as $row) {
            $this->assertSame('content_example_not_registry_match', $row['source_status']);
            $this->assertStringNotContainsString('｜', $row['activity_label']);
            $this->assertStringNotContainsString('先用 15 分钟完成', $row['low_risk_validation']);
            $this->assertArrayNotHasKey('commercial_internal_notes', $row);
        }
    }

    public function test_no_new_private_result_source_is_outside_manifest_whitelist(): void
    {
        $root = base_path('content_assets/riasec');
        $allowed = array_fill_keys(array_keys(RiasecPrivateResultCompileService::SOURCE_CONTRACT), true);
        foreach (File::files($root) as $file) {
            $name = $file->getFilename();
            if (! str_contains($name, 'zh-CN')) {
                continue;
            }
            $this->assertArrayHasKey($name, $allowed, "Chinese private-result source is not manifest-bound: {$name}");
        }
    }

    public function test_retired_competing_assets_and_control_plane_cannot_return(): void
    {
        foreach ([
            'content_assets/riasec/result_page_v2',
            'content_assets/riasec/qa',
            'tests/Fixtures/Riasec',
            'config/riasec_result_page_v2.php',
            'app/Console/Commands/RiasecResultPageAssetAgentAuditCommand.php',
            'app/Console/Commands/RiasecResultPageOpsRunnerCommand.php',
            'app/Console/Commands/RiasecResultPageV2ProductionImportCommand.php',
            'app/Console/Commands/RiasecResultPageV2ProductionSmokeCommand.php',
            'app/Services/Riasec/AssetAgent/RiasecResultPageAssetAgent.php',
            'app/Services/Riasec/Ops/RiasecResultPageOpsAgentRunOrchestrator.php',
            'app/Services/Riasec/RiasecResultPageProductionRolloutGate.php',
            'app/Services/Riasec/RiasecResultPageV2ProductionImportExecutor.php',
            'app/Services/Riasec/RiasecResultPageV2ProductionSmokeVerifier.php',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "Retired RIASEC authority/control-plane path returned: {$path}");
            $this->assertDirectoryDoesNotExist(base_path($path), "Retired RIASEC authority/control-plane path returned: {$path}");
        }
    }

    public function test_report_composer_has_only_the_canonical_projection_selector(): void
    {
        $contents = (string) file_get_contents(base_path('app/Services/Report/RiasecReportComposer.php'));

        $this->assertStringNotContainsString('result_page_v2', $contents);
        $this->assertStringNotContainsString('RiasecResultPageProductionRollout', $contents);
        $this->assertSame(1, substr_count($contents, "'riasec_private_result_authority'"));
    }

    public function test_runtime_php_contains_no_editable_chinese_private_result_body(): void
    {
        foreach ([
            'app/Services/Riasec/RiasecActivityExplorerService.php',
            'app/Services/Riasec/RiasecDeepCopySlotRegistry.php',
            'app/Services/Riasec/RiasecPublicProjectionService.php',
            'app/Services/Riasec/RiasecTechnicalNoteService.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));
            $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', $contents, "Editable Chinese result body remains in PHP: {$path}");
        }
    }
}
