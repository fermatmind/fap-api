<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\IqPublicEntryPackage;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionPhaseIdentity;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRegistryWriter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Publishes only IQ-01 public copy; never impersonates a human CMS reviewer. */
final class IqPublicScalePromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'iq-public-scale';

    private const PACK_ID = 'iq-public-scale';

    private const LEAVES = ['landing_copy', 'why_choose', 'faq'];

    public function __construct(
        private readonly IqPublicEntryPackage $package,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly ScaleRegistryWriter $writer,
        private readonly ScaleRegistry $reader,
    ) {}

    public function id(): string
    {
        return 'w6_iq_public_scale_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W6' && $subscope === self::SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $this->rows($context);

        return $this->result($context, 0, 0, null, $this->hash($this->state(false)));
    }

    public function draftImport(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'preflight', 'content_promotion_preflight_receipt');
        $reference = DB::transaction(function () use ($context, $rows, $previous): string {
            $state = $this->state(true);
            if (data_get($previous, 'receipt.target_state_sha256') !== $this->hash($state)) {
                throw new DomainException('iq_public_scale_preflight_state_drift');
            }
            $existing = $this->snapshot($context, $rows, 'before_draft_import');
            if ($existing !== null) {
                if (data_get($existing->meta_json, 'state_sha256') !== $this->hash($state)) {
                    throw new DomainException('iq_public_scale_draft_state_drift');
                }

                return 'content-release-snapshot:'.$existing->id;
            }

            // The immutable package is a candidate, not a second runtime body.
            // This private journal binds prestate; no live registry row changes.
            return $this->capture($context, $rows, 'before_draft_import', $state);
        }, 3);

        return $this->result($context, 0, 0, $reference, $this->hash($this->state(false)));
    }

    public function publish(PromotionContext $context): array
    {
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'draft-import', 'cms_draft_import_receipt');
        $draft = $this->snapshots->resolve($context, $this->targets($rows), self::PACK_ID, 'before_draft_import', (string) data_get($previous, 'receipt.rollback_reference'));
        $this->assertSnapshotExecution($draft, $context);
        $this->assertSnapshotRows($draft, $rows);
        $written = 0;
        $reference = null;
        $newPublication = false;
        try {
            DB::transaction(function () use ($context, $rows, $previous, $draft, &$written, &$reference, &$newPublication): void {
                $state = $this->state(true);
                $existing = $this->snapshot($context, $rows, 'before_publication');
                if ($existing !== null) {
                    $reference = 'content-release-snapshot:'.$existing->id;
                    $this->assertPublished($rows, $state);

                    return;
                }
                if (data_get($previous, 'receipt.target_state_sha256') !== $this->hash($state)
                    || data_get($draft->meta_json, 'state_sha256') !== $this->hash($state)) {
                    throw new DomainException('iq_public_scale_draft_state_drift');
                }
                $reference = $this->capture($context, $rows, 'before_publication', $state);
                $newPublication = true;
                $changedLocales = [];
                foreach ($state['registries'] as $registry) {
                    $content = $registry['content'];
                    foreach ($rows as $row) {
                        foreach (self::LEAVES as $leaf) {
                            if (($content[$row['key']][$leaf] ?? null) !== $row['patch'][$leaf]) {
                                $changedLocales[$row['key']] = true;
                            }
                        }
                        $content[$row['key']] = array_replace($content[$row['key']], $row['patch']);
                    }
                    if ($content === $registry['content']) {
                        continue;
                    }
                    $changed = DB::table($registry['table'])->where('org_id', 0)->where('code', IqPublicEntryPackage::CODE)
                        ->update(['content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
                    if ($changed !== 1) {
                        throw new DomainException('iq_public_scale_update_count_mismatch');
                    }
                }
                $after = $this->state(true);
                $this->assertPublished($rows, $after);
                if ($this->protectedHash($state) !== $this->protectedHash($after)) {
                    throw new DomainException('iq_public_scale_protected_field_drift');
                }
                $written = count($changedLocales);
            }, 3);
            $this->invalidate();

            return $this->result($context, $written, 2, $reference, $this->hash($this->state(false)));
        } catch (Throwable $error) {
            if ($newPublication && $reference !== null && $this->snapshot($context, $rows, 'before_publication') !== null) {
                $this->rollback($context, $reference);
            }
            throw $error;
        }
    }

    public function liveQa(PromotionContext $context): array
    {
        $this->previous($context, 'publish', 'cms_publication_receipt');
        $rows = $this->rows($context);
        $snapshot = $this->snapshot($context, $rows, 'before_publication');
        if ($snapshot === null) {
            throw new DomainException('iq_public_scale_publication_missing');
        }
        $state = $this->state(false);
        $this->assertPublished($rows, $state);
        foreach ([$this->reader->getByCode(IqPublicEntryPackage::CODE, 0), $this->reader->lookupBySlug(IqPublicEntryPackage::SLUG, 0, false)] as $public) {
            if (! is_array($public) || ($public['primary_slug'] ?? null) !== IqPublicEntryPackage::SLUG) {
                throw new DomainException('iq_public_scale_public_readback_invalid');
            }
            foreach ($rows as $row) {
                foreach (self::LEAVES as $leaf) {
                    if (data_get($public, 'content_i18n_json.'.$row['key'].'.'.$leaf) !== $row['patch'][$leaf]) {
                        throw new DomainException('iq_public_scale_public_readback_drift');
                    }
                }
            }
        }

        return $this->result($context, 0, 2, null, $this->hash($state));
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $rows = $this->rows($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($rows), self::PACK_ID, 'before_publication', $rollbackReference);
        $this->assertSnapshotExecution($snapshot, $context);
        $this->assertSnapshotRows($snapshot, $rows);
        $before = (array) data_get($snapshot->meta_json, 'state', []);
        if (data_get($snapshot->meta_json, 'state_sha256') !== $this->hash($before)) {
            throw new DomainException('iq_public_scale_snapshot_state_invalid');
        }
        DB::transaction(function () use ($rows, $before): void {
            $current = $this->state(true, false);
            foreach ($before['registries'] as $saved) {
                $matches = array_values(array_filter($current['registries'], static fn (array $row): bool => $row['table'] === $saved['table'] && $row['values']['org_id'] === $saved['values']['org_id'] && $row['values']['code'] === $saved['values']['code'] && ($row['values']['id'] ?? null) === ($saved['values']['id'] ?? null)));
                if (count($matches) !== 1) {
                    throw new DomainException('iq_public_scale_rollback_identity_drift');
                }
                $content = $matches[0]['content'];
                foreach ($rows as $row) {
                    foreach (self::LEAVES as $leaf) {
                        $actual = $content[$row['key']][$leaf] ?? null;
                        $old = $saved['content'][$row['key']][$leaf] ?? null;
                        if ($actual !== $row['patch'][$leaf] && $actual !== $old) {
                            throw new DomainException('iq_public_scale_rollback_concurrent_content');
                        }
                        if (array_key_exists($leaf, $saved['content'][$row['key']])) {
                            $content[$row['key']][$leaf] = $old;
                        } else {
                            unset($content[$row['key']][$leaf]);
                        }
                    }
                }
                // Leave every unrelated leaf and later operator change intact.
                $encoded = $content === $saved['content'] ? $saved['values']['content_i18n_json']
                    : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                DB::table($saved['table'])->where('org_id', 0)->where('code', IqPublicEntryPackage::CODE)
                    ->update(['content_i18n_json' => $encoded]);
            }
        }, 3);
        $this->invalidate();
    }

    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $snapshot = $this->snapshot($context, $this->rows($context), 'before_publication');
        if ($snapshot === null) {
            return false;
        }
        $this->rollback($context, 'content-release-snapshot:'.$snapshot->id);

        return true;
    }

    private function rows(PromotionContext $context): array
    {
        if (! $this->supports($context->lane, $context->subscope) || $context->expectedRowCount !== 2
            || realpath($context->packageDirectory) !== realpath(base_path(IqPublicEntryPackage::PACKAGE))) {
            throw new DomainException('iq_public_scale_context_invalid');
        }

        return $this->package->read(base_path(), $context->packageSha256);
    }

    private function state(bool $lock, bool $requirePublic = true): array
    {
        $registries = [];
        foreach (['scales_registry_v2', 'scales_registry'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new DomainException('iq_public_scale_table_missing');
            }
            $query = DB::table($table)->where('org_id', 0)->where('code', IqPublicEntryPackage::CODE);
            $values = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn (object $row): array => (array) $row)->all();
            if (count($values) !== 1) {
                throw new DomainException('iq_public_scale_identity_ambiguous');
            }
            foreach ($values as $value) {
                if ($value['primary_slug'] !== IqPublicEntryPackage::SLUG || ($requirePublic && (! (bool) $value['is_public'] || ! (bool) $value['is_active']))) {
                    throw new DomainException('iq_public_scale_identity_or_hold_invalid');
                }
                $content = json_decode((string) $value['content_i18n_json'], true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($content) || ! is_array($content['zh'] ?? null) || ! is_array($content['en'] ?? null)) {
                    throw new DomainException('iq_public_scale_locale_state_invalid');
                }
                $registries[] = ['table' => $table, 'values' => $value, 'content' => $content];
            }
        }
        if ($registries === []) {
            throw new DomainException('iq_public_scale_identity_missing');
        }
        $query = DB::table('scale_slugs')->where('org_id', 0)->where('scale_code', IqPublicEntryPackage::CODE)->orderBy('id');
        $slugs = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn (object $row): array => (array) $row)->all();

        return ['registries' => $registries, 'slugs' => $slugs];
    }

    private function assertPublished(array $rows, array $state): void
    {
        foreach ($state['registries'] as $registry) {
            foreach ($rows as $row) {
                foreach (self::LEAVES as $leaf) {
                    if (($registry['content'][$row['key']][$leaf] ?? null) !== $row['patch'][$leaf]) {
                        throw new DomainException('iq_public_scale_published_content_drift');
                    }
                }
            }
        }
    }

    private function protectedHash(array $state): string
    {
        foreach ($state['registries'] as &$row) {
            unset($row['values']['content_i18n_json']);
            foreach (['zh', 'en'] as $key) {
                foreach (self::LEAVES as $leaf) {
                    unset($row['content'][$key][$leaf]);
                }
            }
        }
        unset($row);

        return $this->hash($state);
    }

    private function capture(PromotionContext $context, array $rows, string $phase, array $state): string
    {
        $targets = $this->targets($rows);

        return $this->snapshots->capture($context, $targets, self::PACK_ID, $phase, $rows, $targets->identities(), ['state' => $state, 'state_sha256' => $this->hash($state), 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt, 'executor_release_sha256' => $context->executorReleaseSha256]);
    }

    private function snapshot(PromotionContext $context, array $rows, string $phase): ?ContentReleaseSnapshot
    {
        $targets = $this->targets($rows);
        $snapshot = ContentReleaseSnapshot::query()->where('pack_id', self::PACK_ID)->where('reason', 'content_promotion_'.$phase)->orderBy('id')->get()
            ->first(static fn (ContentReleaseSnapshot $row): bool => data_get($row->meta_json, 'phase_idempotency_key') === PromotionPhaseIdentity::idempotencyKey($context, $phase, $targets));
        if ($snapshot !== null) {
            $resolved = $this->snapshots->resolve($context, $targets, self::PACK_ID, $phase, 'content-release-snapshot:'.$snapshot->id);
            $this->assertSnapshotExecution($resolved, $context);
            $this->assertSnapshotRows($resolved, $rows);

            return $resolved;
        }

        return null;
    }

    private function targets(array $rows): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_column($rows, 'identity'));
    }

    private function assertSnapshotRows(ContentReleaseSnapshot $snapshot, array $rows): void
    {
        if (data_get($snapshot->meta_json, 'rows') !== $rows
            || data_get($snapshot->meta_json, 'target_identities') !== $this->targets($rows)->identities()
            || data_get($snapshot->meta_json, 'state_sha256') !== $this->hash((array) data_get($snapshot->meta_json, 'state', []))) {
            throw new DomainException('iq_public_scale_snapshot_targets_invalid');
        }
    }

    private function previous(PromotionContext $context, string $phase, string $kind): array
    {
        $previous = $this->receipts->readPrevious($kind, $context);
        $receipt = $previous['receipt'];
        $contentHash = $receipt['receipt_content_sha256'] ?? null;
        unset($receipt['receipt_content_sha256']);
        if (! is_string($contentHash) || ! hash_equals($this->hash($receipt), $contentHash)
            || ($receipt['phase'] ?? null) !== $phase
            || ($receipt['adapter'] ?? null) !== $this->id()
            || ($receipt['source_repository'] ?? null) !== 'fermatmind/fap-api'
            || ($receipt['workflow_run_id'] ?? null) !== $context->workflowRunId
            || ($receipt['workflow_run_attempt'] ?? null) !== $context->workflowRunAttempt
            || ($receipt['executor_release_sha256'] ?? null) !== $context->executorReleaseSha256
            || ($receipt['idempotency_key'] ?? null) !== $context->idempotencyKey) {
            throw new DomainException('iq_public_scale_previous_receipt_binding_invalid');
        }

        return $previous;
    }

    private function assertSnapshotExecution(ContentReleaseSnapshot $snapshot, PromotionContext $context): void
    {
        if (data_get($snapshot->meta_json, 'workflow_run_id') !== $context->workflowRunId
            || data_get($snapshot->meta_json, 'workflow_run_attempt') !== $context->workflowRunAttempt
            || data_get($snapshot->meta_json, 'executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('iq_public_scale_snapshot_execution_invalid');
        }
    }

    private function invalidate(): void
    {
        $slugs = DB::table('scale_slugs')->where('org_id', 0)->where('scale_code', IqPublicEntryPackage::CODE)->pluck('slug')->all();
        $this->writer->invalidatePublicContentProjection(0, IqPublicEntryPackage::CODE, array_values(array_unique([IqPublicEntryPackage::SLUG, ...$slugs])));
    }

    private function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, string $stateHash): array
    {
        return PromotionAdapterResultFactory::make($context, $written, 2, $published, $reference, [
            'indexability_mutation_count' => 0, 'sitemap_mutation_count' => 0, 'llms_mutation_count' => 0,
            'search_mutation_count' => 0, 'deploy_mutation_count' => 0,
        ]) + ['target_state_sha256' => $stateHash];
    }
}
