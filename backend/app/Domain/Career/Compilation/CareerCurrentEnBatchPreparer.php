<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use JsonException;

final class CareerCurrentEnBatchPreparer
{
    public const CONTRACT_VERSION = 'career.current.en_batch_candidate.v1';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_FILES = 10460;

    public const EXPECTED_SOURCE_AGGREGATE_SHA256 = '690cce1c6ebefac3fd73030368cb1db8f5a2f6814f12aa3b91bd573f2cb33d9c';

    public const EXPECTED_AUTHORITY_MANIFEST_SHA256 = '2b0252c2d57a5c4bc307df2e4f9fd382bf0c91c3d96f9c3f37077ad7f9c4c32c';

    public const EXPECTED_FILES_MANIFEST_SHA256 = 'b28aad13b78a13ff9f4a4efeffd867cc687b21652b388b5482f55cc2de0d5881';

    public function __construct(private readonly CareerCurrentAuthorityPackage $package) {}

    /** @return array<string,mixed> */
    public function prepare(
        string $sourceRoot,
        string $outputRoot,
        int $batchSize,
        string $baseSha,
        string $backendRoot,
    ): array {
        if ($batchSize !== 50 || preg_match('/\A[0-9a-f]{40}\z/', $baseSha) !== 1) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_BATCH_INPUT_INVALID');
        }

        $sourceRoot = $this->sourceRoot($sourceRoot);
        $outputRoot = $this->emptyOutputRoot($outputRoot);
        $baseline = $this->package->load($backendRoot);
        $currentBefore = $this->currentPackageFingerprint($baseline);
        $sourceBefore = $this->sourceLock($sourceRoot);

        $this->assertSealedAuthority($sourceRoot, $sourceBefore);
        if ($sourceBefore['career_count'] !== self::EXPECTED_CAREERS
            || $sourceBefore['file_count'] !== self::EXPECTED_FILES
            || $sourceBefore['slugs'] !== $baseline['slugs']) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_SET_MISMATCH');
        }

        $controls = $this->controlSlugs($backendRoot, $sourceBefore['slugs']);
        $batchPlan = $this->planBatches($sourceBefore['slugs'], $controls, $batchSize);
        $batchBySlug = [];
        foreach ($batchPlan['batches'] as $batch) {
            foreach ($batch['target_slugs'] as $slug) {
                $batchBySlug[$slug] = $batch['batch_id'];
            }
        }

        $projectionRoot = $outputRoot.'/projections/en';
        $batchRoot = $outputRoot.'/batches';
        if (! mkdir($projectionRoot, 0700, true) || ! mkdir($batchRoot, 0700, true)) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_OUTPUT_WRITE_FAILED');
        }

        $componentOrderHash = CareerCurrentAuthorityPackage::hashValue(
            CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
        );
        $perSlug = [];
        $enHashes = [];
        $zhHashes = [];
        $currentEnHashes = [];
        $candidateEnChanges = 0;
        foreach ($sourceBefore['slugs'] as $slug) {
            $blocks = $this->readBlocks($sourceRoot, $slug);
            $candidate = $this->candidateRow($baseline['rows'][$slug], $blocks);
            $this->assertCandidateScope($baseline['rows'][$slug], $candidate);
            $projection = $this->package->publicProjection($candidate, 'en');
            if (! CareerDisplayAssetComponentContract::supports((array) $projection['component_order'])) {
                throw new CareerTenBlockCompileFailure('CURRENT_EN_COMPONENT_CONTRACT_MISMATCH');
            }
            $projectionBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($projection);
            $this->write($projectionRoot.'/'.$slug.'.json', $projectionBytes);
            $enHash = hash('sha256', CareerCurrentAuthorityPackage::encodeCanonical($projection));
            $zhHash = CareerCurrentAuthorityPackage::hashValue($this->package->publicProjection($baseline['rows'][$slug], 'zh-CN'));
            if (! hash_equals($this->package->publicContentHash($baseline['rows'][$slug], 'en'), $this->package->publicContentHash($candidate, 'en'))) {
                $candidateEnChanges++;
            }
            $enHashes[] = $enHash;
            $zhHashes[] = $zhHash;
            $currentEnHashes[] = CareerCurrentAuthorityPackage::hashValue(
                $this->package->publicProjection($baseline['rows'][$slug], 'en'),
            );
            $perSlug[$slug] = [
                'batch_identity' => $batchBySlug[$slug],
                'component_order_sha256' => $componentOrderHash,
                'source_sha256' => $sourceBefore['slug_hashes'][$slug],
                'en_projection_sha256' => $enHash,
            ];
        }

        $batches = [];
        foreach ($batchPlan['batches'] as $batch) {
            $targetSourceHashes = array_map(fn (string $slug): string => $sourceBefore['slug_hashes'][$slug], $batch['target_slugs']);
            $targetProjectionHashes = array_map(fn (string $slug): string => $perSlug[$slug]['en_projection_sha256'], $batch['target_slugs']);
            $batch['target_slug_set_sha256'] = CareerCurrentAuthorityPackage::hashValue($batch['target_slugs']);
            $batch['source_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($targetSourceHashes);
            $batch['candidate_projection_sha256'] = CareerCurrentAuthorityPackage::hashValue($targetProjectionHashes);
            $batch['expected_en_changed_pages'] = count($batch['target_slugs']);
            $batch['expected_zh_changed_pages'] = 0;
            $batches[] = $batch;
            $this->writeJson($batchRoot.'/'.$batch['batch_id'].'.json', $batch);
        }

        $sourceAfter = $this->sourceLock($sourceRoot);
        $currentAfter = $this->currentPackageFingerprint($this->package->load($backendRoot));
        if ($sourceBefore !== $sourceAfter) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_BYTES_CHANGED');
        }
        if ($currentBefore !== $currentAfter) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_CURRENT_PACKAGE_CHANGED');
        }

        $manifest = [
            'batch_count' => count($batches),
            'batch_set_sha256' => CareerCurrentAuthorityPackage::hashValue($batches),
            'cache_writes' => 0,
            'cms_writes' => 0,
            'components_per_page' => count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS),
            'contract_version' => self::CONTRACT_VERSION,
            'database_writes' => 0,
            'discoverability_writes' => 0,
            'en_changed_locale_pages' => $candidateEnChanges,
            'per_slug' => $perSlug,
            'pointer_writes' => 0,
            'search_submissions' => 0,
            'sitemap_writes' => 0,
            'base_sha' => $baseSha,
            'source_aggregate_sha256' => $sourceBefore['aggregate_sha256'],
            'source_career_count' => $sourceBefore['career_count'],
            'source_file_count' => $sourceBefore['file_count'],
            'source_file_set_sha256' => $sourceBefore['file_set_sha256'],
            'source_root_identity' => [
                'basename' => basename($sourceRoot),
                'canonical_path_sha256' => hash('sha256', $sourceRoot),
            ],
            'source_slug_set_sha256' => $sourceBefore['slug_set_sha256'],
            'target_locale' => 'en',
            'target_locale_page_count' => count($perSlug),
            'en_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($enHashes),
        ];
        $diff = [
            'candidate_en_projection_changes' => $candidateEnChanges,
            'compiler_contract_changes' => 1,
            'current_package_changes' => 0,
            'en_projection_changes' => $candidateEnChanges,
            'zh_projection_changes' => 0,
            'runtime_writes' => 0,
            'source_asset_changes' => 0,
        ];
        $report = [
            'batch_count' => count($batches),
            'batch_sizes' => array_map(static fn (array $batch): int => $batch['target_count'], $batches),
            'before_after_bytes_unchanged' => true,
            'candidate_en_projection_changes' => $candidateEnChanges,
            'components_per_page' => count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS),
            'current_assets_sha256' => $currentBefore[$assetsPath],
            'current_zh_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($zhHashes),
            'current_manifest_sha256' => $currentBefore[$manifestPath],
            'current_en_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($currentEnHashes),
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'pointer_writes' => 0,
            'sitemap_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
            'duplicate_target_slugs' => $batchPlan['duplicate_target_slugs'],
            'en_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($enHashes),
            'en_changed_pages' => $candidateEnChanges,
            'zh_changed_pages' => 0,
            'maturity_exclusions' => 0,
            'missing_target_slugs' => $batchPlan['missing_target_slugs'],
            'ready_now_dependency' => false,
            'selected_slug_count' => count($perSlug),
            'software-developers' => [
                'blocks_c3_1046_rollout' => false,
                'canonical_source_member' => false,
                'current_package_member' => false,
                'decision' => 'preserve_manual_hold_outside_c3_source_set',
            ],
            'source_career_count' => $sourceBefore['career_count'],
            'source_file_count' => $sourceBefore['file_count'],
            'target_union_count' => $batchPlan['target_union_count'],
            'unexpected_target_slugs' => $batchPlan['unexpected_target_slugs'],
            'en_locale_pages' => count($perSlug),
        ];

        $this->writeJson($outputRoot.'/source-lock.json', [
            'contract_version' => 'career.current.en_source_lock.v1',
            'files' => $sourceBefore['files'],
        ]);
        $this->write($outputRoot.'/source-files.sha256', implode('', array_map(
            static fn (array $file): string => $file['sha256'].'  '.$file['relative_path']."\n",
            $sourceBefore['files'],
        )));
        $this->writeJson($outputRoot.'/source-slugs.json', $sourceBefore['slugs']);
        $this->writeJson($outputRoot.'/batches.json', $batches);
        $this->writeJson($outputRoot.'/manifest.json', $manifest);
        $this->writeJson($outputRoot.'/exact-diff.json', $diff);
        $this->writeJson($outputRoot.'/acceptance-report.json', $report);

        return ['manifest' => $manifest, 'report' => $report, 'diff' => $diff];
    }

    /** @param array<string,mixed> $package */
    private function currentPackageFingerprint(array $package): array
    {
        return [
            'manifest_sha256' => $package['summary']['manifest_sha256'] ?? null,
            'sharded_aggregate_sha256' => $package['summary']['sharded_aggregate_sha256'] ?? null,
            'versionless_projection_sha256' => $package['summary']['versionless_projection_sha256'] ?? null,
        ];
    }

    /** @param list<string> $slugs @param list<string> $controls @return array<string,mixed> */
    public function planBatches(array $slugs, array $controls, int $batchSize = 50): array
    {
        $canonical = array_values(array_unique($slugs));
        sort($canonical, SORT_STRING);
        $controls = array_values(array_unique(array_intersect($controls, $canonical)));
        sort($controls, SORT_STRING);
        $batches = [];
        $previous = [];
        foreach (array_chunk($canonical, $batchSize) as $index => $targets) {
            $controlSlugs = array_values(array_unique(array_merge($controls, $previous)));
            sort($controlSlugs, SORT_STRING);
            $batches[] = [
                'batch_id' => sprintf('batch-%03d', $index + 1),
                'control_slugs' => $controlSlugs,
                'ordinal' => $index + 1,
                'target_count' => count($targets),
                'target_slugs' => $targets,
            ];
            $previous = $targets;
        }
        $targets = array_merge(...array_map(static fn (array $batch): array => $batch['target_slugs'], $batches));
        $counts = array_count_values($targets);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count !== 1));

        return [
            'batches' => $batches,
            'duplicate_target_slugs' => $duplicates,
            'missing_target_slugs' => array_values(array_diff($canonical, $targets)),
            'target_union_count' => count(array_unique($targets)),
            'unexpected_target_slugs' => array_values(array_diff($targets, $canonical)),
        ];
    }

    /** @return array<string,mixed> */
    public function inspectSource(string $sourceRoot): array
    {
        $sourceRoot = $this->sourceRoot($sourceRoot);
        $source = $this->sourceLock($sourceRoot);
        $this->assertSealedAuthority($sourceRoot, $source);

        return $source;
    }

    /** @param array<string,mixed> $baseline @return array<string,mixed> */
    public function candidateRowForSource(string $sourceRoot, string $slug, array $baseline): array
    {
        $sourceRoot = $this->sourceRoot($sourceRoot);

        return $this->candidateRow($baseline, $this->readBlocks($sourceRoot, $slug));
    }

    /** @param array<string,mixed> $baseline @param array<string,array<string,mixed>> $blocks @return array<string,mixed> */
    private function candidateRow(array $baseline, array $blocks): array
    {
        $identity = $blocks['identity.json'];
        $definition = $blocks['definition.json'];
        $salary = $blocks['salary.json'];
        $ai = $blocks['ai-impact.json'];
        $risk = $blocks['risk.json'];
        $fit = $blocks['fit-personality.json'];
        $geo = $blocks['geo.json'];
        $faq = $blocks['faq.json'];
        $compare = $blocks['compare-links.json'];
        $meta = $blocks['page-meta.json'];
        $pagesWrapped = is_array($baseline['page_payload_json']['page'] ?? null);
        $en = $pagesWrapped
            ? $baseline['page_payload_json']['page']['en']
            : $baseline['page_payload_json']['en'];
        $en['breadcrumb'] = ['label' => $identity['title_en'], 'slug' => $identity['slug']];
        $en['hero'] = ['h1' => $identity['title_en'], 'quick_answer' => $meta['hero_lead'], 'title' => $identity['title_en']];
        $en['fermat_decision_card'] = ['caveat' => $definition['quick_bound'], 'summary' => $definition['def_callout'], 'title' => 'Fermat Quick Fit Check'];
        $en['career_snapshot_primary_locale'] = ['callout' => $meta['snapshot_callout'], 'salary' => $salary, 'scene' => $meta['scene_fact']];
        $en['career_snapshot_secondary_locale'] = ['bls_table' => $salary['bls_table'], 'growth' => $salary['us_growth'], 'median' => $salary['us_median']];
        $en['fit_decision_checklist'] = ['how' => $definition['quick_how'], 'suit' => $definition['quick_suit'], 'boundary' => $definition['quick_bound']];
        $en['riasec_fit_block'] = ['interest' => $fit['interest'], 'fit_interest' => $fit['fit_interest'], 'riasec' => $identity['riasec'], 'riasec_short' => $identity['riasec_short']];
        $en['personality_fit_block'] = ['callout' => $fit['fit_callout'], 'disclaimer' => $fit['disclaimer'], 'traits' => $fit['fit_traits']];
        $en['definition_block'] = $definition['definition'];
        $en['career_ai_description_block'] = ['body' => [$geo['one_line_definition']], 'heading' => $ai['ai_head_sub']];
        $en['responsibilities_block'] = $definition['duties'];
        $en['work_context_block'] = $definition['work_scene'];
        $structured = new CareerStructuredComponentProjector;
        $en['career_quick_answers_block'] = $structured->quickAnswers($definition, 'en');
        $en['onet_structured_fields_block'] = $structured->onetStructuredFields($definition, 'en');
        $en['market_signal_card'] = ['callout' => $meta['signal_callout'], 'facts' => $meta['signal_facts'], 'intro' => $meta['signal_intro'], 'signals' => $meta['signal_list']];
        $en['adjacent_career_comparison_table'] = $compare['compare_rows'];
        $en['ai_impact_table'] = $ai;
        $en['career_risk_cards'] = ['badge' => $risk['risk_badge'], 'callout' => $risk['risk_callout'], 'fact' => $risk['risk_fact'], 'risks' => $risk['risk_list']];
        $en['career_path_block'] = $risk['risk_path_table'];
        $en['contract_project_risk_block'] = $risk['risk_contract'];
        $en['next_steps_block'] = ['hot_skills' => $meta['hot_skills'], 'responsibilities' => $meta['oc_responsibilities'], 'skills' => $meta['oc_skills']];
        $en['faq_block'] = ['items' => array_map(static fn (array $item): array => ['answer' => $item['a'], 'question' => $item['q']], $faq['faq'])];
        $en['related_next_pages'] = ['links' => $compare['internal_links'], 'intro' => $compare['relgrid_intro']];
        $en['source_card'] = ['eeat_signals' => $geo['eeat_signals'], 'note' => $meta['sources_note']];
        $en['review_validity_card'] = ['last_reviewed' => $geo['eeat_signals']['updated_at'] ?? null];
        $en['boundary_notice'] = [$risk['risk_callout'], $fit['disclaimer']];
        if ($pagesWrapped) {
            $baseline['page_payload_json']['page']['en'] = $en;
        } else {
            $baseline['page_payload_json']['en'] = $en;
        }
        $baseline['seo_payload_json']['en']['h1'] = $identity['title_en'];
        $baseline['seo_payload_json']['en']['title'] = $meta['meta_title'];
        $baseline['seo_payload_json']['en']['description'] = $meta['meta_description'];
        $structuredMetadata = $baseline['metadata_json']['structured_components_v1'] ?? null;
        $zhBindings = is_array($structuredMetadata)
            && ($structuredMetadata['contract_version'] ?? null) === 'career.structured_components.locale_claim_bindings.v1'
            ? ($structuredMetadata['locales']['zh-CN'] ?? null)
            : $structuredMetadata;
        if (! is_array($zhBindings)
            || ($zhBindings['contract_version'] ?? null) !== 'career.structured_components.claim_bindings.v1'
            || ! is_array($zhBindings['bindings'] ?? null)
            || count($zhBindings['bindings']) !== 2) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_ZH_CLAIM_BINDINGS_INVALID');
        }
        $baseline['metadata_json']['structured_components_v1'] = [
            'contract_version' => 'career.structured_components.locale_claim_bindings.v1',
            'locales' => [
                'en' => $structured->evidenceBindings($definition, 'en'),
                'zh-CN' => $zhBindings,
            ],
        ];

        return $baseline;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function assertCandidateScope(array $before, array $after): void
    {
        if (is_array($before['page_payload_json']['page'] ?? null)) {
            $beforePage = &$before['page_payload_json']['page'];
            $afterPage = &$after['page_payload_json']['page'];
        } else {
            $beforePage = &$before['page_payload_json'];
            $afterPage = &$after['page_payload_json'];
        }
        unset(
            $beforePage['en'],
            $afterPage['en'],
            $before['seo_payload_json']['en'],
            $after['seo_payload_json']['en'],
            $before['metadata_json']['structured_components_v1'],
            $after['metadata_json']['structured_components_v1'],
        );
        if (! hash_equals(CareerCurrentAuthorityPackage::hashValue($before), CareerCurrentAuthorityPackage::hashValue($after))) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_CANDIDATE_SCOPE_INVALID');
        }
    }

    /** @return array<string,mixed> */
    private function sourceLock(string $root): array
    {
        $slugs = array_values(array_filter(scandir($root) ?: [], static fn (string $name): bool => ! str_starts_with($name, '.') && is_dir($root.'/'.$name)));
        sort($slugs, SORT_STRING);
        $files = [];
        $slugHashes = [];
        $aggregate = hash_init('sha256');
        foreach ($slugs as $slug) {
            $slugContext = hash_init('sha256');
            $actual = array_values(array_filter(scandir($root.'/'.$slug) ?: [], static fn (string $name): bool => str_ends_with($name, '.json')));
            sort($actual, SORT_STRING);
            if ($actual !== CareerTenBlockInputSchema::FILES) {
                throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_FILE_SET_INVALID');
            }
            foreach ($actual as $module) {
                $path = $root.'/'.$slug.'/'.$module;
                if (! is_file($path) || is_link($path)) {
                    throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_FILE_SET_INVALID');
                }
                $bytes = file_get_contents($path);
                if (! is_string($bytes)) {
                    throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_FILE_UNREADABLE');
                }
                try {
                    json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_INVALID_JSON');
                }
                $relative = $slug.'/'.$module;
                $sha = hash('sha256', $bytes);
                $files[] = ['module' => $module, 'relative_path' => $relative, 'sha256' => $sha, 'size' => strlen($bytes), 'slug' => $slug];
                hash_update($slugContext, $module."\0".$bytes."\0");
                hash_update($aggregate, $relative."\0".$bytes."\0");
            }
            $slugHashes[$slug] = hash_final($slugContext);
        }

        return [
            'aggregate_sha256' => hash_final($aggregate),
            'career_count' => count($slugs),
            'file_count' => count($files),
            'file_set_sha256' => CareerCurrentAuthorityPackage::hashValue(array_column($files, 'relative_path')),
            'files' => $files,
            'slug_hashes' => $slugHashes,
            'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($slugs),
            'slugs' => $slugs,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function readBlocks(string $root, string $slug): array
    {
        $blocks = [];
        foreach (CareerTenBlockInputSchema::FILES as $file) {
            $path = $root.'/'.$slug.'/'.$file;
            if (! is_file($path) || is_link($path)) {
                throw new CareerTenBlockCompileFailure('CURRENT_EN_SOURCE_FILE_SET_INVALID');
            }
            try {
                $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INVALID_JSON');
            }
            if (! is_array($value) || array_is_list($value)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
            }
            $blocks[$file] = $value;
        }
        $this->assertProjectionInputs($blocks, $slug);

        return $blocks;
    }

    /** @param array<string,array<string,mixed>> $blocks */
    private function assertProjectionInputs(array $blocks, string $slug): void
    {
        $contract = [
            'identity.json' => ['slug' => 'string', 'title_en' => 'string', 'riasec' => 'string', 'riasec_short' => 'string'],
            'definition.json' => [
                'quick_bound' => 'string', 'quick_how' => 'string', 'quick_suit' => 'string',
                'def_callout' => 'string', 'definition' => 'string', 'duties' => 'list', 'work_scene' => 'string',
                'qa3_q' => 'string', 'qa3_a' => 'string', 'qa3_table' => 'list',
                'qa2_q' => 'string', 'qa2_a' => 'string', 'qa2_table' => 'list',
                'qa1_q' => 'string', 'qa1_a' => 'string', 'qa1_table' => 'list',
                'onet_struct' => 'list',
            ],
            'salary.json' => ['bls_table' => 'list', 'us_growth' => 'string', 'us_median' => 'string'],
            'ai-impact.json' => ['ai_head_sub' => 'string'],
            'risk.json' => ['risk_badge' => 'string', 'risk_callout' => 'string', 'risk_contract' => 'string', 'risk_fact' => 'string', 'risk_list' => 'list', 'risk_path_table' => 'list'],
            'fit-personality.json' => ['interest' => 'string', 'fit_interest' => 'string', 'fit_traits' => 'list', 'fit_callout' => 'string', 'disclaimer' => 'string'],
            'geo.json' => ['one_line_definition' => 'string', 'eeat_signals' => 'object'],
            'faq.json' => ['faq' => 'list'],
            'compare-links.json' => ['compare_rows' => 'list', 'internal_links' => 'list', 'relgrid_intro' => 'string'],
            'page-meta.json' => ['hero_lead' => 'string', 'snapshot_callout' => 'string', 'scene_fact' => 'string', 'signal_callout' => 'string', 'signal_facts' => 'list', 'signal_intro' => 'string', 'signal_list' => 'list', 'hot_skills' => 'list', 'oc_responsibilities' => 'list', 'oc_skills' => 'list', 'sources_note' => 'string', 'meta_title' => 'string', 'meta_description' => 'string'],
        ];
        foreach ($contract as $file => $fields) {
            foreach ($fields as $field => $type) {
                $value = $blocks[$file][$field] ?? null;
                $valid = match ($type) {
                    'string' => is_string($value) && trim($value) !== '',
                    'list' => is_array($value) && array_is_list($value) && $value !== [],
                    'object' => is_array($value) && ! array_is_list($value),
                    default => false,
                };
                if (! $valid) {
                    throw new CareerTenBlockCompileFailure('CURRENT_EN_PROJECTION_INPUT_INVALID');
                }
            }
        }
        if (($blocks['identity.json']['slug'] ?? null) !== $slug) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SLUG_MISMATCH');
        }
        foreach ($blocks['faq.json']['faq'] as $item) {
            if (! is_array($item) || ! is_string($item['q'] ?? null) || ! is_string($item['a'] ?? null)) {
                throw new CareerTenBlockCompileFailure('CURRENT_EN_PROJECTION_INPUT_INVALID');
            }
        }
    }

    /** @param list<string> $sourceSlugs @return list<string> */
    private function controlSlugs(string $backendRoot, array $sourceSlugs): array
    {
        $path = rtrim($backendRoot, '/').'/content_assets/career/evidence/c2-first-five/cohort.json';
        try {
            $cohort = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_CONTROL_SET_INVALID');
        }
        $controls = array_values(array_unique(array_merge(
            ['accountants-and-auditors'],
            is_array($cohort['evidence_bound_slugs'] ?? null) ? $cohort['evidence_bound_slugs'] : [],
        )));
        sort($controls, SORT_STRING);
        if (array_diff($controls, $sourceSlugs) !== []) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_CONTROL_SET_INVALID');
        }

        return $controls;
    }

    /** @param array<string,mixed> $source */
    private function assertSealedAuthority(string $sourceRoot, array $source): void
    {
        $authorityRoot = dirname($sourceRoot);
        $manifestPath = $authorityRoot.'/en-career-pages.manifest.json';
        $filesManifestPath = $authorityRoot.'/en-career-pages.files.sha256';
        if (! is_file($manifestPath) || is_link($manifestPath)
            || ! is_file($filesManifestPath) || is_link($filesManifestPath)
            || ! hash_equals(self::EXPECTED_AUTHORITY_MANIFEST_SHA256, (string) hash_file('sha256', $manifestPath))
            || ! hash_equals(self::EXPECTED_FILES_MANIFEST_SHA256, (string) hash_file('sha256', $filesManifestPath))
            || ! hash_equals(self::EXPECTED_SOURCE_AGGREGATE_SHA256, (string) ($source['aggregate_sha256'] ?? ''))) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_AUTHORITY_HASH_MISMATCH');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_AUTHORITY_MANIFEST_INVALID');
        }
        if (! is_array($manifest)
            || ($manifest['status'] ?? null) !== 'PASS_EN_AUTHORITY_SEALED'
            || ($manifest['immutable'] ?? null) !== true
            || ($manifest['compile_authority'] ?? null) !== false
            || ($manifest['publication_authority'] ?? null) !== false
            || ($manifest['counts']['careers'] ?? null) !== self::EXPECTED_CAREERS
            || ($manifest['counts']['json_files'] ?? null) !== self::EXPECTED_FILES
            || ($manifest['hashes']['english_aggregate_hash'] ?? null) !== self::EXPECTED_SOURCE_AGGREGATE_SHA256
            || ($manifest['hashes']['files_manifest_digest'] ?? null) !== self::EXPECTED_FILES_MANIFEST_SHA256) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_AUTHORITY_MANIFEST_INVALID');
        }
        $expectedFiles = implode('', array_map(
            static fn (array $file): string => $file['sha256'].'  '.$file['relative_path']."\n",
            $source['files'],
        ));
        $actualFiles = file_get_contents($filesManifestPath);
        if (! is_string($actualFiles) || ! hash_equals(hash('sha256', $expectedFiles), hash('sha256', $actualFiles))) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_FILES_MANIFEST_MISMATCH');
        }
    }

    private function sourceRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SOURCE_MISSING');
        }

        return $resolved;
    }

    private function emptyOutputRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        $temp = realpath(sys_get_temp_dir());
        $sharedTemp = realpath('/tmp');
        if ($resolved === false || ! is_dir($resolved) || (scandir($resolved) ?: []) !== ['.', '..']
            || ($temp === false || (! str_starts_with($resolved.'/', rtrim($temp, '/').'/')
                && ($sharedTemp === false || ! str_starts_with($resolved.'/', rtrim($sharedTemp, '/').'/'))))) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }

    /** @param list<string> $paths @return array<string,string> */
    private function fileHashes(array $paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = hash_file('sha256', $path) ?: throw new CareerTenBlockCompileFailure('CURRENT_EN_CURRENT_PACKAGE_INVALID');
        }

        return $hashes;
    }

    private function writeJson(string $path, mixed $value): void
    {
        $this->write($path, CareerCurrentAuthorityPackage::encodePrettyCanonical($value));
    }

    private function write(string $path, string $bytes): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || ! rename($temporary, $path)) {
            throw new CareerTenBlockCompileFailure('CURRENT_EN_OUTPUT_WRITE_FAILED');
        }
    }
}
