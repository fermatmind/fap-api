<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Tests\TestCase;

final class EnneagramPublicAuthorityV213Type4FamilyTest extends TestCase
{
    private const PACKAGE = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/type-4-family-draft.json';

    private const QA_REPORT = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/qa-report.json';

    private const LEDGER_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07';

    private const FAMILY_IDENTITIES = [
        'core_type:type-4',
        'wing:4w3',
        'wing:4w5',
        'instinctual_subtype:type-4/self-preservation',
        'instinctual_subtype:type-4/social',
        'instinctual_subtype:type-4/one-to-one',
    ];

    public function test_package_contains_exactly_the_twelve_frozen_type_four_family_targets(): void
    {
        $package = $this->package();
        $expectedMaps = collect($this->maps()['page_maps'])
            ->filter(fn (array $map): bool => in_array($map['identity_key'], self::FAMILY_IDENTITIES, true))
            ->keyBy(fn (array $map): string => $this->key($map));
        $assets = collect($package['assets'])->keyBy(fn (array $asset): string => $this->key($asset));

        $this->assertSame(EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_SCHEMA_VERSION, $package['schema_version']);
        $this->assertSame('ENNEAGRAM-PUBLIC-AUTHORITY-V2-TYPE-4-FAMILY-13', $package['artifact']);
        $this->assertSame('enneagram', $package['framework']);
        $this->assertCount(12, $assets);
        $this->assertSame($expectedMaps->keys()->sort()->values()->all(), $assets->keys()->sort()->values()->all());
        $this->assertSame(['en' => 6, 'zh-CN' => 6], $assets->countBy('locale')->sortKeys()->all());
        $this->assertSame(['core_type' => 2, 'instinctual_subtype' => 6, 'wing' => 4], $assets->countBy('entity_type')->sortKeys()->all());

        foreach ($assets as $key => $asset) {
            $map = $expectedMaps->get($key);
            $this->assertIsArray($map, $key);
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                $this->assertSame($map[$field], $asset[$field], "{$key}.{$field}");
            }
            $this->assertSame($map['factual_claim_ids'], $asset['claim_ids'], "{$key}.claim_ids");
            $this->assertSame($map['factual_claim_ids'], $asset['visible_evidence']['claim_ids'], "{$key}.visible_evidence.claim_ids");
            foreach ($map['limitations'] as $limitation) {
                $this->assertContains($limitation, $asset['visible_evidence']['limitations'], "{$key}.limitations");
            }
        }
    }

    public function test_all_twelve_assets_have_zero_asset_specific_issues_under_the_pr08_gate(): void
    {
        $package = $this->package();
        $keys = collect($package['assets'])->map(fn (array $asset): string => $this->key($asset))->all();
        $result = app(EnneagramPublicAuthorityV2IntegrityGate::class)->validateEditorial($package, $this->registry(), $this->maps());
        $issues = collect($result['issues']);
        $packageIssues = $issues->filter(fn (array $issue): bool => in_array($issue['asset_key'], $keys, true));
        $globalCodes = $issues->whereNull('asset_key')->pluck('code')->unique()->values()->all();
        $deferredIssues = $issues->filter(fn (array $issue): bool => $issue['asset_key'] !== null && ! in_array($issue['asset_key'], $keys, true));

        $this->assertSame([], $packageIssues->values()->all(), json_encode($packageIssues->values()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(['target_count_invalid'], $globalCodes);
        $this->assertCount(104, $deferredIssues);
        $this->assertSame(['target_asset_missing'], $deferredIssues->pluck('code')->unique()->values()->all());
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_eligible']);
        foreach (['writes_committed', 'cms_write_attempted', 'database_mutation_attempted', 'indexability_mutation_attempted', 'sitemap_mutation_attempted', 'llms_mutation_attempted', 'search_submission_attempted', 'deploy_attempted'] as $field) {
            $this->assertFalse($result[$field], $field);
        }
    }

    public function test_locales_faqs_links_and_release_truth_are_independent_and_closed(): void
    {
        $package = $this->package();
        $assets = collect($package['assets']);
        $expectedReview = ['status' => 'pending_manual_review', 'reviewer' => null, 'reviewed_at' => null, 'human_review_completed' => false, 'review_source' => 'unassigned'];
        $expectedRelease = ['draft_only' => true, 'publish_eligible' => false, 'indexability_changed' => false, 'sitemap_changed' => false, 'llms_changed' => false];

        foreach ($assets as $asset) {
            $key = $this->key($asset);
            $this->assertSame('independent_original', $asset['authoring']['mode'], $key);
            $this->assertNull($asset['authoring']['source_locale'], $key);
            $this->assertGreaterThanOrEqual(3, count(array_unique($asset['authoring']['page_specific_signals'])), $key);
            $this->assertCount(3, $asset['faqs'], $key);
            $this->assertContains('counterexample', array_column($asset['sections'], 'kind'), $key);
            $this->assertGreaterThanOrEqual(4, count($asset['internal_links']), $key);
            $this->assertSame($expectedReview, $asset['review_truth'], $key);
            $this->assertSame($expectedRelease, $asset['release_truth'], $key);
        }
        foreach (self::FAMILY_IDENTITIES as $identity) {
            $pair = $assets->where('identity_key', $identity)->keyBy('locale');
            $this->assertCount(2, $pair, $identity);
            $this->assertNotSame($pair['en']['authoring']['outline'], $pair['zh-CN']['authoring']['outline'], $identity);
        }
        $questions = $assets->flatMap(fn (array $asset): array => array_column($asset['faqs'], 'question'));
        $answers = $assets->flatMap(fn (array $asset): array => array_column($asset['faqs'], 'answer'));
        $this->assertCount(36, $questions);
        $this->assertCount(36, $questions->map(fn (string $value): string => $this->normalize($value))->unique());
        $this->assertCount(36, $answers->map(fn (string $value): string => $this->normalize($value))->unique());
        $this->assertSame([false], array_values(array_unique($package['execution_boundaries'])));
    }

    public function test_core_pages_cover_type_four_identity_meaning_boundary_and_wing_dimensions(): void
    {
        $cores = collect($this->package()['assets'])->where('entity_type', 'core_type');
        $required = ['motivation_attention', 'behavior_motive', 'decision_feedback_conflict', 'strengths_tradeoffs', 'work_relationship_examples', 'wings_mistypes', 'counterexample', 'use_boundary'];

        $this->assertCount(2, $cores);
        foreach ($cores as $asset) {
            $key = $this->key($asset);
            foreach ($required as $kind) {
                $this->assertContains($kind, array_column($asset['sections'], 'kind'), "{$key}.{$kind}");
            }
            $text = json_encode([$asset['answer_first'], $asset['sections'], $asset['observation_exercise']], JSON_UNESCAPED_UNICODE) ?: '';
            $this->assertStringContainsString('4w3', $text, $key);
            $this->assertStringContainsString('4w5', $text, $key);
            $this->assertCount(7, $asset['internal_links'], $key);
        }
    }

    public function test_wing_pages_are_core_first_comparative_and_evidence_limited(): void
    {
        $wings = collect($this->package()['assets'])->where('entity_type', 'wing');
        $required = ['core_first_modifier', 'sibling_core_comparison', 'observable_situations', 'counterexample', 'alternative_explanations', 'wing_boundary'];

        $this->assertCount(4, $wings);
        foreach ($wings as $asset) {
            $key = $this->key($asset);
            foreach ($required as $kind) {
                $this->assertContains($kind, array_column($asset['sections'], 'kind'), "{$key}.{$kind}");
            }
            $this->assertContains(
                $asset['locale'] === 'en'
                    ? 'The systematic review found little research supporting wings; a wing remains an evidence-limited observation hypothesis.'
                    : '系统综述指出翼型支持研究很少（little research）；翼型只能作为证据有限的观察假设。',
                $asset['visible_evidence']['limitations'],
                $key,
            );
        }
    }

    public function test_subtype_pages_use_matched_comparisons_and_non_mechanistic_boundaries(): void
    {
        $subtypes = collect($this->package()['assets'])->where('entity_type', 'instinctual_subtype');
        $required = ['traditional_hypothesis', 'matched_comparison', 'instinct_boundary', 'countertype_variation', 'alternative_environment', 'counterexample', 'evidence_boundary'];

        $this->assertCount(6, $subtypes);
        foreach ($subtypes as $asset) {
            $key = $this->key($asset);
            foreach ($required as $kind) {
                $this->assertContains($kind, array_column($asset['sections'], 'kind'), "{$key}.{$kind}");
            }
            $boundary = collect($asset['sections'])->firstWhere('kind', 'instinct_boundary');
            $this->assertIsArray($boundary, $key);
            $this->assertMatchesRegularExpression(
                $asset['locale'] === 'en' ? '/not evidence about bodily mechanisms|rather than evidence about bodily mechanisms/i' : '/不描述身体机制/u',
                $boundary['body'],
                $key,
            );
        }

        $repaired = $subtypes->first(fn (array $asset): bool => $this->key($asset) === 'en|instinctual_subtype:type-4/social');
        $this->assertIsArray($repaired);
        $evidenceBoundary = collect($repaired['sections'])->firstWhere('kind', 'evidence_boundary');
        $this->assertIsArray($evidenceBoundary);
        $this->assertSame(
            'Evidence from one subtype instrument cannot make belonging patterns universal across groups or cultures. This page cannot predict popularity, inclusion, loyalty, social skill, or group fit.',
            $evidenceBoundary['body'],
        );
    }

    public function test_positive_review_release_and_mechanism_mutations_fail_closed(): void
    {
        $package = $this->package();
        $package['assets'][0]['review_truth']['human_review_completed'] = true;
        $package['assets'][2]['release_truth']['publish_eligible'] = true;
        $package['assets'][6]['sections'][2]['body'] = 'Self-preservation is a biological system and a neurological category that determines behavior.';

        $result = app(EnneagramPublicAuthorityV2IntegrityGate::class)->validateEditorial($package, $this->registry(), $this->maps());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertContains('manual_review_truth_invalid', $codes);
        $this->assertContains('release_truth_invalid', $codes);
        $this->assertContains('unsupported_center_system_claim', $codes);
        $this->assertFalse($result['human_review_completed']);
        $this->assertFalse($result['publish_eligible']);
        $this->assertFalse($result['writes_committed']);
    }

    public function test_qa_report_records_codex_native_review_and_zero_writes(): void
    {
        $report = $this->readJson(self::QA_REPORT);
        $this->assertSame('codex_native_content_generation', $report['generation_mode']);
        $this->assertSame([], $report['external_model_sessions']);
        $this->assertSame('failed_repaired', $report['raw_draft_audit']['status']);
        $this->assertSame(20, $report['raw_draft_audit']['asset_specific_issue_count']);
        $this->assertSame(20, array_sum($report['raw_draft_audit']['issues']));
        $this->assertSame(0, $report['raw_draft_audit']['repair_rounds'][0]['remaining_asset_specific_issue_count']);
        $this->assertSame(0, $report['raw_draft_audit']['critical_contract_violations_after_repair']);
        $this->assertSame('pass', $report['aggregate_duplicate_repair']['status']);
        $this->assertSame('854afe6c6de5ffd72643013ad032c2104076a721def982d2814ae26dc1afdb84', $report['aggregate_duplicate_repair']['asset_sha256']);
        $this->assertSame(0, $report['aggregate_duplicate_repair']['aggregate_duplicate_count_after_repair']);
        $this->assertFalse($report['aggregate_duplicate_repair']['human_review_completed']);
        $this->assertSame('pass_for_manual_review_handoff', $report['final_qa']['status']);
        $this->assertSame(12, $report['final_qa']['asset_count']);
        $this->assertSame(0, $report['final_qa']['asset_specific_issue_count']);
        $this->assertSame(104, $report['final_qa']['deferred_target_count']);
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
