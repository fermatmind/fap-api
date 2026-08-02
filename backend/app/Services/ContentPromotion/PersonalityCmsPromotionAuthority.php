<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Http\Controllers\API\V0_5\Cms\PersonalityPublicContentAssetController;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Exact-package authority for the text-only public personality CMS family.
 * It deliberately uses the existing asset/revision pointers rather than a
 * parallel content store or any of the historical operator commands.
 */
/** @review-surface personality_public_content_asset_revision_review */
final class PersonalityCmsPromotionAuthority
{
    /** @var list<string> */
    private const EDITORIAL_FIELDS = ['title', 'summary', 'content_sections_json', 'seo_json', 'faq_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'internal_links_json', 'source_package', 'source_hash'];

    private const JSON_EDITORIAL_FIELDS = ['content_sections_json', 'seo_json', 'faq_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'internal_links_json'];

    private const PRIVATE_ROUTE_PATTERN = '~(?<![A-Za-z0-9_-])/(?:attempt|attempts|result|results|report|reports|order|orders|payment|pay|share|shares|recovery|account|private)(?:/|[?#\s)"\']|$)~i';

    private const SENSITIVE_QUERY_PATTERN = '/(?:[?&]|^)(?:attempt_id|result_id|order_id|payment_id|token|recovery_token|score|user_id|report_id)=/i';

    public function __construct(
        private readonly PersonalityPublicAssetReadModelCache $cache,
        private readonly PersonalityCmsPromotionReviewBinder $reviews,
        private readonly PersonalityPublicContentAssetController $payloads,
    ) {}

    /** @return array{framework:string,targets:list<array<string,mixed>>,package_sha256:string} */
    public function inspect(PromotionContext $context): array
    {
        $framework = $this->framework($context);
        $manifestPath = $context->packageDirectory.'/manifest.json';
        $manifestBytes = @file_get_contents($manifestPath);
        if (! is_string($manifestBytes)) {
            throw new DomainException('personality_promotion_manifest_missing');
        }
        $manifest = $this->decode($manifestBytes, 'personality_promotion_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== 'fermatmind.personality_cms_promotion.v2'
            || ($manifest['lane'] ?? null) !== $context->lane
            || ($manifest['subscope'] ?? null) !== $context->subscope
            || ($manifest['framework'] ?? null) !== $framework
            || ($manifest['locale'] ?? null) !== 'en'
            || ! is_array($manifest['payloads'] ?? null)) {
            throw new DomainException('personality_promotion_manifest_contract_invalid');
        }
        if (isset($manifest['permissions']) && $manifest['permissions'] !== []) {
            throw new DomainException('personality_promotion_permissions_invalid');
        }
        $chain = '';
        $payloads = $manifest['payloads'];
        usort($payloads, static fn (array $a, array $b): int => ((string) ($a['path'] ?? '')) <=> ((string) ($b['path'] ?? '')));
        $assets = null;
        $payloadPaths = [];
        foreach ($payloads as $payload) {
            $relative = trim((string) ($payload['path'] ?? ''));
            $expected = strtolower(trim((string) ($payload['sha256'] ?? '')));
            if ($relative === '' || str_contains($relative, '..') || preg_match('/\A[0-9a-f]{64}\z/', $expected) !== 1) {
                throw new DomainException('personality_promotion_payload_declaration_invalid');
            }
            $bytes = @file_get_contents($context->packageDirectory.'/'.$relative);
            if (! is_string($bytes) || ! hash_equals($expected, hash('sha256', $bytes))) {
                throw new DomainException('personality_promotion_payload_hash_invalid');
            }
            $payloadPaths[] = $relative;
            $chain .= $relative."\n".$expected."\n";
            if ($relative === 'assets.json') {
                $assets = $this->decode($bytes, 'personality_promotion_assets_invalid');
            }
        }
        if ($payloadPaths !== ['assets.json']) {
            throw new DomainException('personality_promotion_payload_contract_invalid');
        }
        $manifestForChain = $manifest;
        unset($manifestForChain['package_sha256']);
        $actualSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($manifestForChain))."\n".$chain);
        if (! hash_equals($context->packageSha256, $actualSha) || ! hash_equals($actualSha, strtolower((string) ($manifest['package_sha256'] ?? '')))) {
            throw new DomainException('personality_promotion_package_sha_invalid');
        }
        $rows = is_array($assets['assets'] ?? null) ? $assets['assets'] : null;
        if (! is_array($rows) || count($rows) !== $context->expectedRowCount || (int) ($manifest['expected_row_count'] ?? -1) !== $context->expectedRowCount) {
            throw new DomainException('personality_promotion_target_count_invalid');
        }
        $seen = [];
        $targets = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new DomainException('personality_promotion_target_invalid');
            }
            $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
            $key = implode(':', [(string) ($identity['framework'] ?? ''), (string) ($identity['entity_type'] ?? ''), (string) ($identity['entity_key'] ?? ''), (string) ($identity['locale'] ?? '')]);
            $snapshot = is_array($row['snapshot'] ?? null) ? $row['snapshot'] : [];
            if ($key === ':::' || isset($seen[$key]) || ($identity['framework'] ?? null) !== $framework || ($identity['locale'] ?? null) !== 'en') {
                throw new DomainException('personality_promotion_target_identity_invalid');
            }
            $seen[$key] = true;
            $this->assertNoPrivateFields($row);
            $this->assertTextOnlySnapshot($snapshot);
            $sourceHash = hash('sha256', PromotionContextFactory::canonicalJson($row));
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where([
                'org_id' => 0,
                'framework' => $framework,
                'entity_type' => (string) $identity['entity_type'],
                'entity_key' => (string) $identity['entity_key'],
                'locale' => 'en',
            ])->first();
            if (! $asset instanceof PersonalityPublicContentAsset || ! (bool) $asset->is_public || (string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED) {
                throw new DomainException('personality_promotion_target_not_public_authority');
            }
            $candidate = $asset->replicate();
            $candidate->forceFill($snapshot);
            if (! $this->payloads->detailPayloadWithinBudgetForAsset($candidate)) {
                throw new DomainException('personality_promotion_public_payload_budget_exceeded');
            }
            $targets[] = ['asset' => $asset, 'identity' => $identity, 'asset_key' => $key, 'snapshot' => $snapshot, 'source_hash' => $sourceHash];
        }
        usort($targets, static fn (array $a, array $b): int => $a['asset_key'] <=> $b['asset_key']);

        return ['framework' => $framework, 'targets' => $targets, 'package_sha256' => $actualSha];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package): array {
            $created = 0;
            foreach ($package['targets'] as $target) {
                /** @var PersonalityPublicContentAsset $asset */
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($target['asset']->id);
                $revision = PersonalityPublicContentAssetRevision::query()->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $target['asset_key'])->first();
                if ($revision instanceof PersonalityPublicContentAssetRevision) {
                    if ((int) $revision->asset_id !== (int) $asset->id || (int) $asset->working_revision_id !== (int) $revision->id) {
                        throw new DomainException('personality_promotion_revision_collision');
                    }
                } else {
                    if ($asset->working_revision_id !== null) {
                        throw new DomainException('personality_promotion_foreign_working_revision');
                    }
                    $revision = PersonalityPublicContentAssetRevision::query()->create([
                        'asset_id' => $asset->id,
                        'revision_no' => ((int) PersonalityPublicContentAssetRevision::query()->where('asset_id', $asset->id)->max('revision_no')) + 1,
                        'authority_asset_key' => $target['asset_key'],
                        'source_package' => 'content-promotion/'.$context->lane.'/'.$context->subscope,
                        'source_hash' => $target['source_hash'],
                        'authority_package_sha256' => $context->packageSha256,
                        'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
                        'snapshot_json' => $target['snapshot'],
                        'public_runtime_fingerprint_before' => hash('sha256', PromotionContextFactory::canonicalJson($this->publicSnapshot($asset))),
                    ]);
                    $asset->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
                    $created++;
                }
            }

            return ['created_count' => $created, 'unchanged_count' => count($package['targets']) - $created, 'readback_count' => count($package['targets'])];
        });
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    public function publish(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        $result = DB::transaction(function () use ($context, $package): array {
            $changed = 0;
            $resolved = [];
            foreach ($package['targets'] as $target) {
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($target['asset']->id);
                $revision = PersonalityPublicContentAssetRevision::query()->where('authority_package_sha256', $context->packageSha256)->where('authority_asset_key', $target['asset_key'])->first();
                if (! $revision instanceof PersonalityPublicContentAssetRevision) {
                    throw new DomainException('personality_promotion_draft_missing');
                }
                if (! ((int) $asset->published_revision_id === (int) $revision->id && $asset->working_revision_id === null)
                    && (int) $asset->working_revision_id !== (int) $revision->id) {
                    throw new DomainException('personality_promotion_working_pointer_invalid');
                }
                $resolved[] = ['asset' => $asset, 'revision' => $revision, 'asset_key' => $target['asset_key']];
            }
            $this->reviews->assertApproved($context, $resolved);
            foreach ($resolved as $target) {
                $asset = $target['asset'];
                $revision = $target['revision'];
                if ((int) $asset->published_revision_id === (int) $revision->id && $asset->working_revision_id === null) {
                    continue;
                }
                if (! hash_equals(
                    (string) $revision->public_runtime_fingerprint_before,
                    hash('sha256', PromotionContextFactory::canonicalJson($this->publicSnapshot($asset))),
                )) {
                    throw new DomainException('personality_promotion_public_fingerprint_drift');
                }
                $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
                $asset->forceFill([...$snapshot, 'published_revision_id' => $revision->id, 'working_revision_id' => null])->saveQuietly();
                $asset->refresh();
                if (! $asset instanceof PersonalityPublicContentAsset
                    || ! hash_equals(
                        PromotionContextFactory::canonicalJson($snapshot),
                        PromotionContextFactory::canonicalJson($this->publicSnapshot($asset)),
                    )) {
                    throw new DomainException('personality_promotion_public_projection_parity_invalid');
                }
                $revision->forceFill(['workflow_state' => 'published'])->save();
                $changed++;
            }

            return ['changed_count' => $changed, 'unchanged_count' => count($package['targets']) - $changed, 'readback_count' => count($package['targets'])];
        });
        $this->invalidateTargets($package['targets']);

        return $result;
    }

    /** @return array{readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $resolved = [];
        foreach ($package['targets'] as $target) {
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()->find($target['asset']->id);
            if (! $asset instanceof PersonalityPublicContentAsset || $asset->working_revision_id !== null || ! $asset->published_revision_id) {
                throw new DomainException('personality_promotion_public_projection_invalid');
            }
            $revision = PersonalityPublicContentAssetRevision::query()->find($asset->published_revision_id);
            if (! $revision instanceof PersonalityPublicContentAssetRevision || ! hash_equals($context->packageSha256, (string) $revision->authority_package_sha256)) {
                throw new DomainException('personality_promotion_published_revision_invalid');
            }
            if (! is_array($revision->snapshot_json) || ! hash_equals(PromotionContextFactory::canonicalJson($revision->snapshot_json), PromotionContextFactory::canonicalJson($this->publicSnapshot($asset)))) {
                throw new DomainException('personality_promotion_public_projection_parity_invalid');
            }
            $this->assertTextOnlySnapshot($this->publicSnapshot($asset));
            $resolved[] = ['asset' => $asset, 'revision' => $revision, 'asset_key' => $target['asset_key']];
        }
        $this->reviews->assertApproved($context, $resolved);

        return ['readback_count' => count($package['targets'])];
    }

    private function framework(PromotionContext $context): string
    {
        return match ($context->lane.'/'.$context->subscope) {
            'W2/big-five' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'W5/enneagram' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            default => throw new DomainException('personality_promotion_lane_invalid'),
        };
    }

    /** @param array<string,mixed> $snapshot */
    private function assertTextOnlySnapshot(array $snapshot): void
    {
        $fields = array_keys($snapshot);
        sort($fields);
        $expectedFields = self::EDITORIAL_FIELDS;
        sort($expectedFields);
        if ($fields !== $expectedFields) {
            throw new DomainException('personality_promotion_snapshot_incomplete');
        }
        foreach ($snapshot as $field => $value) {
            if (! in_array($field, self::EDITORIAL_FIELDS, true)) {
                throw new DomainException('personality_promotion_snapshot_field_invalid');
            }
            if (preg_match('/(?:image|media|hero|twitter|og|attempt|report|order|payment|token|user)/i', $field) === 1 || $this->containsCjkOrImage($value)) {
                throw new DomainException('personality_promotion_text_only_boundary_invalid');
            }
            if (in_array($field, self::JSON_EDITORIAL_FIELDS, true) && ! is_array($value)) {
                throw new DomainException('personality_promotion_snapshot_type_invalid');
            }
            if (! in_array($field, self::JSON_EDITORIAL_FIELDS, true) && ! is_string($value)) {
                throw new DomainException('personality_promotion_snapshot_type_invalid');
            }
        }
        if (trim((string) $snapshot['source_package']) === ''
            || preg_match('/\A[0-9a-f]{64}\z/', (string) $snapshot['source_hash']) !== 1) {
            throw new DomainException('personality_promotion_snapshot_provenance_invalid');
        }
    }

    private function containsCjkOrImage(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/[\p{Han}]|!\[|<\/?(?:img|picture|source|svg|figure|video|audio|iframe|object|embed|canvas)\b/iu', $value) === 1;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $nested) {
            if (preg_match('/(?:image|media|hero|twitter|og|attempt|report|order|payment|token|user)/i', (string) $key) === 1 || $this->containsCjkOrImage($nested)) {
                return true;
            }
        }

        return false;
    }

    private function assertNoPrivateFields(mixed $value): void
    {
        if (is_string($value)
            && (preg_match(self::PRIVATE_ROUTE_PATTERN, $value) === 1
                || preg_match(self::SENSITIVE_QUERY_PATTERN, $value) === 1)) {
            throw new DomainException('personality_promotion_private_payload_invalid');
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $nested) {
            if (preg_match('/(?:attempt|report|order|payment|token|user|score|percentile)/i', (string) $key) === 1) {
                throw new DomainException('personality_promotion_private_payload_invalid');
            }
            $this->assertNoPrivateFields($nested);
        }
    }

    /** @param list<array<string,mixed>> $targets */
    public function invalidateTargets(array $targets): void
    {
        foreach ($targets as $target) {
            $asset = $target['asset'];
            if (! $this->cache->invalidateAsset($asset->framework, $asset->entity_type, $asset->entity_key, $asset->slug, $asset->locale, (int) $asset->org_id, true)
                || ! $this->cache->invalidateCollections($asset->framework, $asset->entity_type, $asset->locale, (int) $asset->org_id, true)) {
                throw new DomainException('personality_promotion_cache_invalidation_failed');
            }
        }
    }

    /** @return array<string,mixed> */
    private function publicSnapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach (self::EDITORIAL_FIELDS as $field) {
            $snapshot[$field] = $asset->getAttribute($field);
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException($error);
        }
        if (! is_array($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }
}
