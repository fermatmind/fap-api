<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Models\ContentMaterialDecision;
use App\Support\SchemaBaseline;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class MaterialAuthorityUrlTruthBackfillService
{
    public const SCHEMA_VERSION = 'seo-platform-url-truth-material-backfill.v1';

    /** @var list<string> */
    private const DECISION_FAMILIES = ['article', 'career', 'personality'];

    /** @return array<string,mixed> */
    public function run(bool $execute, int $maxRecords = 5000, int $canarySize = 10): array
    {
        $maxRecords = min(10000, max(1, $maxRecords));
        $canarySize = min(50, max(1, $canarySize));
        if (! $this->schemaReady()) {
            return $this->blocked('material_url_truth_schema_unavailable', $execute, $maxRecords, $canarySize);
        }

        $urls = $this->eligibleUrls($maxRecords);
        if ($urls === null) {
            return $this->blocked('legacy_url_bound_exceeded', $execute, $maxRecords, $canarySize);
        }
        $decisions = $this->latestDecisions($maxRecords);
        if ($decisions === null) {
            return $this->blocked('material_decision_bound_exceeded', $execute, $maxRecords, $canarySize);
        }

        [$items, $counts] = $this->plan($urls, $decisions);
        $artifact = $this->artifact($items, $counts, $maxRecords, $canarySize);
        if (! $execute) {
            return $this->receipt($artifact, $counts, false, [], null, $maxRecords, $canarySize);
        }
        if (! (bool) config('seo_intel.enabled', false) || ! (bool) config('seo_intel.write_enabled', false)) {
            return $this->blocked('seo_intel_write_flags_disabled', true, $maxRecords, $canarySize, $artifact, $counts);
        }

        try {
            [$readback, $rerun] = $this->connection()->transaction(function () use ($items, $canarySize): array {
                $applicable = array_values(array_filter(
                    $items,
                    static fn (array $item): bool => $item['write_required'],
                ));
                $batches = array_filter([
                    ['stage' => 'canary', 'items' => array_slice($applicable, 0, $canarySize)],
                    ['stage' => 'remainder', 'items' => array_slice($applicable, $canarySize)],
                ], static fn (array $batch): bool => $batch['items'] !== []);

                $receipts = [];
                foreach ($batches as $batch) {
                    $this->write($batch['items']);
                    $receipt = $this->readback($batch['stage'], $batch['items']);
                    if (! $receipt['passed']) {
                        throw new RuntimeException('material_readback_failed');
                    }
                    $receipts[] = $receipt;
                }

                $freshUrls = $this->eligibleUrls(10000) ?? [];
                $freshDecisions = $this->latestDecisions(10000) ?? [];
                [$rerunItems, $rerunCounts] = $this->plan($freshUrls, $freshDecisions);
                $pendingWrites = count(array_filter($rerunItems, static fn (array $item): bool => $item['write_required']));
                $rerun = [
                    'apply' => $rerunCounts['apply'],
                    'retire' => $rerunCounts['retire'],
                    'hold' => $rerunCounts['hold'],
                    'no_change' => $rerunCounts['no_change'],
                    'pending_writes' => $pendingWrites,
                    'passed' => $pendingWrites === 0,
                ];
                if (! $rerun['passed']) {
                    throw new RuntimeException('material_authority_drift');
                }

                return [$receipts, $rerun];
            });
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), ['material_readback_failed', 'material_authority_drift'], true)) {
                return $this->blocked($exception->getMessage(), true, $maxRecords, $canarySize, $artifact, $counts);
            }

            throw $exception;
        }

        return $this->receipt($artifact, $counts, true, $readback, $rerun, $maxRecords, $canarySize);
    }

    /** @return list<object>|null */
    private function eligibleUrls(int $maxRecords): ?array
    {
        $rows = $this->connection()->table('seo_urls')
            ->where(function ($query): void {
                $query->where(function ($article): void {
                    $article->where('page_family', 'articles_topics')->where('page_entity_type', 'article');
                })->orWhere(function ($career): void {
                    $career->where('page_family', 'career')->where('page_entity_type', 'career_job');
                })->orWhere(function ($personality): void {
                    $personality->where('page_family', 'personality')->whereIn('page_entity_type', [
                        'personality', 'personality_profile_variant', 'personality_profile_comparison',
                        'personality_public_content_asset',
                    ]);
                });
            })
            ->orderBy('id')
            ->limit($maxRecords + 1)
            ->get()->all();

        return count($rows) > $maxRecords ? null : $rows;
    }

    /** @return array<string,ContentMaterialDecision>|null */
    private function latestDecisions(int $maxRecords): ?array
    {
        $latestIds = ContentMaterialDecision::query()
            ->where('org_id', 0)
            ->whereIn('family', self::DECISION_FAMILIES)
            ->selectRaw('MAX(id)')
            ->groupBy('family', 'locale', 'authority_subject_key');
        $rows = ContentMaterialDecision::query()
            ->whereIn('id', $latestIds)
            ->orderBy('id')
            ->limit($maxRecords + 1)
            ->get();
        if ($rows->count() > $maxRecords) {
            return null;
        }

        $decisions = [];
        foreach ($rows as $decision) {
            $decisions[$this->identityKey(
                (string) $decision->family,
                (string) $decision->locale,
                (string) $decision->public_identity,
            )] = $decision;
        }

        return $decisions;
    }

    /** @param list<object> $urls @param array<string,ContentMaterialDecision> $decisions @return array{0:list<array<string,mixed>>,1:array<string,int>} */
    private function plan(array $urls, array $decisions): array
    {
        $items = [];
        $counts = ['apply' => 0, 'retire' => 0, 'hold' => 0, 'no_change' => 0];
        foreach ($urls as $url) {
            $path = $this->canonicalPath((string) $url->canonical_url);
            $family = $this->decisionFamily((string) $url->page_family);
            $decision = $decisions[$this->identityKey($family, (string) $url->locale, $path)] ?? null;
            [$action, $holdReason, $writeRequired] = $this->classification($url, $decision, $path);
            $counts[$action]++;
            $items[] = [
                'url_id' => (int) $url->id,
                'canonical_hash' => (string) $url->canonical_url_hash,
                'family' => $family,
                'locale' => (string) $url->locale,
                'action' => $action,
                'hold_reason' => $holdReason,
                'write_required' => $writeRequired,
                'decision' => $decision,
            ];
        }

        return [$items, $counts];
    }

    /** @return array{0:string,1:string|null,2:bool} */
    private function classification(object $url, ?ContentMaterialDecision $decision, string $path): array
    {
        if (! $decision instanceof ContentMaterialDecision) {
            return ['hold', 'material_decision_missing', (string) $url->material_authority_state !== 'hold'];
        }
        if (! hash_equals($path, (string) $decision->public_identity)
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $decision->material_fingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $decision->decision_key) !== 1
            || trim((string) $decision->authority_revision_kind) === ''
            || trim((string) $decision->authority_revision) === ''
            || trim((string) $decision->evidence_ref) === ''
            || ! in_array((string) $decision->publication_state, ['published', 'unpublished'], true)
            || $decision->material_changed_at === null) {
            return ['hold', 'material_decision_incomplete', (string) $url->material_authority_state !== 'hold'];
        }
        $state = (string) $decision->publication_state === 'published' ? 'trusted' : 'retired';
        $same = (string) $url->material_authority_state === $state
            && hash_equals((string) $url->material_fingerprint, (string) $decision->material_fingerprint)
            && hash_equals((string) $url->material_decision_key, (string) $decision->decision_key)
            && (int) $url->material_decision_id === (int) $decision->id
            && (string) $url->material_lastmod_source === 'material_fingerprint.v1:'.(string) $decision->authority_revision_kind
            && $this->timestamp($url->material_lastmod_at) === $this->timestamp($decision->material_changed_at);

        $action = $same ? 'no_change' : ($state === 'trusted' ? 'apply' : 'retire');

        return [$action, null, ! $same];
    }

    /** @param list<array<string,mixed>> $items */
    private function write(array $items): void
    {
        foreach ($items as $item) {
            /** @var ContentMaterialDecision $decision */
            $decision = $item['decision'];
            if ($item['action'] === 'hold') {
                $this->connection()->table('seo_urls')->where('id', $item['url_id'])->update([
                    'material_authority_state' => 'hold',
                ]);

                continue;
            }
            $state = $item['action'] === 'retire' ? 'retired' : 'trusted';
            $values = [
                'material_fingerprint' => (string) $decision->material_fingerprint,
                'material_lastmod_at' => $decision->material_changed_at,
                'material_lastmod_source' => 'material_fingerprint.v1:'.(string) $decision->authority_revision_kind,
                'material_decision_key' => (string) $decision->decision_key,
                'material_decision_id' => (int) $decision->id,
                'material_authority_state' => $state,
            ];
            if ($state === 'retired') {
                $values['indexability_state'] = 'retired_material_authority';
            }
            $this->connection()->table('seo_urls')->where('id', $item['url_id'])->update($values);
        }
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function readback(string $stage, array $items): array
    {
        $ids = array_column($items, 'url_id');
        $expected = count($items);
        $rows = $this->connection()->table('seo_urls')->whereIn('id', $ids)->get()->keyBy('id');
        $actual = 0;
        foreach ($items as $item) {
            /** @var ContentMaterialDecision $decision */
            $decision = $item['decision'];
            $row = $rows->get($item['url_id']);
            if ($item['action'] === 'hold') {
                if ($row !== null && (string) $row->material_authority_state === 'hold') {
                    $actual++;
                }

                continue;
            }
            $state = $item['action'] === 'retire' ? 'retired' : 'trusted';
            if ($row !== null
                && (string) $row->material_authority_state === $state
                && hash_equals((string) $row->material_fingerprint, (string) $decision->material_fingerprint)
                && hash_equals((string) $row->material_decision_key, (string) $decision->decision_key)
                && $this->timestamp($row->material_lastmod_at) === $this->timestamp($decision->material_changed_at)) {
                $actual++;
            }
        }

        return [
            'stage' => $stage,
            'record_count' => $expected,
            'batch_digest' => hash('sha256', implode('|', array_column($items, 'canonical_hash'))),
            'material_readback_count' => $actual,
            'passed' => $actual === $expected,
        ];
    }

    /** @param list<array<string,mixed>> $items @param array<string,int> $counts @return array<string,mixed> */
    private function artifact(array $items, array $counts, int $maxRecords, int $canarySize): array
    {
        $rows = array_map(static function (array $item): array {
            $decision = $item['decision'];

            return [
                'canonical_hash' => $item['canonical_hash'],
                'family' => $item['family'],
                'locale' => $item['locale'],
                'hold_reason' => $item['hold_reason'],
                'public_identity_hash' => $decision instanceof ContentMaterialDecision
                    ? hash('sha256', (string) $decision->public_identity)
                    : null,
                'authority_revision' => $decision instanceof ContentMaterialDecision ? $decision->authority_revision : null,
                'authority_revision_kind' => $decision instanceof ContentMaterialDecision ? $decision->authority_revision_kind : null,
                'decision_key' => $decision instanceof ContentMaterialDecision ? $decision->decision_key : null,
                'decision_code' => $decision instanceof ContentMaterialDecision ? $decision->decision_code : null,
                'material_fingerprint' => $decision instanceof ContentMaterialDecision ? $decision->material_fingerprint : null,
                'material_changed_at' => $decision?->material_changed_at?->toISOString(),
                'evidence_ref_hash' => $decision instanceof ContentMaterialDecision
                    ? hash('sha256', (string) $decision->evidence_ref)
                    : null,
            ];
        }, $items);
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'record_count' => count($rows),
            'counts' => $counts,
            'content_digest' => $digest,
            'artifact_hash' => hash('sha256', self::SCHEMA_VERSION.'|'.$digest.'|'.$maxRecords.'|'.$canarySize),
            'event_field_contract' => [
                'family', 'locale', 'public_identity', 'authority_revision', 'material_fingerprint',
                'material_changed_at', 'decision_code', 'evidence_ref',
            ],
            'bounds' => ['max_records' => $maxRecords, 'canary_size' => $canarySize],
            'raw_urls_emitted' => false,
            'raw_content_emitted' => false,
        ];
    }

    /** @param array<string,mixed> $artifact @param array<string,int> $counts @param list<array<string,mixed>> $readback @param array<string,mixed>|null $rerun @return array<string,mixed> */
    private function receipt(array $artifact, array $counts, bool $executed, array $readback, ?array $rerun, int $maxRecords, int $canarySize): array
    {
        $readbackPassed = count(array_filter($readback, static fn (array $row): bool => ! $row['passed'])) === 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $readbackPassed && ($rerun === null || $rerun['passed']) ? 'success' : 'blocked',
            'mode' => $executed ? 'controlled_write' : 'dry_run',
            'artifact' => $artifact,
            'plan' => ['counts' => $counts],
            'writes_committed' => $executed,
            'readback' => $readback,
            'idempotent_rerun' => $rerun,
            'projection_state' => $this->projectionState($maxRecords),
            'bounds' => ['max_records' => $maxRecords, 'canary_size' => $canarySize],
            'boundaries' => [
                'material_decisions_only' => true,
                'unknown_legacy_action' => 'hold',
                'sitemap_can_create_authority' => false,
                'llms_can_create_authority' => false,
                'cache_can_create_authority' => false,
                'runtime_can_create_authority' => false,
                'cms_write' => false,
                'search_submission_allowed' => false,
                'raw_urls_emitted' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function projectionState(int $maxRecords): array
    {
        $urls = $this->eligibleUrls($maxRecords);
        if ($urls === null) {
            return ['status' => 'blocked', 'reason' => 'legacy_url_bound_exceeded'];
        }

        $rows = array_map(fn (object $url): array => [
            'canonical_hash' => (string) $url->canonical_url_hash,
            'material_fingerprint' => $this->validHash($url->material_fingerprint ?? null),
            'material_lastmod_at' => $this->timestamp($url->material_lastmod_at ?? null),
            'material_authority_state' => (string) ($url->material_authority_state ?? 'hold'),
        ], $urls);
        $states = ['trusted' => 0, 'hold' => 0, 'retired' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $state = array_key_exists($row['material_authority_state'], $states)
                ? $row['material_authority_state']
                : 'other';
            $states[$state]++;
        }

        return [
            'status' => 'available',
            'record_count' => count($rows),
            'state_counts' => $states,
            'projection_digest' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'raw_urls_emitted' => false,
        ];
    }

    private function validHash(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    /** @param array<string,mixed> $artifact @param array<string,int> $counts @return array<string,mixed> */
    private function blocked(string $issue, bool $execute, int $maxRecords, int $canarySize, array $artifact = [], array $counts = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'mode' => $execute ? 'controlled_write' : 'dry_run',
            'issues' => [$issue],
            'artifact' => $artifact,
            'plan' => ['counts' => $counts],
            'writes_committed' => false,
            'bounds' => ['max_records' => $maxRecords, 'canary_size' => $canarySize],
            'boundaries' => ['search_submission_allowed' => false, 'unknown_legacy_action' => 'hold'],
        ];
    }

    private function canonicalPath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function identityKey(string $family, string $locale, string $publicIdentity): string
    {
        return $family.'|'.$locale.'|'.$publicIdentity;
    }

    private function decisionFamily(string $pageFamily): string
    {
        return $pageFamily === 'articles_topics' ? 'article' : $pageFamily;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : date('Y-m-d H:i:s', strtotime((string) $value));
    }

    private function schemaReady(): bool
    {
        try {
            $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));

            return SchemaBaseline::tableExists('content_material_decisions')
                && $schema->hasTable('seo_urls')
                && $schema->hasColumn('seo_urls', 'page_family')
                && $schema->hasColumn('seo_urls', 'material_fingerprint');
        } catch (Throwable) {
            return false;
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection((string) config('seo_intel.connection', 'seo_intel'));
    }
}
