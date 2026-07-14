<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV221Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-article-ia-21');
    }

    public function test_existing_surface_audit_is_exact_and_backend_authoritative(): void
    {
        $audit = $this->readJson('existing-surface-audit.json');

        $this->assertSame(['articles' => 9, 'topic_hubs' => 2, 'total' => 11], $audit['counts']);
        $this->assertCount(11, $audit['surfaces']);
        $this->assertCount(9, array_filter($audit['surfaces'], fn (array $surface): bool => $surface['surface_type'] === 'article'));
        $this->assertCount(2, array_filter($audit['surfaces'], fn (array $surface): bool => $surface['surface_type'] === 'topic_hub'));

        foreach ($audit['surfaces'] as $surface) {
            $this->assertSame('CMS/backend', $surface['authority']);
            $this->assertFalse($surface['publication_or_indexability_change']);
        }
    }

    public function test_matrix_locks_ten_batches_fifty_unique_themes_and_one_hundred_locale_candidates(): void
    {
        $matrix = $this->readJson('article-intent-matrix.json');

        $this->assertSame(['batches' => 10, 'themes' => 50, 'locale_drafts' => 100], $matrix['counts']);
        $this->assertCount(50, $matrix['themes']);
        $this->assertCount(50, array_unique(array_column($matrix['themes'], 'topic_id')));
        $this->assertCount(50, array_unique(array_column($matrix['themes'], 'unique_intent_key')));
        $this->assertCount(50, array_unique(array_column($matrix['themes'], 'locked_slug')));

        foreach (range(24, 33) as $batch) {
            $themes = array_filter($matrix['themes'], fn (array $theme): bool => $theme['batch'] === $batch);
            $this->assertCount(5, $themes, "Batch {$batch} must contain exactly five themes.");
        }
    }

    public function test_every_theme_has_complete_en_and_zh_cn_locked_metadata(): void
    {
        $matrix = $this->readJson('article-intent-matrix.json');
        $required = ['title_intent', 'primary_question', 'audience', 'user_task', 'keywords', 'search_intent', 'internal_link_targets', 'source_requirements', 'risk_boundary'];

        foreach ($matrix['themes'] as $theme) {
            $this->assertCount(2, $theme['locales']);
            $locales = array_column($theme['locales'], 'locale');
            sort($locales);
            $this->assertSame(['en', 'zh-CN'], $locales);

            foreach ($theme['locales'] as $locale) {
                $this->assertSame($theme['locked_slug'], $locale['slug']);
                $this->assertSame('draft_candidate_only', $locale['publication_state']);
                $this->assertSame('unchanged', $locale['indexability_state']);
                foreach ($required as $field) {
                    $this->assertNotEmpty($locale[$field], "{$theme['topic_id']}/{$locale['locale']} missing {$field}");
                }
            }
        }
    }

    public function test_evidence_channels_are_separate_and_gsc_is_explicitly_pending(): void
    {
        $evidence = $this->readJson('evidence-register.json');

        $this->assertSame('AVAILABLE_FROM_LOCKED_PR05_LEDGER', $evidence['academic_evidence']['status']);
        $this->assertSame('AVAILABLE_AS_TIME_BOUND_STRUCTURE_ONLY', $evidence['competitor_evidence']['status']);
        $this->assertSame('GSC_EVIDENCE_PENDING', $evidence['gsc_evidence']['status']);
        $this->assertFalse($evidence['gsc_evidence']['permitted_inference']);
    }

    public function test_package_contains_no_article_body_or_mutation_authority(): void
    {
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('PASS', $qa['status']);
        $this->assertSame(0, $qa['checks']['body_assets_generated']);
        $this->assertSame(0, $qa['checks']['cms_writes']);
        $this->assertSame(0, $qa['checks']['publication_or_indexability_changes']);
        $this->assertSame(0, $qa['checks']['trait_combination_matrices']);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
