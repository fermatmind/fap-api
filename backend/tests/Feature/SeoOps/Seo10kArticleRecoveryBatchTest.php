<?php

declare(strict_types=1);

namespace Tests\Feature\SeoOps;

use App\Services\SeoOps\ArticleRecoveryBatchPlanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Seo10kArticleRecoveryBatchTest extends TestCase
{
    private const EVIDENCE_SHA256 = '69eb07235831602faea7241b965c44561aeae68fe6e6e141d3a8e87d7d0fff03';

    private string $evidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evidencePath = base_path(
            'content_packs/seo/SEO-10K-ARTICLE-RECOVERY-BATCH-01/live-gsc-evidence.v1.json'
        );
    }

    public function test_it_builds_the_exact_five_target_zero_write_review_package_deterministically(): void
    {
        self::assertSame(self::EVIDENCE_SHA256, hash_file('sha256', $this->evidencePath));

        $planner = app(ArticleRecoveryBatchPlanner::class);
        $package = $planner->plan($this->evidencePath, self::EVIDENCE_SHA256);

        self::assertFalse($package['ok']);
        self::assertTrue($package['dry_run']);
        self::assertFalse($package['would_write']);
        self::assertTrue($package['candidate_package_built']);
        self::assertFalse($package['approval_eligible']);
        self::assertSame('blocked_formal_gsc_readmodel_gate', $package['status']);
        self::assertSame(['formal_gsc_readmodel_gate_not_passed'], $package['issues']);
        self::assertSame('blocked_formal_gsc_readmodel_gate', data_get($package, 'manual_review_gate.status'));
        self::assertSame(5, data_get($package, 'selection.target_count'));
        self::assertSame(0, data_get($package, 'selection.new_urls'));
        self::assertSame(0, data_get($package, 'query_owner.conflict_count'));
        self::assertSame(167, data_get($package, 'query_owner.retained_query_count'));
        self::assertSame(1, data_get($package, 'query_owner.suppressed_target_count'));
        self::assertFalse(data_get($package, 'query_owner.raw_query_persisted'));
        self::assertSame(
            'f893df6f17ab964a04c5fe371627baedeb4cc4e42507ce77538d87d7f3bfa9ce',
            $package['target_set_sha256']
        );
        self::assertSame(
            'a3f09f3616bdccb079fb96912b2608a3e62fae6f5a0730a9800d2b375a540dcb',
            data_get($package, 'source_evidence.page_cohort_artifact_sha256')
        );
        self::assertSame('sc-domain:fermatmind.com', data_get($package, 'source_evidence.property'));
        self::assertSame('web', data_get($package, 'source_evidence.search_type'));
        self::assertSame(
            '057f1e88800b0fea500d0354092318a4461028ebc7c39659428f83129fa399c1',
            $package['package_sha256']
        );
        self::assertSame(
            $planner->prettyJson($package),
            File::get(dirname($this->evidencePath).'/seo-10k-article-recovery-batch-01.dry-run.json')
        );
        self::assertSame('blocked', data_get($package, 'formal_readmodel_gate.status'));
        self::assertSame(0, data_get($package, 'formal_readmodel_gate.actual_eligible_article_cohort_coverage_count'));
        self::assertSame(['D1', 'D7', 'D14', 'D28'], data_get($package, 'observation_contract.windows'));
        self::assertFalse(data_get($package, 'observation_contract.automatic_second_batch_allowed'));
        self::assertSame('D28_review_completed', data_get($package, 'observation_contract.second_batch_locked_until'));
        self::assertSame(
            [
                'https://fermatmind.com/en/articles/what-is-riasec-holland-code-career-interest-test',
                'https://fermatmind.com/zh/articles/college-major-choice-holland-mbti-career-test',
                'https://fermatmind.com/zh/articles/how-personality-shapes-attitude-toward-ai',
                'https://fermatmind.com/en/articles/mbti-personality-test-science-vs-pseudoscience',
                'https://fermatmind.com/zh/articles/iq-test-tool-guide',
            ],
            array_column($package['targets'], 'canonical_url')
        );

        foreach ($package['targets'] as $target) {
            self::assertTrue($target['url_lock']);
            self::assertFalse($target['new_url']);
            self::assertSame('pending', $target['review_status']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $target['review_target_sha256']);
            self::assertNotEmpty(data_get($target, 'claim_boundary.required_disclaimer'));
            self::assertGreaterThanOrEqual(2, count($target['source_refs']));
        }

        foreach ($package['negative_guarantees'] as $allowed) {
            self::assertFalse($allowed);
        }

        $artifactDir = storage_path('framework/testing/seo-10k-article-recovery-'.bin2hex(random_bytes(4)));
        try {
            $options = [
                '--evidence' => $this->evidencePath,
                '--confirm-evidence-sha256' => self::EVIDENCE_SHA256,
                '--artifact-dir' => $artifactDir,
                '--json' => true,
            ];

            self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', $options));
            $artifactPath = $artifactDir.'/seo-10k-article-recovery-batch-01.dry-run.json';
            self::assertFileExists($artifactPath);
            self::assertSame($planner->prettyJson($package), File::get($artifactPath));

            self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', $options));
            $second = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse(data_get($second, 'artifact.changed'));
            self::assertFalse(data_get($second, 'artifact.business_write'));
            self::assertSame(hash_file('sha256', $artifactPath), data_get($second, 'artifact.sha256'));
        } finally {
            File::deleteDirectory($artifactDir);
        }
    }

    public function test_it_fails_closed_on_evidence_sha_drift_without_creating_an_artifact(): void
    {
        $artifactDir = storage_path('framework/testing/seo-10k-article-recovery-sha-'.bin2hex(random_bytes(4)));

        try {
            self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', [
                '--evidence' => $this->evidencePath,
                '--confirm-evidence-sha256' => str_repeat('0', 64),
                '--artifact-dir' => $artifactDir,
                '--json' => true,
            ]));

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($output['ok']);
            self::assertSame(['evidence_sha_not_locked_to_batch'], $output['issues']);
            self::assertNull($output['artifact']);
            self::assertDirectoryDoesNotExist($artifactDir);
        } finally {
            File::deleteDirectory($artifactDir);
        }
    }

    public function test_artifact_errors_do_not_mutate_the_hashed_planner_package(): void
    {
        $expected = app(ArticleRecoveryBatchPlanner::class)->plan(
            $this->evidencePath,
            self::EVIDENCE_SHA256
        );

        self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', [
            '--evidence' => $this->evidencePath,
            '--confirm-evidence-sha256' => self::EVIDENCE_SHA256,
            '--json' => true,
        ]));

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected['package_sha256'], $output['package_sha256']);
        self::assertSame($expected['status'], $output['status']);
        self::assertSame($expected['issues'], $output['issues']);
        self::assertFalse(data_get($output, 'command.ok'));
        self::assertSame(['artifact_dir_required'], data_get($output, 'command.issues'));
        self::assertSame('artifact_dir_required', data_get($output, 'artifact.issue'));
    }

    public function test_it_rejects_symlinked_artifact_directories_and_destinations(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $realDirectory = storage_path('framework/testing/seo-10k-real-'.$suffix);
        $linkedDirectory = storage_path('framework/testing/seo-10k-link-'.$suffix);
        $artifactDirectory = storage_path('framework/testing/seo-10k-destination-'.$suffix);
        $victimPath = storage_path('framework/testing/seo-10k-victim-'.$suffix.'.json');
        $artifactPath = $artifactDirectory.'/seo-10k-article-recovery-batch-01.dry-run.json';

        try {
            File::ensureDirectoryExists($realDirectory);
            self::assertTrue(symlink($realDirectory, $linkedDirectory));
            self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', [
                '--evidence' => $this->evidencePath,
                '--confirm-evidence-sha256' => self::EVIDENCE_SHA256,
                '--artifact-dir' => $linkedDirectory,
                '--json' => true,
            ]));
            $directoryOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('artifact_dir_unsafe', data_get($directoryOutput, 'artifact.issue'));

            File::ensureDirectoryExists($artifactDirectory);
            File::put($victimPath, "must remain unchanged\n");
            self::assertTrue(symlink($victimPath, $artifactPath));
            self::assertSame(1, Artisan::call('seo-ops:article-recovery-batch', [
                '--evidence' => $this->evidencePath,
                '--confirm-evidence-sha256' => self::EVIDENCE_SHA256,
                '--artifact-dir' => $artifactDirectory,
                '--json' => true,
            ]));
            $destinationOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('artifact_destination_unsafe', data_get($destinationOutput, 'artifact.issue'));
            self::assertSame("must remain unchanged\n", File::get($victimPath));
        } finally {
            File::delete($artifactPath);
            File::delete($linkedDirectory);
            File::delete($victimPath);
            File::deleteDirectory($artifactDirectory);
            File::deleteDirectory($realDirectory);
        }
    }

    public function test_it_fails_closed_when_the_pre_sanitization_owner_check_reports_a_conflict(): void
    {
        [$directory, $evidence, $queryArtifact] = $this->mutableFixture();

        try {
            $queryArtifact['cross_target_owner_conflict_check']['conflict_count'] = 1;
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);

            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);

            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');
            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('query_owner_conflict_present', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_scope_claim_and_raw_query_drift(): void
    {
        [$directory, $evidence, $queryArtifact] = $this->mutableFixture();

        try {
            $evidence['targets'][] = $evidence['targets'][0];
            $evidence['targets'][0]['source_refs'] = [];
            $evidence['targets'][0]['claim_boundary']['required_disclaimer'] = '';
            $queryArtifact['query'] = 'must never be persisted';
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);
            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);

            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');
            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('target_count_must_equal_five', $package['issues']);
            self::assertContains(
                'source_refs_incomplete:'.$evidence['targets'][0]['canonical_url'],
                $package['issues']
            );
            self::assertContains(
                'claim_boundary_incomplete:'.$evidence['targets'][0]['canonical_url'],
                $package['issues']
            );
            self::assertContains('forbidden_raw_or_private_field_present', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_fails_closed_when_source_metadata_attests_raw_query_persistence(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $evidence['gsc']['query_summary_artifact']['raw_query_persisted'] = true;
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('raw_query_persistence_attested', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_unkeyed_query_digests_and_keeps_only_counts_in_git(): void
    {
        [$directory, $evidence, $queryArtifact] = $this->mutableFixture();

        try {
            self::assertSame('counts_only_no_query_text_or_unkeyed_digest', $queryArtifact['privacy_model']);
            self::assertFalse($queryArtifact['unkeyed_query_digest_persisted']);
            self::assertArrayNotHasKey('targets', $queryArtifact);

            $queryArtifact['unkeyed_query_digest_persisted'] = true;
            $queryArtifact['target_summaries'][$evidence['targets'][0]['canonical_url']]['digests'] = [
                hash('sha256', 'predictable query'),
            ];
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);
            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('unkeyed_query_digest_persistence_attested', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_requires_nonnegative_integer_query_counts_that_conserve_raw_rows(): void
    {
        [$directory, $evidence, $queryArtifact] = $this->mutableFixture();

        try {
            $firstUrl = $evidence['targets'][0]['canonical_url'];
            $secondUrl = $evidence['targets'][1]['canonical_url'];
            $evidence['targets'][0]['query_export']['raw_row_count']++;
            $evidence['targets'][1]['query_export']['excluded_site_operator_count'] = '1';
            $queryArtifact['target_summaries'][$secondUrl]['excluded']['site_operator'] = -1;
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);
            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('query_export_count_conservation_failed:'.$firstUrl, $package['issues']);
            self::assertContains('query_export_count_invalid:'.$secondUrl, $package['issues']);
            self::assertContains('query_summary_exclusion_count_invalid:'.$secondUrl, $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_malformed_or_impossible_gsc_count_metrics(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $url = $evidence['targets'][0]['canonical_url'];
            $evidence['targets'][0]['gsc_page']['current_clicks'] = 'invalid';
            $evidence['targets'][0]['gsc_page']['previous_clicks'] = 'invalid';
            $evidence['targets'][0]['gsc_page']['click_delta'] = 0;
            $evidence['targets'][0]['gsc_page']['current_impressions'] = 1;
            $evidence['targets'][0]['gsc_page']['previous_impressions'] = 1;
            $evidence['targets'][1]['gsc_page']['current_clicks'] = 2;
            $evidence['targets'][1]['gsc_page']['current_impressions'] = 1;
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('gsc_click_delta_mismatch:'.$url, $package['issues']);
            self::assertContains(
                'gsc_click_impression_relationship_invalid:'.$evidence['targets'][1]['canonical_url'],
                $package['issues']
            );
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_requires_the_exact_manual_review_checklist(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $evidence['manual_review_gate']['required_checks'] = [];
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('manual_review_gate_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_credentials_embedded_in_source_urls(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $url = $evidence['targets'][0]['canonical_url'];
            $evidence['targets'][0]['source_refs'][0]['url'] = 'https://token:secret@example.org/paper';
            $evidence['targets'][0]['source_refs'][1]['url'] = 'https://example.org/paper?access%5Ftoken=secret';
            $evidence['targets'][1]['source_refs'][0]['url'] = 'https://example.org/paper?client-secret=secret';
            $evidence['targets'][1]['source_refs'][1]['url'] = 'https://example.org/paper?refresh_token=secret';
            $evidence['targets'][2]['source_refs'][0]['url'] = 'https://example.org/paper?authorization=secret';
            $evidence['targets'][2]['source_refs'][1]['url'] = 'https://example.org/paper?cookie=secret';
            $evidence['targets'][3]['source_refs'][0]['url'] = 'https://example.org/paper?x-api-key=secret';
            $evidence['targets'][3]['source_refs'][1]['url'] = 'https://example.org/paper?github_token=secret';
            $evidence['targets'][4]['source_refs'][0]['url'] = 'https://example.org/paper?db-password=secret';
            $evidence['targets'][4]['source_refs'][1]['url'] = 'https://example.org/paper?provider-token-version=secret';
            $evidence['targets'][0]['source_refs'][] = [
                'id' => 'camel-api-key',
                'url' => 'https://example.org/paper?apiKey=secret',
                'authority_type' => 'research',
            ];
            $evidence['targets'][1]['source_refs'][] = [
                'id' => 'camel-access-token',
                'url' => 'https://example.org/paper?accessToken=secret',
                'authority_type' => 'research',
            ];
            $evidence['targets'][2]['source_refs'][] = [
                'id' => 'camel-client-secret',
                'url' => 'https://example.org/paper?clientSecret=secret',
                'authority_type' => 'research',
            ];
            $evidence['targets'][3]['source_refs'][] = [
                'id' => 'collapsed-api-key',
                'url' => 'https://example.org/paper?apikey=secret',
                'authority_type' => 'research',
            ];
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('source_ref_invalid:'.$url, $package['issues']);
            self::assertContains(
                'source_ref_invalid:'.$evidence['targets'][1]['canonical_url'],
                $package['issues']
            );
            self::assertContains(
                'source_ref_invalid:'.$evidence['targets'][2]['canonical_url'],
                $package['issues']
            );
            self::assertContains(
                'source_ref_invalid:'.$evidence['targets'][3]['canonical_url'],
                $package['issues']
            );
            self::assertContains(
                'source_ref_invalid:'.$evidence['targets'][4]['canonical_url'],
                $package['issues']
            );
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_non_allowlisted_fields_in_sanitized_artifacts(): void
    {
        [$directory, $evidence, $queryArtifact, $pageCohort] = $this->mutableFixture();

        try {
            $firstUrl = $evidence['targets'][0]['canonical_url'];
            $queryArtifact['target_summaries'][$firstUrl]['queries'] = ['must-not-persist'];
            $pageCohort['rows'][0]['raw_url'] = 'https://private.invalid/article';
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);
            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('artifact_field_allowlist_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_non_allowlisted_fields_in_primary_evidence(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $evidence['targets'][0]['proposed_recovery']['search_terms'] = ['must-not-persist'];
            $evidence['targets'][1]['proposed_recovery']['visible_section_actions'][] = [
                'search_terms' => ['must-not-persist'],
            ];
            $evidence['targets'][2]['claim_boundary']['allowed_claims'][] = [
                'raw_evidence' => 'must-not-persist',
            ];
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('artifact_field_allowlist_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_private_or_internal_source_reference_hosts(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $privateUrls = [
                'https://localhost/private',
                'https://10.0.0.1/evidence',
                'https://169.254.169.254/latest/meta-data',
                'https://[::1]/private',
                'https://evidence.internal/private',
            ];
            foreach ($privateUrls as $index => $privateUrl) {
                $evidence['targets'][$index]['source_refs'][0]['url'] = $privateUrl;
            }
            $evidence['targets'][0]['source_refs'][] = [
                'id' => 'shorthand-loopback',
                'url' => 'https://127.1/private',
                'authority_type' => 'research',
            ];
            $evidence['targets'][1]['source_refs'][] = [
                'id' => 'octal-loopback',
                'url' => 'https://0177.0.0.1/private',
                'authority_type' => 'research',
            ];
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            foreach ($evidence['targets'] as $target) {
                self::assertContains('source_ref_invalid:'.$target['canonical_url'], $package['issues']);
            }
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_requires_valid_consecutive_equal_length_nonfuture_comparison_windows(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $invalidWindows = [
                [
                    'previous' => ['start' => '2026-05-29', 'end' => '2026-06-25'],
                    'current' => ['start' => 'not-a-date', 'end' => '2026-07-23'],
                    'observed_at' => '2026-07-26T04:35:35Z',
                ],
                [
                    'previous' => ['start' => '2026-05-29', 'end' => '2026-06-25'],
                    'current' => ['start' => '2026-06-25', 'end' => '2026-07-22'],
                    'observed_at' => '2026-07-26T04:35:35Z',
                ],
                [
                    'previous' => ['start' => '2026-06-25', 'end' => '2026-05-29'],
                    'current' => ['start' => '2026-06-26', 'end' => '2026-07-23'],
                    'observed_at' => '2026-07-26T04:35:35Z',
                ],
                [
                    'previous' => ['start' => '2026-06-26', 'end' => '2026-07-23'],
                    'current' => ['start' => '2026-07-24', 'end' => '2026-08-20'],
                    'observed_at' => '2026-07-26T04:35:35Z',
                ],
                [
                    'previous' => ['start' => '2026-05-29', 'end' => '2026-06-25'],
                    'current' => ['start' => '2026-06-26', 'end' => '2026-07-22'],
                    'observed_at' => '2026-07-26T04:35:35Z',
                ],
            ];

            foreach ($invalidWindows as $invalidWindow) {
                $mutated = $evidence;
                $mutated['gsc']['previous_window'] = $invalidWindow['previous'];
                $mutated['gsc']['current_window'] = $invalidWindow['current'];
                $mutated['observed_at'] = $invalidWindow['observed_at'];
                $this->writeJson($directory.'/live-gsc-evidence.v1.json', $mutated);
                $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

                $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                    $directory.'/live-gsc-evidence.v1.json',
                    $evidenceSha
                );

                self::assertFalse($package['ok']);
                self::assertContains('gsc_comparison_windows_invalid', $package['issues']);
                self::assertFalse($package['would_write']);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_requires_exact_gsc_property_export_identity_and_complete_recovery_metadata(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $firstUrl = $evidence['targets'][0]['canonical_url'];
            $secondUrl = $evidence['targets'][1]['canonical_url'];
            $evidence['gsc']['property'] = 'sc-domain:example.org';
            $evidence['gsc']['search_type'] = 'image';
            $evidence['gsc']['page_export']['zip_sha256'] = '';
            $evidence['gsc']['page_export']['csv_sha256'] = 'not-a-sha';
            $evidence['gsc']['page_export']['total_row_count'] = '149';
            $evidence['targets'][0]['proposed_recovery']['title_candidate'] = '';
            $evidence['targets'][1]['proposed_recovery']['meta_description_candidate'] = '';
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('gsc_property_or_search_type_invalid', $package['issues']);
            self::assertContains('gsc_page_export_hash_invalid', $package['issues']);
            self::assertContains('gsc_page_export_count_invalid', $package['issues']);
            self::assertContains('recovery_plan_incomplete:'.$firstUrl, $package['issues']);
            self::assertContains('recovery_plan_incomplete:'.$secondUrl, $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_structured_secret_keys_before_building_the_package(): void
    {
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $evidence['targets'][0]['source_refs'][0]['api_key'] = 'must-not-persist';
            $evidence['targets'][1]['proposed_recovery']['client-secret'] = 'must-not-persist';
            $evidence['targets'][2]['claim_boundary']['provider_x_api_key_value'] = 'must-not-persist';
            $evidence['targets'][3]['source_refs'][0]['github_token_value'] = 'must-not-persist';
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('forbidden_raw_or_private_field_present', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_page_cohort_uses_non_derivable_opaque_ids_without_url_digests(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            self::assertSame('opaque_random_ids_mapping_not_persisted', $pageCohort['identifier_model']);
            self::assertFalse($pageCohort['raw_url_persisted']);
            self::assertFalse($pageCohort['unkeyed_url_digest_persisted']);
            self::assertArrayNotHasKey('top_five_url_hashes', $pageCohort);
            self::assertCount(54, $pageCohort['rows']);

            $serializedCohort = json_encode($pageCohort, JSON_THROW_ON_ERROR);
            foreach ($pageCohort['rows'] as $row) {
                self::assertArrayNotHasKey('canonical_url_hash', $row);
                self::assertMatchesRegularExpression('/^page_[a-f0-9]{32}$/', $row['page_evidence_id']);
            }
            foreach ($evidence['targets'] as $target) {
                self::assertArrayNotHasKey('url_hash', $target);
                self::assertMatchesRegularExpression('/^page_[a-f0-9]{32}$/', $target['page_evidence_id']);
                self::assertStringNotContainsString(hash('sha256', $target['canonical_url']), $serializedCohort);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_string_negative_and_non_finite_average_positions(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            $firstUrl = $evidence['targets'][0]['canonical_url'];
            $secondUrl = $evidence['targets'][1]['canonical_url'];
            $evidence['targets'][0]['gsc_page']['current_position'] = '19.09';
            $evidence['targets'][1]['gsc_page']['previous_position'] = -1.0;
            $pageCohort['rows'][2]['current_position'] = '12';
            $pageCohort['rows'][3]['current_position'] = 98765.4321;
            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            File::put(
                $directory.'/live-gsc-page-cohort-hashes.v1.json',
                str_replace(
                    '"current_position": 98765.4321',
                    '"current_position": 1e400',
                    File::get($directory.'/live-gsc-page-cohort-hashes.v1.json')
                )
            );
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('gsc_position_invalid:'.$firstUrl, $package['issues']);
            self::assertContains('gsc_position_invalid:'.$secondUrl, $package['issues']);
            self::assertContains('page_cohort_row_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_validates_the_full_article_cohort_and_top_five_cutoff(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            $pageCohort['rows'][5]['current_impressions'] = 15;
            $pageCohort['rows'][5]['impression_delta'] = -25;
            $pageCohort['cutoff_attestation']['rank_6'] = $pageCohort['rows'][5];
            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('page_cohort_ranking_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_non_integer_counts_anywhere_in_the_full_article_cohort(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            $pageCohort['rows'][10]['current_clicks'] = '0';
            $pageCohort['rows'][11]['previous_impressions'] = 4.5;
            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('page_cohort_row_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_enforces_the_opaque_page_id_tie_break_for_equal_cohort_deltas(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            [$pageCohort['rows'][9], $pageCohort['rows'][10]] = [
                $pageCohort['rows'][10],
                $pageCohort['rows'][9],
            ];
            $pageCohort['rows'][9]['rank'] = 10;
            $pageCohort['rows'][10]['rank'] = 11;
            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('page_cohort_ranking_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_requires_five_targets_with_verified_performance_deterioration(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            $previousImpressions = $pageCohort['rows'][4]['previous_impressions'];
            $pageCohort['rows'][4]['current_impressions'] = $previousImpressions;
            $pageCohort['rows'][4]['impression_delta'] = 0;
            $pageCohort['cutoff_attestation']['rank_5'] = $pageCohort['rows'][4];
            $evidence['targets'][4]['gsc_page']['current_impressions'] = $previousImpressions;
            $evidence['targets'][4]['gsc_page']['impression_delta'] = 0;

            $this->writeJson($directory.'/live-gsc-page-cohort-hashes.v1.json', $pageCohort);
            $evidence['gsc']['page_cohort_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-page-cohort-hashes.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertContains('insufficient_performance_deterioration_targets', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_a_self_attested_formal_readmodel_gate_artifact(): void
    {
        [$directory, $evidence, , $pageCohort] = $this->mutableFixture();

        try {
            $gatePath = $directory.'/formal-readmodel-gate.v1.json';
            $this->writeJson($gatePath, [
                'schema' => 'fermatmind-seo-10k-article-recovery-readmodel-gate.v1',
                'task' => ArticleRecoveryBatchPlanner::TASK,
                'status' => 'pass',
                'data_quality_gate' => 'pass',
                'data_origin' => 'live_gsc_api',
                'row_source' => 'live_gsc_api',
                'source_engine' => 'google',
                'data_state' => 'final',
                'opportunity_queue_eligible' => true,
                'article_cohort_coverage_count' => 54,
                'current_window' => $evidence['gsc']['current_window'],
                'previous_window' => $evidence['gsc']['previous_window'],
                'page_cohort_artifact_sha256' => $evidence['gsc']['page_cohort_artifact']['sha256'],
                'top_five_page_evidence_ids' => $pageCohort['top_five_page_evidence_ids'],
                'production_readback_sha256' => str_repeat('a', 64),
            ]);
            $evidence['gsc']['formal_readmodel_gate'] = [
                'status' => 'pass',
                'artifact_path' => basename($gatePath),
                'artifact_sha256' => hash_file('sha256', $gatePath),
            ];
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $package = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertFalse($package['ok']);
            self::assertSame('blocked', $package['status']);
            self::assertContains('artifact_field_allowlist_invalid', $package['issues']);
            self::assertFalse($package['would_write']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_binds_query_evidence_and_artifact_identity_into_each_review_target_hash(): void
    {
        $planner = app(ArticleRecoveryBatchPlanner::class);
        $baseline = $planner->plan($this->evidencePath, self::EVIDENCE_SHA256);
        [$directory, $evidence, $queryArtifact] = $this->mutableFixture();

        try {
            $url = $evidence['targets'][0]['canonical_url'];
            $queryArtifact['target_summaries'][$url]['retained_query_count']--;
            $evidence['targets'][0]['query_export']['retained_query_count']--;
            $evidence['targets'][0]['query_export']['raw_row_count']--;
            $this->writeJson($directory.'/live-gsc-query-summary.v1.json', $queryArtifact);
            $evidence['gsc']['query_summary_artifact']['sha256'] = hash_file(
                'sha256',
                $directory.'/live-gsc-query-summary.v1.json'
            );
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $mutated = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertNotSame(
                data_get($baseline, 'targets.0.review_target_sha256'),
                data_get($mutated, 'targets.0.review_target_sha256')
            );
            self::assertSame(
                $evidence['gsc']['query_summary_artifact']['sha256'],
                data_get($mutated, 'targets.0.query_summary_artifact_sha256')
            );
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                data_get($mutated, 'targets.0.target_query_summary_sha256')
            );
            self::assertFalse($mutated['approval_eligible']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_binds_each_private_query_export_identity_into_the_review_target_hash(): void
    {
        $planner = app(ArticleRecoveryBatchPlanner::class);
        $baseline = $planner->plan($this->evidencePath, self::EVIDENCE_SHA256);
        [$directory, $evidence] = $this->mutableFixture();

        try {
            $evidence['targets'][0]['query_export']['zip_sha256'] = str_repeat('a', 64);
            $evidence['targets'][0]['query_export']['csv_sha256'] = str_repeat('b', 64);
            $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
            $evidenceSha = hash_file('sha256', $directory.'/live-gsc-evidence.v1.json');

            $mutated = (new ArticleRecoveryBatchPlanner($evidenceSha))->plan(
                $directory.'/live-gsc-evidence.v1.json',
                $evidenceSha
            );

            self::assertNotSame(
                data_get($baseline, 'targets.0.review_target_sha256'),
                data_get($mutated, 'targets.0.review_target_sha256')
            );
            self::assertSame(
                str_repeat('a', 64),
                data_get($mutated, 'targets.0.private_query_export_zip_sha256')
            );
            self::assertSame(
                str_repeat('b', 64),
                data_get($mutated, 'targets.0.private_query_export_csv_sha256')
            );
            self::assertFalse($mutated['approval_eligible']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return array{
     *     string,
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     array<string, mixed>
     * }
     */
    private function mutableFixture(): array
    {
        $directory = storage_path('framework/testing/seo-10k-article-recovery-fixture-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        $queryPath = dirname($this->evidencePath).'/live-gsc-query-summary.v1.json';
        $pageCohortPath = dirname($this->evidencePath).'/live-gsc-page-cohort-hashes.v1.json';
        $evidence = json_decode(File::get($this->evidencePath), true, 512, JSON_THROW_ON_ERROR);
        $queryArtifact = json_decode(File::get($queryPath), true, 512, JSON_THROW_ON_ERROR);
        $pageCohort = json_decode(File::get($pageCohortPath), true, 512, JSON_THROW_ON_ERROR);

        $this->writeJson($directory.'/live-gsc-evidence.v1.json', $evidence);
        File::copy($queryPath, $directory.'/live-gsc-query-summary.v1.json');
        File::copy($pageCohortPath, $directory.'/live-gsc-page-cohort-hashes.v1.json');

        return [$directory, $evidence, $queryArtifact, $pageCohort];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::put(
            $path,
            json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )."\n"
        );
    }
}
