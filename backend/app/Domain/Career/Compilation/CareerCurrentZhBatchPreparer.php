<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use JsonException;

final class CareerCurrentZhBatchPreparer
{
    public const CONTRACT_VERSION = 'career.current.zh_batch_candidate.v1';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_FILES = 10460;

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
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_INPUT_INVALID');
        }

        $sourceRoot = $this->sourceRoot($sourceRoot);
        $outputRoot = $this->emptyOutputRoot($outputRoot);
        $baseline = $this->package->load($backendRoot);
        $currentBefore = $this->currentPackageFingerprint($baseline);
        $sourceBefore = $this->sourceLock($sourceRoot);

        if ($sourceBefore['career_count'] !== self::EXPECTED_CAREERS
            || $sourceBefore['file_count'] !== self::EXPECTED_FILES
            || $sourceBefore['slugs'] !== $baseline['slugs']) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_SET_MISMATCH');
        }

        $controls = $this->controlSlugs($backendRoot, $sourceBefore['slugs']);
        $batchPlan = $this->planBatches($sourceBefore['slugs'], $controls, $batchSize);
        $batchBySlug = [];
        foreach ($batchPlan['batches'] as $batch) {
            foreach ($batch['target_slugs'] as $slug) {
                $batchBySlug[$slug] = $batch['batch_id'];
            }
        }

        $projectionRoot = $outputRoot.'/projections/zh-CN';
        $batchRoot = $outputRoot.'/batches';
        if (! mkdir($projectionRoot, 0700, true) || ! mkdir($batchRoot, 0700, true)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_WRITE_FAILED');
        }

        $componentOrderHash = CareerCurrentAuthorityPackage::hashValue(
            CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
        );
        $perSlug = [];
        $zhHashes = [];
        $enHashes = [];
        $currentZhHashes = [];
        $candidateZhChanges = 0;
        foreach ($sourceBefore['slugs'] as $slug) {
            $blocks = $this->readBlocks($sourceRoot, $slug);
            $candidate = $this->candidateRow($baseline['rows'][$slug], $blocks);
            $projection = $this->package->publicProjection($candidate, 'zh-CN');
            if (! CareerDisplayAssetComponentContract::supports((array) $projection['component_order'])) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_COMPONENT_CONTRACT_MISMATCH');
            }
            $projectionBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($projection);
            $this->write($projectionRoot.'/'.$slug.'.json', $projectionBytes);
            $zhHash = hash('sha256', CareerCurrentAuthorityPackage::encodeCanonical($projection));
            $enHash = CareerCurrentAuthorityPackage::hashValue($this->package->publicProjection($candidate, 'en'));
            if (! hash_equals($this->package->publicContentHash($baseline['rows'][$slug], 'zh-CN'), $this->package->publicContentHash($candidate, 'zh-CN'))) {
                $candidateZhChanges++;
            }
            $zhHashes[] = $zhHash;
            $enHashes[] = $enHash;
            $currentZhHashes[] = CareerCurrentAuthorityPackage::hashValue(
                $this->package->publicProjection($baseline['rows'][$slug], 'zh-CN'),
            );
            $perSlug[$slug] = [
                'batch_identity' => $batchBySlug[$slug],
                'component_order_sha256' => $componentOrderHash,
                'source_sha256' => $sourceBefore['slug_hashes'][$slug],
                'zh_projection_sha256' => $zhHash,
            ];
        }

        $batches = [];
        foreach ($batchPlan['batches'] as $batch) {
            $targetSourceHashes = array_map(fn (string $slug): string => $sourceBefore['slug_hashes'][$slug], $batch['target_slugs']);
            $targetProjectionHashes = array_map(fn (string $slug): string => $perSlug[$slug]['zh_projection_sha256'], $batch['target_slugs']);
            $batch['target_slug_set_sha256'] = CareerCurrentAuthorityPackage::hashValue($batch['target_slugs']);
            $batch['source_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($targetSourceHashes);
            $batch['candidate_projection_sha256'] = CareerCurrentAuthorityPackage::hashValue($targetProjectionHashes);
            $batch['expected_zh_changed_pages'] = count($batch['target_slugs']);
            $batch['expected_en_changed_pages'] = count($batch['target_slugs']);
            $batches[] = $batch;
            $this->writeJson($batchRoot.'/'.$batch['batch_id'].'.json', $batch);
        }

        $sourceAfter = $this->sourceLock($sourceRoot);
        $currentAfter = $this->currentPackageFingerprint($this->package->load($backendRoot));
        if ($sourceBefore !== $sourceAfter) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_BYTES_CHANGED');
        }
        if ($currentBefore !== $currentAfter) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_CHANGED');
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
            'en_changed_locale_pages' => count($perSlug),
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
            'target_locale' => 'zh-CN',
            'target_locale_page_count' => count($perSlug),
            'zh_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($zhHashes),
        ];
        $diff = [
            'candidate_zh_projection_changes' => $candidateZhChanges,
            'compiler_contract_changes' => 1,
            'current_package_changes' => 0,
            'en_projection_changes' => count($perSlug),
            'runtime_writes' => 0,
            'source_asset_changes' => 0,
        ];
        $report = [
            'batch_count' => count($batches),
            'batch_sizes' => array_map(static fn (array $batch): int => $batch['target_count'], $batches),
            'before_after_bytes_unchanged' => true,
            'candidate_zh_projection_changes' => $candidateZhChanges,
            'components_per_page' => count(CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS),
            'current_assets_sha256' => $currentBefore[$assetsPath],
            'current_en_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($enHashes),
            'current_manifest_sha256' => $currentBefore[$manifestPath],
            'current_zh_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($currentZhHashes),
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'pointer_writes' => 0,
            'sitemap_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
            'duplicate_target_slugs' => $batchPlan['duplicate_target_slugs'],
            'en_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($enHashes),
            'en_changed_pages' => count($perSlug),
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
            'zh_locale_pages' => count($perSlug),
        ];

        $this->writeJson($outputRoot.'/source-lock.json', [
            'contract_version' => 'career.current.zh_source_lock.v1',
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
        return $this->sourceLock($this->sourceRoot($sourceRoot));
    }

    /** @param array<string,mixed> $baseline @return array<string,mixed> */
    public function candidateRowForSource(string $sourceRoot, string $slug, array $baseline, bool $upgradeV43 = true): array
    {
        $sourceRoot = $this->sourceRoot($sourceRoot);

        return $this->candidateRow($baseline, $this->readBlocks($sourceRoot, $slug), $upgradeV43);
    }

    /** @param array<string,mixed> $baseline @param array<string,array<string,mixed>> $blocks @return array<string,mixed> */
    private function candidateRow(array $baseline, array $blocks, bool $upgradeV43 = true): array
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
        $zh = $pagesWrapped
            ? $baseline['page_payload_json']['page']['zh']
            : $baseline['page_payload_json']['zh'];
        $zh['breadcrumb'] = ['label' => $identity['title_zh'], 'slug' => $identity['slug']];
        $zh['hero'] = ['h1' => $identity['title_zh'], 'quick_answer' => $meta['hero_lead'], 'title' => $identity['title_zh']];
        $zh['fermat_decision_card'] = ['caveat' => $definition['quick_bound'], 'summary' => $definition['def_callout'], 'title' => '费马快速判断'];
        $zh['career_snapshot_primary_locale'] = ['callout' => $meta['snapshot_callout'], 'salary' => $salary, 'scene' => $meta['scene_fact']];
        $zh['career_snapshot_secondary_locale'] = ['bls_table' => $salary['bls_table'], 'growth' => $salary['us_growth'], 'median' => $salary['us_median']];
        $zh['fit_decision_checklist'] = ['how' => $definition['quick_how'], 'suit' => $definition['quick_suit'], 'boundary' => $definition['quick_bound']];
        $zh['riasec_fit_block'] = ['interest' => $fit['interest'], 'fit_interest' => $fit['fit_interest'], 'riasec' => $identity['riasec'], 'riasec_short' => $identity['riasec_short']];
        $zh['personality_fit_block'] = ['callout' => $fit['fit_callout'], 'disclaimer' => $fit['disclaimer'], 'traits' => $fit['fit_traits']];
        $zh['definition_block'] = $definition['definition'];
        $zh['career_ai_description_block'] = ['body' => [$geo['one_line_definition']], 'heading' => $ai['ai_head_sub']];
        $zh['responsibilities_block'] = $definition['duties'];
        $zh['work_context_block'] = $definition['work_scene'];
        if ($upgradeV43) {
            $structured = new CareerStructuredComponentProjector;
            $zh['career_quick_answers_block'] = $structured->quickAnswers($definition);
            $zh['onet_structured_fields_block'] = $structured->onetStructuredFields($definition);
        }
        $zh['market_signal_card'] = ['callout' => $meta['signal_callout'], 'facts' => $meta['signal_facts'], 'intro' => $meta['signal_intro'], 'signals' => $meta['signal_list']];
        $zh['adjacent_career_comparison_table'] = $compare['compare_rows'];
        $zh['ai_impact_table'] = $ai;
        $zh['career_risk_cards'] = ['badge' => $risk['risk_badge'], 'callout' => $risk['risk_callout'], 'fact' => $risk['risk_fact'], 'risks' => $risk['risk_list']];
        $zh['career_path_block'] = $risk['risk_path_table'];
        $zh['contract_project_risk_block'] = $risk['risk_contract'];
        $zh['next_steps_block'] = ['hot_skills' => $meta['hot_skills'], 'responsibilities' => $meta['oc_responsibilities'], 'skills' => $meta['oc_skills']];
        $zh['faq_block'] = ['items' => array_map(static fn (array $item): array => ['answer' => $item['a'], 'question' => $item['q']], $faq['faq'])];
        $zh['related_next_pages'] = ['links' => $compare['internal_links'], 'intro' => $compare['relgrid_intro']];
        $zh['source_card'] = ['eeat_signals' => $geo['eeat_signals'], 'note' => $meta['sources_note']];
        $zh['review_validity_card'] = ['last_reviewed' => $geo['eeat_signals']['updated_at'] ?? null];
        $zh['boundary_notice'] = [$risk['risk_callout'], $fit['disclaimer']];
        if ($pagesWrapped) {
            $baseline['page_payload_json']['page']['zh'] = $zh;
            if ($upgradeV43) {
                $baseline['page_payload_json']['page']['en']['career_quick_answers_block'] = $structured->unavailable();
                $baseline['page_payload_json']['page']['en']['onet_structured_fields_block'] = $structured->unavailable();
            }
        } else {
            $baseline['page_payload_json']['zh'] = $zh;
            if ($upgradeV43) {
                $baseline['page_payload_json']['en']['career_quick_answers_block'] = $structured->unavailable();
                $baseline['page_payload_json']['en']['onet_structured_fields_block'] = $structured->unavailable();
            }
        }
        if ($upgradeV43) {
            $baseline['component_order_json'] = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
            $baseline['metadata_json']['structured_components_v1'] = $structured->evidenceBindings($definition);
        }
        $baseline['seo_payload_json']['zh']['h1'] = $identity['title_zh'];
        $baseline['seo_payload_json']['zh']['title'] = $meta['meta_title'];
        $baseline['seo_payload_json']['zh']['description'] = $meta['meta_description'];
        $baseline['structured_data_json']['faq_page']['zh'] = $this->faqPage($zh['faq_block']['items']);
        $enPage = $pagesWrapped
            ? ($baseline['page_payload_json']['page']['en'] ?? null)
            : ($baseline['page_payload_json']['en'] ?? null);
        $baseline['structured_data_json']['faq_page']['en'] = $this->faqPage(
            is_array($enPage) ? ($enPage['faq_block']['items'] ?? null) : null,
        );

        return $baseline;
    }

    /** @return array<string,mixed> */
    private function faqPage(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PROJECTION_INPUT_INVALID');
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static function (mixed $item): array {
                $question = is_array($item) ? ($item['question'] ?? null) : null;
                $answer = is_array($item) ? ($item['answer'] ?? null) : null;
                if (! is_string($question) || trim($question) === ''
                    || ! is_string($answer) || trim($answer) === '') {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_PROJECTION_INPUT_INVALID');
                }

                return [
                    '@type' => 'Question',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                    'name' => $question,
                ];
            }, $items),
        ];
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
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_FILE_SET_INVALID');
            }
            foreach ($actual as $module) {
                $path = $root.'/'.$slug.'/'.$module;
                if (! is_file($path) || is_link($path)) {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_FILE_SET_INVALID');
                }
                $bytes = file_get_contents($path);
                if (! is_string($bytes)) {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_FILE_UNREADABLE');
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
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_FILE_SET_INVALID');
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
            'identity.json' => ['slug' => 'string', 'title_zh' => 'string', 'riasec' => 'string', 'riasec_short' => 'string'],
            'definition.json' => [
                'quick_bound' => 'string', 'quick_how' => 'string', 'quick_suit' => 'string',
                'def_callout' => 'string', 'definition' => 'string', 'duties' => 'list',
                'work_scene' => 'string', 'qa1_q' => 'string', 'qa1_a' => 'string',
                'qa1_table' => 'list', 'qa2_q' => 'string', 'qa2_a' => 'string',
                'qa2_table' => 'list', 'qa3_q' => 'string', 'qa3_a' => 'string',
                'qa3_table' => 'list', 'onet_struct' => 'list',
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
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_PROJECTION_INPUT_INVALID');
                }
            }
        }
        if (($blocks['identity.json']['slug'] ?? null) !== $slug) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SLUG_MISMATCH');
        }
        foreach ($blocks['faq.json']['faq'] as $item) {
            if (! is_array($item) || ! is_string($item['q'] ?? null) || ! is_string($item['a'] ?? null)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_PROJECTION_INPUT_INVALID');
            }
        }
        $projector = new CareerStructuredComponentProjector;
        foreach (['qa1_table', 'qa2_table', 'qa3_table', 'onet_struct'] as $field) {
            $projector->rows($blocks['definition.json'][$field]);
        }
    }

    /** @param list<string> $sourceSlugs @return list<string> */
    private function controlSlugs(string $backendRoot, array $sourceSlugs): array
    {
        $path = rtrim($backendRoot, '/').'/content_assets/career/evidence/c2-first-five/cohort.json';
        try {
            $cohort = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CONTROL_SET_INVALID');
        }
        $controls = array_values(array_unique(array_merge(
            ['accountants-and-auditors'],
            is_array($cohort['evidence_bound_slugs'] ?? null) ? $cohort['evidence_bound_slugs'] : [],
        )));
        sort($controls, SORT_STRING);
        if (array_diff($controls, $sourceSlugs) !== []) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CONTROL_SET_INVALID');
        }

        return $controls;
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
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }

    /** @param list<string> $paths @return array<string,string> */
    private function fileHashes(array $paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = hash_file('sha256', $path) ?: throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_INVALID');
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
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_WRITE_FAILED');
        }
    }
}
