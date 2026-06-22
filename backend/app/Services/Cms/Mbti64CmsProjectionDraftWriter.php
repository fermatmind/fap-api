<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Support\Facades\DB;

final class Mbti64CmsProjectionDraftWriter
{
    private const SNAPSHOT_KEY = 'mbti64_agent_projection_draft_v1';

    private const PACKAGE_ARTIFACT = 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01';

    private const QA_ARTIFACT = 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01';

    private const VISIBLE_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
    ];

    private const FORBIDDEN_ROUTE_PATTERNS = [
        '#/results?(?:/|$)#i',
        '#/orders?(?:/|$)#i',
        '#/share(?:/|$)#i',
        '#/pay(?:/|$)#i',
        '#/payment(?:/|$)#i',
        '#/history(?:/|$)#i',
        '#/private(?:/|$)#i',
        '#/account(?:/|$)#i',
        '#[?&](?:token|session|user|result_id|report_id|order_no)=#i',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $package, array $qa, string $sourceSha256, string $qaSha256, array $options = []): array
    {
        return $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, false, $options);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function write(array $package, array $qa, string $sourceSha256, string $qaSha256, array $options = []): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, true, $options));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildSummary(
        array $package,
        array $qa,
        string $sourceSha256,
        string $qaSha256,
        bool $write,
        array $options,
    ): array {
        $errors = $this->validatePackageAndQa($package, $qa);
        $warnings = array_values(array_filter((array) ($qa['warnings'] ?? []), static fn (mixed $warning): bool => is_string($warning)));
        $qaResultsByUrl = $this->qaResultsByUrl($qa);
        $recommendations = $this->recommendationsForOptions($package, $options, $write, $errors);

        $preparedRows = [];
        foreach ($recommendations as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_mbti64_target_url',
                    'message' => 'Unsupported MBTI64 public profile URL: '.((string) ($recommendation['target_url'] ?? '')),
                ];

                continue;
            }

            $target = $this->targetRecord($identity);
            $targetId = $target['id'] ?? null;
            $pageType = (string) $identity['page_type'];
            $targetField = $pageType === 'comparison' ? 'profile_id' : 'personality_profile_variant_id';

            if (! is_int($targetId)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'target_not_found',
                    'message' => 'CMS target record was not found for MBTI64 agent projection '.$identity['path'],
                ];
            }

            if ($this->containsForbiddenRoutePattern(json_encode($recommendation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position),
                    'code' => 'forbidden_public_route_pattern_present',
                    'message' => 'Recommendation contains a forbidden private route or sensitive query pattern.',
                ];
            }

            $existingRevision = is_int($targetId)
                ? $this->existingRevision($pageType, $targetField, $targetId, $sourceSha256)
                : null;
            $nextRevisionNo = is_int($targetId)
                ? $this->nextRevisionNo($pageType, $targetField, $targetId)
                : null;
            $qaResult = $qaResultsByUrl[(string) $recommendation['target_url']] ?? [];

            $preparedRows[] = [
                'position' => $position + 1,
                'url' => (string) $recommendation['target_url'],
                'path' => $identity['path'],
                'locale' => $identity['locale'],
                'page_type' => $pageType,
                'identity' => $identity,
                'target_table' => $pageType === 'comparison'
                    ? 'personality_profile_revisions'
                    : 'personality_profile_variant_revisions',
                'target_id' => $targetId,
                'snapshot_key' => self::SNAPSHOT_KEY,
                'source_sha256' => $sourceSha256,
                'qa_source_sha256' => $qaSha256,
                'existing_revision_id' => $existingRevision?->id !== null ? (int) $existingRevision->id : null,
                'existing_revision_no' => $existingRevision?->revision_no !== null ? (int) $existingRevision->revision_no : null,
                'next_revision_no' => $nextRevisionNo,
                'write_mode' => $write ? 'write_draft_revision' : 'dry_run',
                'action' => 'pending',
                'snapshot_preview' => $this->snapshotPayload($package, $qa, $recommendation, $identity, $sourceSha256, $qaSha256, $qaResult),
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $options), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($preparedRows),
                'variant_row_count' => $this->countRows($preparedRows, 'variant'),
                'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
                'rows' => $preparedRows,
                'errors' => $errors,
                'warnings' => $warnings,
            ]);
        }

        $created = 0;
        $skippedExisting = 0;
        if ($write) {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'skipped_existing';
                    $skippedExisting++;

                    continue;
                }

                $revision = $this->createRevision($preparedRow);
                $preparedRow['action'] = 'created';
                $preparedRow['created_revision_id'] = (int) $revision->id;
                $preparedRow['created_revision_no'] = (int) $revision->revision_no;
                $created++;
            }
            unset($preparedRow);
        } else {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'would_skip_existing';
                    $skippedExisting++;

                    continue;
                }

                $preparedRow['action'] = 'would_create';
            }
            unset($preparedRow);
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $options), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($preparedRows),
            'variant_row_count' => $this->countRows($preparedRows, 'variant'),
            'comparison_row_count' => $this->countRows($preparedRows, 'comparison'),
            'created_revision_count' => $created,
            'skipped_existing_count' => $skippedExisting,
            'would_create_revision_count' => $write ? 0 : count($preparedRows) - $skippedExisting,
            'writes_committed' => $write && $created > 0,
            'rows' => $preparedRows,
            'errors' => [],
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return list<array<string,string>>
     */
    private function validatePackageAndQa(array $package, array $qa): array
    {
        $errors = [];
        $summary = is_array($package['summary'] ?? null) ? $package['summary'] : [];
        $qaSummary = is_array($qa['summary'] ?? null) ? $qa['summary'] : [];

        if ((string) ($package['artifact'] ?? '') !== self::PACKAGE_ARTIFACT) {
            $errors[] = ['field' => 'artifact', 'code' => 'unsupported_package_artifact', 'message' => 'Unexpected package artifact.'];
        }
        if ((string) ($package['version'] ?? '') !== 'mbti64.agent_expansion_88_recommendations.v1') {
            $errors[] = ['field' => 'version', 'code' => 'unsupported_package_version', 'message' => 'Unexpected package version.'];
        }
        if ((string) ($package['status'] ?? '') !== 'pass_ready_for_qa_gates') {
            $errors[] = ['field' => 'status', 'code' => 'package_status_not_ready_for_qa', 'message' => 'Package must be ready for QA gates.'];
        }
        if (count($this->recommendations($package)) !== 88 || (int) ($summary['recommendation_count'] ?? -1) !== 88) {
            $errors[] = ['field' => 'recommendations', 'code' => 'unexpected_recommendation_count', 'message' => 'Expected exactly 88 expansion recommendations.'];
        }
        if ((int) ($summary['variant_pages'] ?? -1) !== 58 || (int) ($summary['comparison_pages'] ?? -1) !== 30) {
            $errors[] = ['field' => 'summary', 'code' => 'unexpected_page_type_counts', 'message' => 'Expected 58 variant and 30 comparison recommendations.'];
        }
        if ((string) ($qa['artifact'] ?? '') !== self::QA_ARTIFACT) {
            $errors[] = ['field' => 'qa.artifact', 'code' => 'unsupported_qa_artifact', 'message' => 'Unexpected QA artifact.'];
        }
        if ((string) ($qa['final_decision'] ?? '') !== 'PASS_READY_FOR_CMS_DRAFT') {
            $errors[] = ['field' => 'qa.final_decision', 'code' => 'qa_not_ready_for_cms_draft', 'message' => 'QA final decision must pass before draft write.'];
        }
        if ((int) ($qaSummary['checked_recommendation_count'] ?? -1) !== 88
            || (int) ($qaSummary['pass_ready_for_cms_draft_count'] ?? -1) !== 88
            || (int) ($qaSummary['blocked_count'] ?? -1) !== 0) {
            $errors[] = ['field' => 'qa.summary', 'code' => 'qa_summary_not_all_pass', 'message' => 'QA summary must show 88 pass and 0 blocked.'];
        }
        if ((array) ($qa['blockers'] ?? []) !== []) {
            $errors[] = ['field' => 'qa.blockers', 'code' => 'qa_blockers_present', 'message' => 'QA blockers must be empty.'];
        }

        $recommendationUrls = array_map(static fn (array $item): string => (string) ($item['target_url'] ?? ''), $this->recommendations($package));
        $qaUrls = array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            array_values(array_filter(
                is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [],
                static fn (mixed $item): bool => is_array($item)
            ))
        );
        sort($recommendationUrls);
        sort($qaUrls);
        if ($recommendationUrls !== $qaUrls) {
            $errors[] = ['field' => 'qa.page_results', 'code' => 'qa_url_set_mismatch', 'message' => 'QA page result URLs must match recommendation URLs.'];
        }

        foreach ($this->qaResultsByUrl($qa) as $url => $result) {
            if ((string) ($result['decision'] ?? '') !== 'PASS_READY_FOR_CMS_DRAFT' || (array) ($result['blockers'] ?? []) !== []) {
                $errors[] = ['field' => 'qa.page_results.'.$url, 'code' => 'qa_page_not_pass', 'message' => 'Every QA page result must pass with no blockers.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $package
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $package): array
    {
        return array_values(array_filter(
            is_array($package['recommendations'] ?? null) ? $package['recommendations'] : [],
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $options
     * @param  list<array<string,string>>  $errors
     * @return list<array<string,mixed>>
     */
    private function recommendationsForOptions(array $package, array $options, bool $write, array &$errors): array
    {
        $recommendations = $this->recommendations($package);
        if (! (bool) ($options['visible_query_backed_3'] ?? false)) {
            return $recommendations;
        }

        if ($write) {
            $errors[] = [
                'field' => 'options.visible_query_backed_3',
                'code' => 'visible_query_backed_subset_write_not_allowed',
                'message' => 'Visible query-backed 3-page subset is dry-run only.',
            ];
        }

        $allowed = array_fill_keys(self::VISIBLE_QUERY_BACKED_3_URLS, true);
        $subset = array_values(array_filter(
            $recommendations,
            static fn (array $item): bool => isset($allowed[(string) ($item['target_url'] ?? '')])
        ));
        $subsetUrls = array_map(static fn (array $item): string => (string) ($item['target_url'] ?? ''), $subset);
        sort($subsetUrls);
        $expectedUrls = self::VISIBLE_QUERY_BACKED_3_URLS;
        sort($expectedUrls);

        if ($subsetUrls !== $expectedUrls) {
            $errors[] = [
                'field' => 'recommendations',
                'code' => 'visible_query_backed_subset_required_urls_missing',
                'message' => 'The visible query-backed subset must resolve exactly the 3 approved URLs.',
            ];
        }

        return $subset;
    }

    /**
     * @param  array<string,mixed>  $qa
     * @return array<string,array<string,mixed>>
     */
    private function qaResultsByUrl(array $qa): array
    {
        $results = [];
        foreach (is_array($qa['page_results'] ?? null) ? $qa['page_results'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = (string) ($item['target_url'] ?? '');
            if ($url !== '') {
                $results[$url] = $item;
            }
        }

        return $results;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,string>|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $targetUrl = (string) ($recommendation['target_url'] ?? '');
        $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);
            $variantCode = strtoupper((string) $matches['variant']);

            return [
                'url' => $targetUrl,
                'path' => $path,
                'locale' => $locale,
                'page_type' => 'variant',
                'canonical_type_code' => $canonicalType,
                'variant_code' => $variantCode,
                'runtime_type_code' => $canonicalType.'-'.$variantCode,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-a-vs-\k<type>-t$#i', $path, $matches) === 1) {
            $locale = $this->localeFromPrefix((string) $matches['prefix']);
            $canonicalType = strtoupper((string) $matches['type']);

            return [
                'url' => $targetUrl,
                'path' => $path,
                'locale' => $locale,
                'page_type' => 'comparison',
                'canonical_type_code' => $canonicalType,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,string>  $identity
     * @return array{id?:int}
     */
    private function targetRecord(array $identity): array
    {
        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', (string) $identity['locale'])
            ->where('canonical_type_code', (string) $identity['canonical_type_code'])
            ->first();

        if (! $profile instanceof PersonalityProfile) {
            return [];
        }

        if (($identity['page_type'] ?? null) === 'comparison') {
            return ['id' => (int) $profile->id];
        }

        $variant = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', (string) ($identity['runtime_type_code'] ?? ''))
            ->first();

        return $variant instanceof PersonalityProfileVariant ? ['id' => (int) $variant->id] : [];
    }

    private function existingRevision(
        string $pageType,
        string $targetField,
        int $targetId,
        string $sourceSha256,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $storedSha = (string) ($snapshot[self::SNAPSHOT_KEY]['source']['source_sha256'] ?? '');
            if ($storedSha === $sourceSha256) {
                return $revision;
            }
        }

        return null;
    }

    private function nextRevisionNo(string $pageType, string $targetField, int $targetId): int
    {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        return ((int) $query->max('revision_no')) + 1;
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     */
    private function createRevision(array $preparedRow): PersonalityProfileRevision|PersonalityProfileVariantRevision
    {
        $pageType = (string) ($preparedRow['page_type'] ?? '');
        $targetId = (int) ($preparedRow['target_id'] ?? 0);
        $revisionNo = (int) ($preparedRow['next_revision_no'] ?? 0);
        $snapshot = is_array($preparedRow['snapshot_preview'] ?? null) ? $preparedRow['snapshot_preview'] : [];
        $note = $pageType === 'comparison'
            ? 'mbti64 agent projection comparison draft: '.((string) ($preparedRow['path'] ?? ''))
            : 'mbti64 agent projection variant draft: '.((string) ($preparedRow['path'] ?? ''));

        if ($pageType === 'comparison') {
            return PersonalityProfileRevision::query()->create([
                'profile_id' => $targetId,
                'revision_no' => $revisionNo,
                'snapshot_json' => $snapshot,
                'note' => $note,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);
        }

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $targetId,
            'revision_no' => $revisionNo,
            'snapshot_json' => $snapshot,
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,string>  $identity
     * @param  array<string,mixed>  $qaResult
     * @return array<string,mixed>
     */
    private function snapshotPayload(
        array $package,
        array $qa,
        array $recommendation,
        array $identity,
        string $sourceSha256,
        string $qaSha256,
        array $qaResult,
    ): array {
        $recommended = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];

        return [
            self::SNAPSHOT_KEY => [
                'source' => [
                    'artifact' => (string) ($package['artifact'] ?? ''),
                    'version' => (string) ($package['version'] ?? ''),
                    'status' => (string) ($package['status'] ?? ''),
                    'source_sha256' => $sourceSha256,
                    'qa_artifact' => (string) ($qa['artifact'] ?? ''),
                    'qa_source_sha256' => $qaSha256,
                    'qa_final_decision' => (string) ($qa['final_decision'] ?? ''),
                ],
                'identity' => $identity,
                'first_class_draft_fields' => [
                    'url' => (string) ($recommendation['target_url'] ?? ''),
                    'locale' => (string) ($recommendation['locale'] ?? ''),
                    'page_type' => (string) $identity['page_type'],
                    'seo' => [
                        'title' => (string) ($recommended['title']['recommended'] ?? ''),
                        'description' => (string) ($recommended['description']['recommended'] ?? ''),
                        'h1' => (string) ($recommended['h1']['recommended'] ?? ''),
                    ],
                    'content' => [
                        'quick_answer' => (string) ($recommended['quick_answer']['recommended'] ?? ''),
                    ],
                    'faq' => is_array($recommended['faq'] ?? null) ? array_values((array) $recommended['faq']) : [],
                    'internal_links' => is_array($recommended['internal_links'] ?? null) ? array_values((array) $recommended['internal_links']) : [],
                    'differentiation_notes' => is_array($recommended['differentiation_notes'] ?? null)
                        ? array_values((array) $recommended['differentiation_notes'])
                        : [],
                ],
                'structured_metadata' => [
                    'current_surface' => is_array($recommendation['current_surface'] ?? null) ? $recommendation['current_surface'] : [],
                    'observed_signal' => is_array($recommendation['observed_signal'] ?? null) ? $recommendation['observed_signal'] : [],
                    'reference_patterns_used' => is_array($recommendation['reference_patterns_used'] ?? null)
                        ? array_values((array) $recommendation['reference_patterns_used'])
                        : [],
                    'source_inputs' => is_array($recommendation['source_inputs'] ?? null) ? $recommendation['source_inputs'] : [],
                    'qa_result' => $qaResult,
                    'qa_summary' => is_array($qa['summary'] ?? null) ? $qa['summary'] : [],
                ],
                'safety_holds' => [
                    'draft_only' => true,
                    'publish_attempted' => false,
                    'index_attempted' => false,
                    'sitemap_llms_release_attempted' => false,
                    'search_release_attempted' => false,
                    'runtime_content_updated' => false,
                ],
                'raw_recommendation' => $recommendation,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write, array $options): array
    {
        return [
            'artifact' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_source_sha256' => $qaSha256,
            'qa_final_decision' => (string) ($qa['final_decision'] ?? ''),
            'snapshot_key' => self::SNAPSHOT_KEY,
            'dry_run' => ! $write,
            'write' => $write,
            'draft_only' => true,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'writes_committed' => false,
            'subset' => $this->subsetSummary($options),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function subsetSummary(array $options): array
    {
        $enabled = (bool) ($options['visible_query_backed_3'] ?? false);

        return [
            'mode' => $enabled ? 'visible_query_backed_3' : 'full_88',
            'enabled' => $enabled,
            'dry_run_only' => $enabled,
            'allowed_urls' => $enabled ? self::VISIBLE_QUERY_BACKED_3_URLS : [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function countRows(array $rows, string $pageType): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['page_type'] ?? null) === $pageType
        ));
    }

    private function localeFromPrefix(string $prefix): string
    {
        return strtolower($prefix) === 'zh' ? 'zh-CN' : 'en';
    }

    private function containsForbiddenRoutePattern(string $value): bool
    {
        foreach (self::FORBIDDEN_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
