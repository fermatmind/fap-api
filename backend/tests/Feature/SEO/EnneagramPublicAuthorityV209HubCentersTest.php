<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Tests\TestCase;

final class EnneagramPublicAuthorityV209HubCentersTest extends TestCase
{
    private const PACKAGE = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/hub-centers-draft.json';

    private const QA_REPORT = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/qa-report.json';

    private const LEDGER_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07';

    public function test_package_contains_exactly_the_eight_frozen_hub_and_center_targets(): void
    {
        $package = $this->package();
        $expectedMaps = collect($this->maps()['page_maps'])
            ->filter(fn (array $map): bool => in_array($map['entity_type'], ['hub', 'center'], true))
            ->keyBy(fn (array $map): string => $this->key($map));
        $assets = collect($package['assets'])->keyBy(fn (array $asset): string => $this->key($asset));

        $this->assertSame(EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_SCHEMA_VERSION, $package['schema_version']);
        $this->assertSame('ENNEAGRAM-PUBLIC-AUTHORITY-V2-HUB-CENTERS-09', $package['artifact']);
        $this->assertSame('enneagram', $package['framework']);
        $this->assertCount(8, $assets);
        $this->assertSame($expectedMaps->keys()->sort()->values()->all(), $assets->keys()->sort()->values()->all());
        $this->assertSame(['en' => 4, 'zh-CN' => 4], $assets->countBy('locale')->sortKeys()->all());
        $this->assertSame(['center' => 6, 'hub' => 2], $assets->countBy('entity_type')->sortKeys()->all());

        foreach ($assets as $key => $asset) {
            $map = $expectedMaps->get($key);
            $this->assertIsArray($map, $key);
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                $this->assertSame($map[$field], $asset[$field], "{$key}.{$field}");
            }
            $this->assertSame($map['factual_claim_ids'], $asset['claim_ids'], "{$key}.claim_ids");
            $this->assertSame($map['factual_claim_ids'], $asset['visible_evidence']['claim_ids'], "{$key}.visible_evidence.claim_ids");
            $this->assertContains($map['limitations'][0], $asset['visible_evidence']['limitations'], "{$key}.visible_evidence.limitations");
            $this->assertTrue($asset['visible_evidence']['visible'], $key);
        }
    }

    public function test_all_eight_assets_have_zero_asset_specific_issues_under_the_pr08_gate(): void
    {
        $package = $this->package();
        $keys = collect($package['assets'])->map(fn (array $asset): string => $this->key($asset))->all();

        $result = app(EnneagramPublicAuthorityV2IntegrityGate::class)
            ->validateEditorial($package, $this->registry(), $this->maps());
        $issues = collect($result['issues']);
        $packageIssues = $issues->filter(fn (array $issue): bool => in_array($issue['asset_key'], $keys, true));
        $globalCodes = $issues->whereNull('asset_key')->pluck('code')->unique()->values()->all();
        $deferredIssues = $issues->filter(fn (array $issue): bool => $issue['asset_key'] !== null
            && ! in_array($issue['asset_key'], $keys, true));

        $this->assertSame([], $packageIssues->values()->all(), json_encode($packageIssues->values()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(['target_count_invalid'], $globalCodes);
        $this->assertCount(108, $deferredIssues);
        $this->assertSame(['target_asset_missing'], $deferredIssues->pluck('code')->unique()->values()->all());
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_eligible']);
        foreach (['writes_committed', 'cms_write_attempted', 'database_mutation_attempted', 'indexability_mutation_attempted', 'sitemap_mutation_attempted', 'llms_mutation_attempted', 'search_submission_attempted', 'deploy_attempted'] as $field) {
            $this->assertFalse($result[$field], $field);
        }
    }

    public function test_bilingual_drafts_are_independent_specific_and_release_closed(): void
    {
        $package = $this->package();
        $assets = collect($package['assets']);
        $expectedReview = [
            'status' => 'pending_manual_review',
            'reviewer' => null,
            'reviewed_at' => null,
            'human_review_completed' => false,
            'review_source' => 'unassigned',
        ];
        $expectedRelease = [
            'draft_only' => true,
            'publish_eligible' => false,
            'indexability_changed' => false,
            'sitemap_changed' => false,
            'llms_changed' => false,
        ];

        foreach ($assets as $asset) {
            $key = $this->key($asset);
            $this->assertSame('independent_original', $asset['authoring']['mode'], $key);
            $this->assertNull($asset['authoring']['source_locale'], $key);
            $this->assertGreaterThanOrEqual(3, count(array_unique($asset['authoring']['page_specific_signals'])), $key);
            $this->assertCount(3, $asset['faqs'], $key);
            $this->assertContains('counterexample', array_column($asset['sections'], 'kind'), $key);
            $this->assertGreaterThanOrEqual(30, mb_strlen($asset['observation_exercise']['alternative_explanation']), $key);
            $this->assertSame($expectedReview, $asset['review_truth'], $key);
            $this->assertSame($expectedRelease, $asset['release_truth'], $key);
        }

        foreach (['hub:enneagram', 'center:gut', 'center:heart', 'center:head'] as $identity) {
            $pair = $assets->where('identity_key', $identity)->keyBy('locale');
            $this->assertCount(2, $pair, $identity);
            $this->assertNotSame($pair['en']['authoring']['outline'], $pair['zh-CN']['authoring']['outline'], $identity);
        }

        $faqQuestions = $assets->flatMap(fn (array $asset): array => array_column($asset['faqs'], 'question'));
        $faqAnswers = $assets->flatMap(fn (array $asset): array => array_column($asset['faqs'], 'answer'));
        $this->assertCount(24, $faqQuestions);
        $this->assertCount(24, $faqQuestions->map(fn (string $value): string => $this->normalize($value))->unique());
        $this->assertCount(24, $faqAnswers->map(fn (string $value): string => $this->normalize($value))->unique());
        $this->assertSame([false], array_values(array_unique($package['execution_boundaries'])));
    }

    public function test_center_drafts_explain_internal_differences_without_medical_or_absolute_categories(): void
    {
        $centers = collect($this->package()['assets'])->where('entity_type', 'center');
        $expectedGroups = [
            'en|center:gut' => ['Eight', 'Nine', 'One'],
            'en|center:heart' => ['Two', 'Three', 'Four'],
            'en|center:head' => ['Five', 'Six', 'Seven'],
            'zh-CN|center:gut' => ['八号', '九号', '一号'],
            'zh-CN|center:heart' => ['二号', '三号', '四号'],
            'zh-CN|center:head' => ['五号', '六号', '七号'],
        ];

        $this->assertCount(6, $centers);
        foreach ($centers as $asset) {
            $key = $this->key($asset);
            $sections = collect($asset['sections']);
            $visibleText = implode("\n", [
                $asset['answer_first'],
                ...$sections->pluck('body')->all(),
                ...$asset['visible_evidence']['limitations'],
            ]);
            $this->assertContains('within_center_difference', $sections->pluck('kind')->all(), $key);
            foreach ($expectedGroups[$key] as $label) {
                $this->assertStringContainsString($label, $visibleText, $key);
            }
            $this->assertMatchesRegularExpression(
                $asset['locale'] === 'en'
                    ? '/(?:does not|do not|not a|not an)/i'
                    : '/(?:不能|不是|并不|不表示)/u',
                $visibleText,
                $key,
            );
        }
    }

    public function test_positive_center_science_and_release_mutations_still_fail_closed(): void
    {
        $package = $this->package();
        $package['assets'][1]['sections'][3]['body'] = 'The gut center is a biological system and a neurological category that determines behavior in every decision.';
        $package['assets'][2]['review_truth']['human_review_completed'] = true;
        $package['assets'][3]['release_truth']['publish_eligible'] = true;

        $result = app(EnneagramPublicAuthorityV2IntegrityGate::class)
            ->validateEditorial($package, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertContains('unsupported_center_system_claim', $codes);
        $this->assertContains('manual_review_truth_invalid', $codes);
        $this->assertContains('release_truth_invalid', $codes);
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['publish_eligible']);
        $this->assertFalse($result['writes_committed']);
    }

    public function test_qa_report_preserves_raw_failure_repair_and_zero_write_evidence(): void
    {
        $report = $this->readJson(self::QA_REPORT);

        $this->assertSame('codex_native_content_generation', $report['generation_mode']);
        $this->assertSame([], $report['external_model_sessions']);
        $this->assertSame('failed_repaired', $report['raw_draft_audit']['status']);
        $this->assertSame(11, $report['raw_draft_audit']['asset_specific_issue_count']);
        $this->assertSame(11, array_sum($report['raw_draft_audit']['issues']));
        $this->assertSame(0, $report['raw_draft_audit']['critical_contract_violations_after_repair']);
        $this->assertSame('pass_for_manual_review_handoff', $report['final_qa']['status']);
        $this->assertSame(8, $report['final_qa']['asset_count']);
        $this->assertSame(0, $report['final_qa']['asset_specific_issue_count']);
        $this->assertSame(108, $report['final_qa']['deferred_target_count']);
        $this->assertSame('pending_manual_review', $report['final_qa']['manual_review_state']);
        $this->assertFalse($report['final_qa']['human_review_completed']);
        $this->assertFalse($report['final_qa']['publish_eligible']);
        $this->assertSame([false], array_values(array_unique($report['execution_boundaries'])));
    }

    /** @param array<string, mixed> $asset */
    private function key(array $asset): string
    {
        return $asset['locale'].'|'.$asset['identity_key'];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    /** @return array<string, mixed> */
    private function package(): array
    {
        return $this->readJson(self::PACKAGE);
    }

    /** @return array<string, mixed> */
    private function registry(): array
    {
        return $this->readJson(self::LEDGER_DIR.'/source-registry.json');
    }

    /** @return array<string, mixed> */
    private function maps(): array
    {
        return $this->readJson(self::LEDGER_DIR.'/page-claim-maps.json');
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(base_path($path)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
