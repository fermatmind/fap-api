<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class IncrementalUrlTruthSyncService
{
    public function __construct(
        private readonly UrlTruthInventorySource $authority,
        private readonly EffectivePublicUrlEvaluator $evaluator,
        private readonly UrlTruthInventoryRecordWriter $writer,
    ) {}

    /** @return array<string,mixed> */
    public function sync(
        string $pageEntityType,
        string $entityIdentity,
        string $locale,
        string $revision,
        string $change,
    ): array {
        $pageEntityType = trim($pageEntityType);
        $entityIdentity = trim($entityIdentity);
        $locale = trim($locale);
        $revision = trim($revision);
        if ($pageEntityType === '' || $entityIdentity === '' || ! in_array($locale, ['en', 'zh-CN'], true) || $revision === '') {
            throw new InvalidArgumentException('incremental URL Truth identity is invalid.');
        }
        if (! (bool) config('seo_intel.enabled', false) || ! (bool) config('seo_intel.write_enabled', false)) {
            throw new RuntimeException('incremental URL Truth writes are disabled.');
        }
        $this->assertSchemaReady();

        $matches = array_values(array_filter(
            $this->authority->candidates(),
            static fn (UrlTruthInventoryRecord $record): bool => $record->pageEntityType === $pageEntityType
                && (string) $record->entityIdOrSlug === $entityIdentity
                && $record->locale === $locale,
        ));
        if (count($matches) > 1) {
            throw new RuntimeException('incremental URL Truth authority identity is ambiguous.');
        }

        if ($matches === []) {
            return $this->retire($pageEntityType, $entityIdentity, $locale, $revision, $change);
        }

        $record = $matches[0];
        $evaluation = $this->evaluator->evaluate($record);
        if (! (bool) ($evaluation['effective_public'] ?? false)) {
            return $this->retire($pageEntityType, $entityIdentity, $locale, $revision, $change);
        }

        $authorityRevision = $this->revisionHash((string) ($evaluation['authority_revision'] ?? ''));
        $family = (string) $evaluation['family_id'];
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $record, $authorityRevision, $family, $pageEntityType, $entityIdentity, $locale, $revision, $change): array {
            $url = $connection->table('seo_urls')
                ->where('locale', $locale)
                ->where('canonical_url_hash', $record->canonicalUrlHash())
                ->lockForUpdate()->first();
            if ($url !== null && ((string) $url->page_entity_type !== $pageEntityType
                || (string) $url->entity_id_or_slug !== $entityIdentity)) {
                throw new RuntimeException('incremental URL Truth canonical identity conflict.');
            }
            $bindings = $connection->table('seo_url_entities')
                ->where('current_binding_key', $this->bindingKey($pageEntityType, $entityIdentity, $locale))
                ->lockForUpdate()->get();
            if ($bindings->count() > 1 || $bindings->contains(fn ($binding): bool => (string) $binding->page_entity_type !== $pageEntityType
                || (string) $binding->entity_id_or_slug !== $entityIdentity
                || (string) $binding->locale !== $locale)) {
                throw new RuntimeException('incremental URL Truth current identity conflict.');
            }
            $conflictingCanonicalBinding = $connection->table('seo_url_entities')
                ->where('canonical_url_hash', $record->canonicalUrlHash())
                ->where('locale', $locale)
                ->where('binding_status', 'current')
                ->whereNotNull('current_binding_key')
                ->where('current_binding_key', '!=', $this->bindingKey($pageEntityType, $entityIdentity, $locale))
                ->lockForUpdate()->exists();
            if ($conflictingCanonicalBinding) {
                throw new RuntimeException('incremental URL Truth canonical binding conflict.');
            }
            if ($this->hasCurrentReadback($record, $authorityRevision, $family)) {
                return $this->receipt('no_change', $pageEntityType, $locale, $revision, $change, false);
            }

            $this->writer->write([$record]);
            $fresh = array_values(array_filter($this->authority->candidates(), static fn (UrlTruthInventoryRecord $candidate): bool => $candidate->pageEntityType === $pageEntityType
                && (string) $candidate->entityIdOrSlug === $entityIdentity
                && $candidate->locale === $locale));
            if (count($fresh) !== 1 || $fresh[0]->canonicalUrl !== $record->canonicalUrl
                || $fresh[0]->sourceAuthority !== $record->sourceAuthority
                || $fresh[0]->entitySource !== $record->entitySource
                || $this->evaluator->evaluate($fresh[0]) !== $this->evaluator->evaluate($record)) {
                throw new RuntimeException('incremental URL Truth authority changed during synchronization.');
            }
            if (! $this->hasCurrentReadback($record, $authorityRevision, $family)) {
                throw new RuntimeException('incremental URL Truth URL and binding readback failed.');
            }

            return $this->receipt('synced', $pageEntityType, $locale, $revision, $change, true);
        });
    }

    private function hasCurrentReadback(UrlTruthInventoryRecord $record, string $revision, string $family): bool
    {
        $connection = $this->connection();
        $urlCount = $connection->table('seo_urls')
            ->where('locale', $record->locale)
            ->where('canonical_url_hash', $record->canonicalUrlHash())
            ->where('canonical_url', $record->canonicalUrl)
            ->where('page_entity_type', $record->pageEntityType)
            ->where('entity_id_or_slug', $record->entityIdOrSlug)
            ->where('source_authority', $record->sourceAuthority)
            ->where('indexability_state', 'indexable')
            ->where('is_private_flow', false)
            ->where('page_family', $family)
            ->where('authority_revision', $revision)
            ->count();
        $bindingCount = $connection->table('seo_url_entities')
            ->where('current_binding_key', $this->bindingKey($record->pageEntityType, (string) $record->entityIdOrSlug, $record->locale))
            ->where('canonical_url_hash', $record->canonicalUrlHash())
            ->where('locale', $record->locale)
            ->where('page_entity_type', $record->pageEntityType)
            ->where('entity_id_or_slug', $record->entityIdOrSlug)
            ->where('entity_source', $record->entitySource)
            ->where('authority_status', $record->authorityStatus)
            ->where('binding_status', 'current')
            ->where('page_family', $family)
            ->where('authority_revision', $revision)
            ->count();

        return $urlCount === 1 && $bindingCount === 1;
    }

    /** @return array<string,mixed> */
    private function retire(string $type, string $identity, string $locale, string $revision, string $change): array
    {
        $connection = $this->connection();
        $bindingKey = $this->bindingKey($type, $identity, $locale);
        $changed = $connection->transaction(function () use ($connection, $bindingKey, $locale, $revision): bool {
            $bindings = $connection->table('seo_url_entities')
                ->where('current_binding_key', $bindingKey)
                ->lockForUpdate()
                ->get();
            if ($bindings->isEmpty()) {
                return false;
            }
            $now = now();
            $hashes = $bindings->pluck('canonical_url_hash')->all();
            $connection->table('seo_url_entities')
                ->whereIn('id', $bindings->pluck('id')->all())
                ->update([
                    'authority_status' => 'retired',
                    'authority_revision' => $this->revisionHash($revision),
                    'binding_status' => 'retired',
                    'current_binding_key' => null,
                    'retired_at' => $now,
                    'updated_at' => $now,
                ]);
            $connection->table('seo_urls')
                ->where('locale', $locale)
                ->whereIn('canonical_url_hash', $hashes)
                ->where('indexability_state', 'indexable')
                ->update([
                    'indexability_state' => 'retired',
                    'authority_revision' => $this->revisionHash($revision),
                    'updated_at' => $now,
                ]);

            return true;
        });

        return $this->receipt($changed ? 'retired' : 'no_change', $type, $locale, $revision, $change, $changed);
    }

    private function assertSchemaReady(): void
    {
        $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));
        if (! \App\Support\SchemaBaseline::tableExists('seo_urls', $schema->getConnection()->getName()) || ! \App\Support\SchemaBaseline::tableExists('seo_url_entities', $schema->getConnection()->getName())
            || ! \App\Support\SchemaBaseline::columnExists('seo_urls', 'authority_revision', $schema->getConnection()->getName())
            || ! \App\Support\SchemaBaseline::columnExists('seo_url_entities', 'current_binding_key', $schema->getConnection()->getName())) {
            throw new RuntimeException('incremental URL Truth schema is unavailable.');
        }
    }

    private function connection(): mixed
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'));
    }

    private function bindingKey(string $type, string $identity, string $locale): string
    {
        return hash('sha256', json_encode([$type, $identity, $locale], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function revisionHash(string $revision): string
    {
        return preg_match('/^[a-f0-9]{64}$/', $revision) === 1 ? $revision : hash('sha256', $revision);
    }

    /** @return array<string,mixed> */
    private function receipt(string $status, string $type, string $locale, string $revision, string $change, bool $written): array
    {
        return [
            'schema_version' => 'seo-url-truth-incremental-sync.v1',
            'status' => $status,
            'identity_hash' => hash('sha256', $type.'|'.$locale),
            'revision_hash' => $this->revisionHash($revision),
            'change' => $change,
            'writes_committed' => $written,
            'boundaries' => [
                'url_truth_only' => true,
                'content_publish_attempted' => false,
                'sitemap_authority_mutation_attempted' => false,
                'search_submission_allowed' => false,
                'raw_url_output' => false,
            ],
        ];
    }
}
