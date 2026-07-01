<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\DB;

final class PersonalityAgentApprovalQueueWriter
{
    private const ALLOWED_FRAMEWORKS = ['mbti64', 'big_five', 'enneagram'];

    private const MBTI64_V85_V5_ARTIFACT = 'MBTI64-ZH32-EN32-V8_5-V5-BILINGUAL-PACKAGE-QA-01';

    private const MBTI64_V85_V5_PACKAGE_VERSION = 'mbti64_zh32_en32_v8_5_v5_bilingual_v1';

    private const MBTI64_V85_V5_PACKAGE_FILE_SHA256 = '38e1e325c8ed38c2181d8ce01d315b12c161690691dcc699605ecc186d72f286';

    private const MBTI64_V85_V5_QA_FILE_SHA256 = 'f0afbfcdb795050764951b2d1c08779b65bdc7f96cb2482ffba7fc957db398b7';

    private const MBTI64_V85_V5_PACKAGE_SHA256 = '937842c2a152f5943f641470f030cb88d08e318df5cfb40d24d6449d6999d8ad';

    private const MBTI64_V85_V5_QA_SHA256 = '9246f6f2644c218b6b5e4d678a2364b558a21bf2ee7d1d2cf96ef43fe21d6011';

    private const MBTI64_TYPES = [
        'enfj',
        'enfp',
        'entj',
        'entp',
        'esfj',
        'esfp',
        'estj',
        'estp',
        'infj',
        'infp',
        'intj',
        'intp',
        'isfj',
        'isfp',
        'istj',
        'istp',
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
     * @param  list<int>  $itemIds
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function approveItems(array $itemIds, string $framework, string $sourceSha256, string $qaSha256, array $metadata = []): array
    {
        return DB::transaction(function () use ($itemIds, $framework, $sourceSha256, $qaSha256, $metadata): array {
            return $this->approveItemsInTransaction($itemIds, $framework, $sourceSha256, $qaSha256, $metadata);
        });
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
        $errors = $this->validationErrors($package, $qa, $sourceSha256, $qaSha256, $queueItems);

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
     * @param  list<int>  $itemIds
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function approveItemsInTransaction(array $itemIds, string $framework, string $sourceSha256, string $qaSha256, array $metadata): array
    {
        $summary = $this->approvalBaseSummary($itemIds, $framework, $sourceSha256, $qaSha256, $metadata);
        $errors = $this->approvalValidationErrors($itemIds, $framework, $sourceSha256, $qaSha256);

        if ($errors !== []) {
            return array_merge($summary, [
                'ok' => false,
                'status' => 'fail',
                'matched_item_count' => 0,
                'approved_item_count' => 0,
                'skipped_existing_approved_item_count' => 0,
                'items' => [],
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $rows = DB::table('personality_agent_approval_items as items')
            ->join('personality_agent_approval_batches as batches', 'batches.id', '=', 'items.batch_id')
            ->whereIn('items.id', $itemIds)
            ->lockForUpdate()
            ->orderBy('items.id')
            ->get([
                'items.id',
                'items.batch_id',
                'items.framework',
                'items.target_url',
                'items.approval_state',
                'items.approved_at',
                'items.rejected_at',
                'items.blocked_reason',
                'items.qa_decision',
                'items.recommendation_sha256',
                'batches.source_package_sha256',
                'batches.qa_sha256',
            ]);

        $foundIds = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        sort($foundIds);
        if ($foundIds !== $itemIds) {
            return array_merge($summary, [
                'ok' => false,
                'status' => 'fail',
                'matched_item_count' => count($foundIds),
                'approved_item_count' => 0,
                'skipped_existing_approved_item_count' => 0,
                'items' => $this->approvalRows($rows),
                'errors' => [[
                    'field' => 'item_ids',
                    'code' => 'approval_item_ids_missing',
                    'message' => 'Every explicitly requested approval item ID must exist.',
                ]],
                'warnings' => [],
            ]);
        }

        $rowErrors = [];
        foreach ($rows as $row) {
            $rowErrors = array_merge($rowErrors, $this->approvalRowErrors($row, $framework, $sourceSha256, $qaSha256));
        }

        if ($rowErrors !== []) {
            return array_merge($summary, [
                'ok' => false,
                'status' => 'fail',
                'matched_item_count' => $rows->count(),
                'approved_item_count' => 0,
                'skipped_existing_approved_item_count' => 0,
                'items' => $this->approvalRows($rows),
                'errors' => $rowErrors,
                'warnings' => [],
            ]);
        }

        $states = array_values(array_unique($rows->map(static fn (object $row): string => (string) $row->approval_state)->all()));
        sort($states);

        if ($states === ['approved']) {
            return array_merge($summary, [
                'ok' => true,
                'status' => 'pass',
                'matched_item_count' => $rows->count(),
                'approved_item_count' => 0,
                'skipped_existing_approved_item_count' => $rows->count(),
                'writes_committed' => false,
                'items' => $this->approvalRows($rows),
                'errors' => [],
                'warnings' => [],
            ]);
        }

        if ($states !== ['pending']) {
            return array_merge($summary, [
                'ok' => false,
                'status' => 'fail',
                'matched_item_count' => $rows->count(),
                'approved_item_count' => 0,
                'skipped_existing_approved_item_count' => 0,
                'items' => $this->approvalRows($rows),
                'errors' => [[
                    'field' => 'approval_state',
                    'code' => 'approval_items_must_be_all_pending_or_all_approved',
                    'message' => 'Approval requests must not mix pending, approved, rejected, or blocked states.',
                ]],
                'warnings' => [],
            ]);
        }

        $now = now();
        $updated = DB::table('personality_agent_approval_items')
            ->whereIn('id', $itemIds)
            ->where('approval_state', 'pending')
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->update([
                'approval_state' => 'approved',
                'approved_at' => $now,
                'updated_at' => $now,
            ]);

        $approvedRows = DB::table('personality_agent_approval_items as items')
            ->join('personality_agent_approval_batches as batches', 'batches.id', '=', 'items.batch_id')
            ->whereIn('items.id', $itemIds)
            ->orderBy('items.id')
            ->get([
                'items.id',
                'items.batch_id',
                'items.framework',
                'items.target_url',
                'items.approval_state',
                'items.approved_at',
                'items.rejected_at',
                'items.blocked_reason',
                'items.qa_decision',
                'items.recommendation_sha256',
                'batches.source_package_sha256',
                'batches.qa_sha256',
            ]);

        return array_merge($summary, [
            'ok' => $updated === count($itemIds),
            'status' => $updated === count($itemIds) ? 'pass' : 'fail',
            'matched_item_count' => $approvedRows->count(),
            'approved_item_count' => $updated,
            'skipped_existing_approved_item_count' => 0,
            'writes_committed' => $updated > 0,
            'items' => $this->approvalRows($approvedRows),
            'errors' => $updated === count($itemIds) ? [] : [[
                'field' => 'approval_items',
                'code' => 'approval_update_count_mismatch',
                'message' => 'Approval update count did not match the requested item count.',
            ]],
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
     * @param  list<int>  $itemIds
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function approvalBaseSummary(array $itemIds, string $framework, string $sourceSha256, string $qaSha256, array $metadata): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-APPROVAL-QUEUE-APPROVE-CONTRACT-01',
            'status' => 'fail',
            'ok' => false,
            'framework' => $framework,
            'item_ids' => $itemIds,
            'source_package_sha256' => $sourceSha256,
            'qa_sha256' => $qaSha256,
            'metadata' => $metadata,
            'dry_run' => false,
            'write' => false,
            'approve' => true,
            'writes_attempted' => true,
            'writes_committed' => false,
            'approval_state_mutation_attempted' => true,
            'cms_write_attempted' => false,
            'cms_mutation_attempted' => false,
            'cms_live_promotion_attempted' => false,
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
     * @param  list<int>  $itemIds
     * @return list<array<string,string>>
     */
    private function approvalValidationErrors(array $itemIds, string $framework, string $sourceSha256, string $qaSha256): array
    {
        $errors = [];
        if ($itemIds === []) {
            $errors[] = [
                'field' => 'item_ids',
                'code' => 'approval_item_ids_required',
                'message' => 'Explicit approval item IDs are required.',
            ];
        }
        if (! in_array($framework, self::ALLOWED_FRAMEWORKS, true)) {
            $errors[] = [
                'field' => 'framework',
                'code' => 'unsupported_framework',
                'message' => 'A supported framework lock is required.',
            ];
        }
        if (! $this->isSha256($sourceSha256)) {
            $errors[] = [
                'field' => 'source_package_sha256',
                'code' => 'source_package_sha256_required',
                'message' => 'A valid source package SHA256 lock is required.',
            ];
        }
        if (! $this->isSha256($qaSha256)) {
            $errors[] = [
                'field' => 'qa_sha256',
                'code' => 'qa_sha256_required',
                'message' => 'A valid QA SHA256 lock is required.',
            ];
        }

        return $errors;
    }

    /**
     * @return list<array<string,string>>
     */
    private function approvalRowErrors(object $row, string $framework, string $sourceSha256, string $qaSha256): array
    {
        $errors = [];
        $id = (string) $row->id;
        if ((string) $row->framework !== $framework) {
            $errors[] = [
                'field' => 'framework',
                'code' => 'approval_item_framework_mismatch',
                'message' => 'Approval item '.$id.' framework does not match the requested framework lock.',
            ];
        }
        if ((string) $row->source_package_sha256 !== $sourceSha256) {
            $errors[] = [
                'field' => 'source_package_sha256',
                'code' => 'approval_item_source_sha256_mismatch',
                'message' => 'Approval item '.$id.' source package hash does not match.',
            ];
        }
        if ((string) $row->qa_sha256 !== $qaSha256) {
            $errors[] = [
                'field' => 'qa_sha256',
                'code' => 'approval_item_qa_sha256_mismatch',
                'message' => 'Approval item '.$id.' QA hash does not match.',
            ];
        }
        if ((string) $row->blocked_reason !== '') {
            $errors[] = [
                'field' => 'blocked_reason',
                'code' => 'approval_item_blocked',
                'message' => 'Approval item '.$id.' has a blocked reason and cannot be approved.',
            ];
        }
        if (! $this->qaDecisionPasses((string) $row->qa_decision)) {
            $errors[] = [
                'field' => 'qa_decision',
                'code' => 'approval_item_qa_not_pass',
                'message' => 'Approval item '.$id.' QA decision is not pass-ready.',
            ];
        }
        if ((string) $row->recommendation_sha256 === '') {
            $errors[] = [
                'field' => 'recommendation_sha256',
                'code' => 'approval_item_recommendation_sha_missing',
                'message' => 'Approval item '.$id.' recommendation hash is missing.',
            ];
        }
        if ((string) $row->approval_state === 'rejected' || $row->rejected_at !== null) {
            $errors[] = [
                'field' => 'approval_state',
                'code' => 'approval_item_rejected',
                'message' => 'Approval item '.$id.' is rejected and cannot be approved.',
            ];
        }
        if ((string) $row->approval_state === 'approved' && $row->approved_at === null) {
            $errors[] = [
                'field' => 'approved_at',
                'code' => 'approval_item_approved_timestamp_missing',
                'message' => 'Approval item '.$id.' is approved but approved_at is missing.',
            ];
        }
        if (! in_array((string) $row->approval_state, ['pending', 'approved'], true)) {
            $errors[] = [
                'field' => 'approval_state',
                'code' => 'approval_item_state_not_approvable',
                'message' => 'Approval item '.$id.' must be pending or already approved.',
            ];
        }

        return $errors;
    }

    /**
     * @param  \Illuminate\Support\Collection<int,object>  $rows
     * @return list<array<string,mixed>>
     */
    private function approvalRows($rows): array
    {
        return $rows
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'batch_id' => (int) $row->batch_id,
                'framework' => (string) $row->framework,
                'target_url' => (string) $row->target_url,
                'approval_state' => (string) $row->approval_state,
                'approved_at' => $row->approved_at === null ? null : (string) $row->approved_at,
                'rejected_at' => $row->rejected_at === null ? null : (string) $row->rejected_at,
                'blocked_reason' => $row->blocked_reason === null ? null : (string) $row->blocked_reason,
                'qa_decision' => (string) $row->qa_decision,
                'recommendation_sha256' => (string) $row->recommendation_sha256,
                'source_package_sha256' => (string) $row->source_package_sha256,
                'qa_sha256' => (string) $row->qa_sha256,
            ])
            ->values()
            ->all();
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
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
        $qaDecision = (string) ($qaResult['decision'] ?? $qaResult['qa_decision'] ?? $qaResult['status'] ?? $qaResult['qa_status'] ?? '');
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

        $decision = (string) ($qaResult['decision'] ?? $qaResult['qa_decision'] ?? $qaResult['status'] ?? $qaResult['qa_status'] ?? '');
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
            'PASS_READY_FOR_APPROVAL_HANDOFF',
            'PASS_READY_FOR_EDITORIAL_REVIEW_AND_APPROVAL_QUEUE_REPAIR',
            'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
            'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC',
            'READY_QUERY_BACKED_LOW_RISK_DRAFT_REVIEW',
        ], true);
    }

    /**
     * @param  list<array<string,mixed>>  $queueItems
     * @return list<array<string,string>>
     */
    private function validationErrors(array $package, array $qa, string $sourceSha256, string $qaSha256, array $queueItems): array
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

        $isMbti64V85V5ContractCandidate = $this->isMbti64V85V5ContractCandidate($package, $qa);
        if (! $isMbti64V85V5ContractCandidate && $this->hasFapApiArtifactSyncDecision($qa)) {
            $errors[] = [
                'field' => 'qa.final_decision',
                'code' => 'fap_api_artifact_sync_decision_requires_mbti64_v85_v5_contract',
                'message' => 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC is only accepted for the locked MBTI64 V8.5/V5 bilingual package contract.',
            ];
        }

        if ($isMbti64V85V5ContractCandidate) {
            $errors = array_merge(
                $errors,
                $this->mbti64V85V5ContractErrors($package, $qa, $sourceSha256, $qaSha256, $queueItems)
            );
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     */
    private function isMbti64V85V5ContractCandidate(array $package, array $qa): bool
    {
        return (string) ($package['artifact'] ?? '') === self::MBTI64_V85_V5_ARTIFACT
            || (string) ($package['package_version'] ?? '') === self::MBTI64_V85_V5_PACKAGE_VERSION
            || (string) ($qa['artifact'] ?? '') === self::MBTI64_V85_V5_ARTIFACT
            || (string) ($qa['input_package_sha256'] ?? '') === self::MBTI64_V85_V5_PACKAGE_SHA256;
    }

    /**
     * @param  array<string,mixed>  $qa
     */
    private function hasFapApiArtifactSyncDecision(array $qa): bool
    {
        if ((string) ($qa['final_decision'] ?? $qa['decision'] ?? '') === 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC') {
            return true;
        }

        foreach ($this->qaResultsByUrl($qa) as $qaResult) {
            $decision = (string) ($qaResult['decision'] ?? $qaResult['qa_decision'] ?? $qaResult['status'] ?? $qaResult['qa_status'] ?? '');
            if ($decision === 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @param  list<array<string,mixed>>  $queueItems
     * @return list<array<string,string>>
     */
    private function mbti64V85V5ContractErrors(
        array $package,
        array $qa,
        string $sourceSha256,
        string $qaSha256,
        array $queueItems
    ): array {
        $errors = [];
        $expectedUrls = $this->mbti64V85V5ExpectedUrls();
        $recommendations = $this->recommendations($package);
        $qaResultsByUrl = $this->qaResultsByUrl($qa);
        $recommendationUrls = $this->sortedUniqueUrls($recommendations);
        $qaUrls = $this->sortedUniqueUrls(array_values($qaResultsByUrl));
        $queueUrls = $this->sortedUniqueUrls($queueItems);

        $expectedScalars = [
            ['field' => 'package.artifact', 'actual' => (string) ($package['artifact'] ?? ''), 'expected' => self::MBTI64_V85_V5_ARTIFACT, 'code' => 'mbti64_v85_v5_package_artifact_mismatch'],
            ['field' => 'package.package_version', 'actual' => (string) ($package['package_version'] ?? ''), 'expected' => self::MBTI64_V85_V5_PACKAGE_VERSION, 'code' => 'mbti64_v85_v5_package_version_mismatch'],
            ['field' => 'package.package_sha256', 'actual' => (string) ($package['package_sha256'] ?? ''), 'expected' => self::MBTI64_V85_V5_PACKAGE_SHA256, 'code' => 'mbti64_v85_v5_embedded_package_sha_mismatch'],
            ['field' => 'qa.artifact', 'actual' => (string) ($qa['artifact'] ?? ''), 'expected' => self::MBTI64_V85_V5_ARTIFACT, 'code' => 'mbti64_v85_v5_qa_artifact_mismatch'],
            ['field' => 'qa.final_decision', 'actual' => (string) ($qa['final_decision'] ?? ''), 'expected' => 'PASS_READY_FOR_FAP_API_ARTIFACT_SYNC', 'code' => 'mbti64_v85_v5_qa_final_decision_mismatch'],
            ['field' => 'qa.input_package_sha256', 'actual' => (string) ($qa['input_package_sha256'] ?? ''), 'expected' => self::MBTI64_V85_V5_PACKAGE_SHA256, 'code' => 'mbti64_v85_v5_qa_input_package_sha_mismatch'],
            ['field' => 'qa.qa_sha256', 'actual' => (string) ($qa['qa_sha256'] ?? ''), 'expected' => self::MBTI64_V85_V5_QA_SHA256, 'code' => 'mbti64_v85_v5_embedded_qa_sha_mismatch'],
            ['field' => 'source_package_sha256', 'actual' => $sourceSha256, 'expected' => self::MBTI64_V85_V5_PACKAGE_FILE_SHA256, 'code' => 'mbti64_v85_v5_package_file_sha_mismatch'],
            ['field' => 'qa_sha256', 'actual' => $qaSha256, 'expected' => self::MBTI64_V85_V5_QA_FILE_SHA256, 'code' => 'mbti64_v85_v5_qa_file_sha_mismatch'],
        ];

        foreach ($expectedScalars as $lock) {
            if ($lock['actual'] !== $lock['expected']) {
                $errors[] = [
                    'field' => $lock['field'],
                    'code' => $lock['code'],
                    'message' => 'MBTI64 V8.5/V5 approval queue contract requires the exact synced artifact lock.',
                ];
            }
        }

        if ((int) ($package['target_count'] ?? 0) !== 64 || count($recommendations) !== 64) {
            $errors[] = [
                'field' => 'package.recommendations',
                'code' => 'mbti64_v85_v5_recommendation_count_mismatch',
                'message' => 'MBTI64 V8.5/V5 approval queue contract requires exactly 64 recommendation rows.',
            ];
        }

        if (count($qaResultsByUrl) !== 64) {
            $errors[] = [
                'field' => 'qa.page_results',
                'code' => 'mbti64_v85_v5_qa_count_mismatch',
                'message' => 'MBTI64 V8.5/V5 approval queue contract requires exactly 64 QA rows.',
            ];
        }

        if ($recommendationUrls !== $expectedUrls || $qaUrls !== $expectedUrls || $queueUrls !== $expectedUrls) {
            $errors[] = [
                'field' => 'target_url',
                'code' => 'mbti64_v85_v5_target_set_mismatch',
                'message' => 'MBTI64 V8.5/V5 approval queue contract only allows the fixed 64 A/T variant URLs.',
            ];
        }

        foreach ($recommendations as $recommendation) {
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '');
            if ((string) ($recommendation['framework'] ?? '') !== 'mbti64'
                || preg_match('#^/(en|zh)/personality/[a-z]{4}-(?:a|t)$#i', $path) !== 1
                || str_contains($path, '-a-vs-')
            ) {
                $errors[] = [
                    'field' => 'package.recommendations.target_url',
                    'code' => 'mbti64_v85_v5_non_variant_target_present',
                    'message' => 'MBTI64 V8.5/V5 approval queue contract rejects comparison or non-MBTI64 targets.',
                ];

                break;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function mbti64V85V5ExpectedUrls(): array
    {
        $urls = [];
        foreach (['en', 'zh'] as $locale) {
            foreach (self::MBTI64_TYPES as $type) {
                foreach (['a', 't'] as $variant) {
                    $urls[] = 'https://fermatmind.com/'.$locale.'/personality/'.$type.'-'.$variant;
                }
            }
        }

        sort($urls);

        return $urls;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function sortedUniqueUrls(array $items): array
    {
        $urls = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => (string) ($item['target_url'] ?? ''),
            $items
        ))));
        sort($urls);

        return $urls;
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
