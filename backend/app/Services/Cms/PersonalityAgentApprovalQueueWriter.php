<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\DB;

final class PersonalityAgentApprovalQueueWriter
{
    private const ALLOWED_FRAMEWORKS = ['mbti64', 'big_five', 'enneagram'];

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
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function plan(array $package, array $qa, string $sourceSha256, string $qaSha256, array $metadata = []): array
    {
        return $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, false, $metadata);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function write(array $package, array $qa, string $sourceSha256, string $qaSha256, array $metadata = []): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, true, $metadata));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write, array $metadata): array
    {
        $qaResultsByUrl = $this->qaResultsByUrl($qa);
        $blockedItems = [];
        $queueItems = [];

        foreach ($this->recommendations($package) as $position => $recommendation) {
            $qaResult = $qaResultsByUrl[(string) ($recommendation['target_url'] ?? '')] ?? [];
            $prepared = $this->prepareItem($recommendation, $qaResult, $position + 1);
            if (($prepared['blocked_reason'] ?? null) !== null) {
                $blockedItems[] = $prepared;

                continue;
            }

            $queueItems[] = $prepared;
        }

        $framework = $this->frameworkForBatch($queueItems);
        $errors = $this->validationErrors($package, $qa, $queueItems);

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $metadata), [
                'ok' => false,
                'status' => 'fail',
                'framework' => $framework,
                'planned_item_count' => count($queueItems),
                'blocked_item_count' => count($blockedItems),
                'queued_item_count' => 0,
                'created_batch_id' => null,
                'created_item_count' => 0,
                'skipped_existing_item_count' => 0,
                'items' => $queueItems,
                'blocked_items' => $blockedItems,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $existingBatch = $this->existingBatch($framework, $sourceSha256, $qaSha256);
        if ($existingBatch !== null) {
            $existingItemCount = (int) DB::table('personality_agent_approval_items')
                ->where('batch_id', (int) $existingBatch->id)
                ->count();

            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $metadata), [
                'ok' => true,
                'status' => 'pass',
                'framework' => $framework,
                'planned_item_count' => count($queueItems),
                'blocked_item_count' => count($blockedItems),
                'queued_item_count' => $write ? $existingItemCount : 0,
                'created_batch_id' => null,
                'existing_batch_id' => (int) $existingBatch->id,
                'created_item_count' => 0,
                'skipped_existing_item_count' => $existingItemCount,
                'items' => $queueItems,
                'blocked_items' => $blockedItems,
                'errors' => [],
                'warnings' => [],
            ]);
        }

        $createdBatchId = null;
        $createdItems = 0;
        if ($write) {
            $createdBatchId = $this->createBatch($package, $qa, $framework, $sourceSha256, $qaSha256, $metadata, $queueItems, $blockedItems);
            foreach ($queueItems as $item) {
                $this->createItem($createdBatchId, $item);
                $createdItems++;
            }
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write, $metadata), [
            'ok' => true,
            'status' => 'pass',
            'framework' => $framework,
            'planned_item_count' => count($queueItems),
            'blocked_item_count' => count($blockedItems),
            'queued_item_count' => $write ? $createdItems : 0,
            'created_batch_id' => $createdBatchId,
            'existing_batch_id' => null,
            'created_item_count' => $createdItems,
            'skipped_existing_item_count' => 0,
            'writes_committed' => $write && $createdItems > 0,
            'items' => $queueItems,
            'blocked_items' => $blockedItems,
            'errors' => [],
            'warnings' => [],
        ]);
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
     * @param  array<string,mixed>  $qa
     * @return array<string,array<string,mixed>>
     */
    private function qaResultsByUrl(array $qa): array
    {
        $sources = [
            $qa['page_results'] ?? null,
            $qa['evaluations'] ?? null,
            $qa['results'] ?? null,
            $qa['items'] ?? null,
            $qa['recommendations'] ?? null,
        ];
        $results = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $url = (string) ($item['target_url'] ?? $item['url'] ?? '');
                if ($url !== '') {
                    $results[$url] = $item;
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $qaResult
     * @return array<string,mixed>
     */
    private function prepareItem(array $recommendation, array $qaResult, int $position): array
    {
        $identity = $this->identityForRecommendation($recommendation);
        $recommendationJson = $this->jsonString($recommendation);
        $qaDecision = (string) ($qaResult['decision'] ?? $qaResult['status'] ?? $qaResult['qa_status'] ?? '');
        $blockedReason = $this->blockedReason($recommendation, $qaResult, $identity, $recommendationJson);

        return [
            'position' => $position,
            'framework' => (string) ($recommendation['framework'] ?? ''),
            'target_url' => (string) ($recommendation['target_url'] ?? ''),
            'path' => (string) ($identity['path'] ?? ''),
            'locale' => (string) ($identity['locale'] ?? $recommendation['locale'] ?? ''),
            'entity_type' => (string) ($recommendation['entity_type'] ?? $identity['entity_type'] ?? ''),
            'page_type' => (string) ($identity['page_type'] ?? $recommendation['page_type'] ?? ''),
            'recommendation_id' => (string) ($recommendation['recommendation_id'] ?? ''),
            'recommendation_sha256' => hash('sha256', $recommendationJson),
            'qa_decision' => $qaDecision,
            'approval_state' => 'pending',
            'blocked_reason' => $blockedReason,
            'safety_holds' => $this->safetyHolds(),
            'recommendation' => $recommendation,
            'qa_result' => $qaResult,
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array<string,mixed>  $qaResult
     * @param  array<string,mixed>|null  $identity
     */
    private function blockedReason(array $recommendation, array $qaResult, ?array $identity, string $recommendationJson): ?string
    {
        if ($identity === null) {
            return 'unsupported_or_private_target_url';
        }

        $framework = (string) ($recommendation['framework'] ?? '');
        if (! in_array($framework, self::ALLOWED_FRAMEWORKS, true)) {
            return 'unsupported_framework';
        }

        if ($this->containsForbiddenRoutePattern((string) ($recommendation['target_url'] ?? ''))
            || $this->containsForbiddenRoutePattern($recommendationJson)) {
            return 'forbidden_private_route_pattern';
        }

        if ($qaResult === []) {
            return 'qa_result_missing';
        }

        $decision = (string) ($qaResult['decision'] ?? $qaResult['status'] ?? $qaResult['qa_status'] ?? '');
        if (! $this->qaDecisionPasses($decision)) {
            return 'qa_not_pass';
        }

        if ((array) ($qaResult['blockers'] ?? []) !== []) {
            return 'qa_blockers_present';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,string>|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $targetUrl = (string) ($recommendation['target_url'] ?? '');
        $host = (string) (parse_url($targetUrl, PHP_URL_HOST) ?: '');
        $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
        if ($host !== 'fermatmind.com') {
            return null;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path, $matches) === 1) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'page_type' => 'personality_profile_variant',
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-a-vs-\k<type>-t$#i', $path, $matches) === 1) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'page_type' => 'personality_profile_comparison',
            ];
        }

        if ($this->isEnneagramPublicContentAssetPath($path, $matches)) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => 'enneagram_public_content_asset',
                'page_type' => 'personality_public_content_asset',
            ];
        }

        if ($this->isBigFivePublicContentAssetPath($path, $matches)) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => 'big_five_public_content_asset',
                'page_type' => 'personality_public_content_asset',
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/[a-z0-9-]+$#i', $path, $matches) === 1) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'page_type' => (string) ($recommendation['page_type'] ?? 'personality_profile'),
            ];
        }

        return null;
    }

    /**
     * @param  array<string,string>  $matches
     */
    private function isEnneagramPublicContentAssetPath(string $path, ?array &$matches = null): bool
    {
        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram$#i', $path, $matches) === 1) {
            return true;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/centers/(?:gut|heart|head)$#i', $path, $matches) === 1) {
            return true;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-[1-9]$#i', $path, $matches) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,string>  $matches
     */
    private function isBigFivePublicContentAssetPath(string $path, ?array &$matches = null): bool
    {
        if (preg_match('#^/(?<prefix>en|zh)/personality/big-five$#i', $path, $matches) === 1) {
            return true;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/big-five/(?:facets|(?:high|low)-(?:agreeableness|conscientiousness|extraversion|neuroticism|openness)|agreeableness|conscientiousness|emotional-stability|extraversion|neuroticism|openness)$#i', $path, $matches) === 1) {
            return true;
        }

        return false;
    }

    private function localeFromPrefix(string $prefix): string
    {
        return $prefix === 'zh' ? 'zh-CN' : 'en';
    }

    private function qaDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'pass',
            'PASS',
            'PASS_READY_FOR_CMS_DRAFT',
            'PASS_READY_FOR_APPROVAL_QUEUE',
            'PASS_READY_FOR_APPROVAL_REVIEW',
            'READY_QUERY_BACKED_LOW_RISK_DRAFT_REVIEW',
        ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $queueItems
     * @return list<array<string,string>>
     */
    private function validationErrors(array $package, array $qa, array $queueItems): array
    {
        $errors = [];
        if ($this->recommendations($package) === []) {
            $errors[] = [
                'field' => 'package.recommendations',
                'code' => 'recommendations_missing',
                'message' => 'The package must contain recommendation rows.',
            ];
        }
        if ($this->qaResultsByUrl($qa) === []) {
            $errors[] = [
                'field' => 'qa.page_results',
                'code' => 'qa_results_missing',
                'message' => 'The QA artifact must contain per-target QA rows.',
            ];
        }
        if ($queueItems === []) {
            $errors[] = [
                'field' => 'recommendations',
                'code' => 'no_queueable_qa_passed_items',
                'message' => 'No QA-passed public personality recommendations are eligible for approval queue creation.',
            ];
        }

        return $errors;
    }

    /**
     * @param  list<array<string,mixed>>  $queueItems
     */
    private function frameworkForBatch(array $queueItems): string
    {
        $frameworks = array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['framework'] ?? ''),
            $queueItems
        )));
        sort($frameworks);

        return count($frameworks) === 1 ? $frameworks[0] : 'mixed';
    }

    private function existingBatch(string $framework, string $sourceSha256, string $qaSha256): ?object
    {
        return DB::table('personality_agent_approval_batches')
            ->where('framework', $framework)
            ->where('source_package_sha256', $sourceSha256)
            ->where('qa_sha256', $qaSha256)
            ->first();
    }

    /**
     * @param  list<array<string,mixed>>  $queueItems
     * @param  list<array<string,mixed>>  $blockedItems
     */
    private function createBatch(
        array $package,
        array $qa,
        string $framework,
        string $sourceSha256,
        string $qaSha256,
        array $metadata,
        array $queueItems,
        array $blockedItems,
    ): int {
        $now = now();

        return (int) DB::table('personality_agent_approval_batches')->insertGetId([
            'framework' => $framework,
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_artifact_path' => (string) ($metadata['package_path'] ?? ''),
            'source_package_sha256' => $sourceSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_artifact_path' => (string) ($metadata['qa_path'] ?? ''),
            'qa_sha256' => $qaSha256,
            'status' => 'pending_review',
            'planned_item_count' => count($queueItems),
            'queued_item_count' => count($queueItems),
            'blocked_item_count' => count($blockedItems),
            'safety_holds_json' => $this->jsonString($this->safetyHolds()),
            'summary_json' => $this->jsonString([
                'source_version' => (string) ($package['version'] ?? ''),
                'source_status' => (string) ($package['status'] ?? ''),
                'qa_final_decision' => (string) ($qa['final_decision'] ?? $qa['decision'] ?? ''),
                'blocked_items' => array_map(
                    static fn (array $item): array => [
                        'target_url' => (string) ($item['target_url'] ?? ''),
                        'blocked_reason' => (string) ($item['blocked_reason'] ?? ''),
                    ],
                    $blockedItems
                ),
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function createItem(int $batchId, array $item): void
    {
        $now = now();
        DB::table('personality_agent_approval_items')->insert([
            'batch_id' => $batchId,
            'framework' => (string) ($item['framework'] ?? ''),
            'target_url' => (string) ($item['target_url'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
            'locale' => (string) ($item['locale'] ?? ''),
            'page_type' => (string) ($item['page_type'] ?? ''),
            'recommendation_id' => (string) ($item['recommendation_id'] ?? ''),
            'recommendation_sha256' => (string) ($item['recommendation_sha256'] ?? ''),
            'qa_decision' => (string) ($item['qa_decision'] ?? ''),
            'approval_state' => 'pending',
            'approved_at' => null,
            'rejected_at' => null,
            'blocked_reason' => null,
            'safety_holds_json' => $this->jsonString($this->safetyHolds()),
            'recommendation_json' => $this->jsonString($item['recommendation'] ?? []),
            'qa_json' => $this->jsonString($item['qa_result'] ?? []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write, array $metadata): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01',
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'source_package_sha256' => $sourceSha256,
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'qa_sha256' => $qaSha256,
            'qa_final_decision' => (string) ($qa['final_decision'] ?? $qa['decision'] ?? ''),
            'package_path' => (string) ($metadata['package_path'] ?? ''),
            'qa_path' => (string) ($metadata['qa_path'] ?? ''),
            'dry_run' => ! $write,
            'write' => $write,
            'writes_attempted' => $write,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'cms_mutation_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'live_content_updated' => false,
            'approval_state_written_only' => true,
            'safety_holds' => $this->safetyHolds(),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function safetyHolds(): array
    {
        return [
            'approval_queue_only' => true,
            'cms_write_attempted' => false,
            'cms_mutation_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'live_content_updated' => false,
        ];
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

    /**
     * @param  mixed  $value
     */
    private function jsonString($value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
