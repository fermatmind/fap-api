<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\Sources\UrlTruthInventorySource;
use App\Services\SeoIntel\UrlTruthInventoryRecord;

final class AuthorityDrivenCohortResolver
{
    public const SCHEMA_VERSION = 'seo-platform-07-cohort.v1';

    public const ROLES = [
        'core',
        'long_tail',
        'recent',
        'historical',
        'redirect_boundary',
        'changed_revision',
    ];

    public const LOCALES = ['zh-CN', 'en'];

    public function __construct(
        private readonly UrlTruthInventorySource $source,
        private readonly ?PageFamilyPolicyRegistry $registry = null,
        private readonly ?PageFamilyClassifier $classifier = null,
    ) {}

    /**
     * @param  array<string,string>  $baselineRevisions  identity hash => authority revision
     * @return array<string,mixed>
     */
    public function resolve(array $baselineRevisions = []): array
    {
        $registry = $this->registry ?? new PageFamilyPolicyRegistry;
        $registry->assertValid();
        $classifier = $this->classifier ?? new PageFamilyClassifier($registry);
        $eligible = [];
        $rejected = [];

        foreach ($this->source->candidates() as $record) {
            if (! $record instanceof UrlTruthInventoryRecord) {
                continue;
            }

            $candidate = $this->candidate($record, $registry, $classifier, $baselineRevisions);
            if (($candidate['eligible'] ?? false) !== true) {
                $reason = (string) ($candidate['reason'] ?? 'invalid_authority');
                $identityHash = (string) ($candidate['identity_hash'] ?? hash('sha256', $record->canonicalUrl));
                $rejected[$identityHash] = $reason;

                continue;
            }

            $eligible[] = $candidate;
        }

        usort($eligible, static fn (array $left, array $right): int => strcmp(
            (string) $left['identity_hash'],
            (string) $right['identity_hash'],
        ));

        $cells = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (self::LOCALES as $locale) {
                $key = $family.'|'.$locale;
                $cellCandidates = array_values(array_filter(
                    $eligible,
                    static fn (array $candidate): bool => $candidate['family'] === $family
                        && $candidate['locale'] === $locale,
                ));
                $roles = $this->selectRoles($cellCandidates);
                $cells[$key] = [
                    'family' => $family,
                    'locale' => $locale,
                    'status' => $cellCandidates === [] ? 'unobserved' : 'observed',
                    'authority_revision' => $cellCandidates === []
                        ? null
                        : $this->aggregateRevision($cellCandidates),
                    'roles' => $roles,
                ];
            }
        }

        ksort($rejected);
        $negativeSet = $this->negativeSet($registry, $classifier, $rejected);
        $hashPayload = [
            'schema_version' => self::SCHEMA_VERSION,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => $registry->policyHash(),
            'cells' => array_map(fn (array $cell): array => $this->hashableCell($cell), $cells),
            'negative_set_hash' => $negativeSet['set_hash'],
        ];

        return [
            ...$hashPayload,
            'cohort_hash' => hash('sha256', $this->canonicalJson($hashPayload)),
            'cells' => $cells,
            'private_negative_set' => $negativeSet,
            'boundaries' => [
                'authority_driven' => true,
                'sitemap_is_authority' => false,
                'query_targets_allowed' => false,
                'private_targets_allowed' => false,
                'unclassified_targets_allowed' => false,
                'rejected_raw_url_emitted' => false,
                'write_authorization_granted' => false,
            ],
        ];
    }

    /**
     * @param  array<string,string>  $baselineRevisions
     * @return array<string,mixed>
     */
    private function candidate(
        UrlTruthInventoryRecord $record,
        PageFamilyPolicyRegistry $registry,
        PageFamilyClassifier $classifier,
        array $baselineRevisions,
    ): array {
        $identityHash = $record->canonicalUrlHash();
        $parts = parse_url($record->canonicalUrl);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return ['eligible' => false, 'identity_hash' => $identityHash, 'reason' => 'invalid_or_query_url'];
        }

        $classification = $classifier->classify([
            'canonical_url' => $record->canonicalUrl,
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_source' => $record->entitySource,
            'source_authority' => $record->sourceAuthority,
            'authority_status' => $record->authorityStatus,
            'indexability_state' => $record->indexabilityState,
            'is_private_flow' => $record->isPrivateFlow,
        ]);
        if (($classification['classification_status'] ?? null) !== 'classified') {
            return [
                'eligible' => false,
                'identity_hash' => $identityHash,
                'reason' => (string) ($classification['classification_status'] ?? 'unclassified'),
            ];
        }
        if (! in_array($classification['family_id'], PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)
            || ! in_array($classification['locale'], self::LOCALES, true)
            || ! in_array(strtolower($record->authorityStatus), ['published', 'published_approved', 'active'], true)
            || strtolower($record->indexabilityState) !== 'indexable') {
            return ['eligible' => false, 'identity_hash' => $identityHash, 'reason' => 'ineligible_authority'];
        }

        $revision = $this->authorityRevision($record);
        $previousRevision = $baselineRevisions[$identityHash]
            ?? data_get($record->metadata, 'previous_authority_revision');
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $isCore = in_array(
            $path,
            (array) data_get($registry->families()[(string) $classification['family_id']], 'authority.route_authority.exact_static_templates', []),
            true,
        );

        return [
            'eligible' => true,
            'canonical_url' => $record->canonicalUrl,
            'identity_hash' => $identityHash,
            'family' => (string) $classification['family_id'],
            'locale' => (string) $classification['locale'],
            'authority_revision' => $revision,
            'observed_at' => $record->sourceUpdatedAt?->toIso8601String()
                ?? $record->lastmodAt?->toIso8601String(),
            'is_core' => $isCore,
            'has_redirect_boundary' => $this->hasRedirectBoundary($record),
            'changed_revision' => is_string($previousRevision)
                && $previousRevision !== ''
                && ! hash_equals($previousRevision, $revision),
        ];
    }

    /** @param list<array<string,mixed>> $candidates */
    private function selectRoles(array $candidates): array
    {
        $details = array_values(array_filter($candidates, static fn (array $candidate): bool => ! $candidate['is_core']));
        $dated = array_values(array_filter($candidates, static fn (array $candidate): bool => is_string($candidate['observed_at'])));
        usort($dated, static fn (array $left, array $right): int => [$left['observed_at'], $left['identity_hash']] <=> [$right['observed_at'], $right['identity_hash']]);

        $selectors = [
            'core' => array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['is_core'])),
            'long_tail' => $details,
            'recent' => $dated === [] ? [] : [end($dated)],
            'historical' => $dated === [] ? [] : [$dated[0]],
            'redirect_boundary' => array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['has_redirect_boundary'])),
            'changed_revision' => array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['changed_revision'])),
        ];

        $roles = [];
        foreach (self::ROLES as $role) {
            $matches = $selectors[$role];
            usort($matches, static fn (array $left, array $right): int => strcmp($left['identity_hash'], $right['identity_hash']));
            $target = $matches[0] ?? null;
            $roles[$role] = [
                'status' => $target === null ? 'unobserved' : 'observed',
                'target' => $target === null ? null : $this->publicTarget($target),
            ];
        }

        return $roles;
    }

    /** @param array<string,mixed> $candidate */
    private function publicTarget(array $candidate): array
    {
        return [
            'canonical_url' => $candidate['canonical_url'],
            'identity_hash' => $candidate['identity_hash'],
            'authority_revision' => $candidate['authority_revision'],
        ];
    }

    private function authorityRevision(UrlTruthInventoryRecord $record): string
    {
        $explicit = data_get($record->metadata, 'authority_revision')
            ?? data_get($record->attributes, 'authority_revision');
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        return hash('sha256', $this->canonicalJson([
            'identity_hash' => $record->canonicalUrlHash(),
            'page_entity_type' => $record->pageEntityType,
            'entity_id_or_slug' => $record->entityIdOrSlug,
            'source_authority' => $record->sourceAuthority,
            'entity_source' => $record->entitySource,
            'authority_status' => $record->authorityStatus,
            'indexability_state' => $record->indexabilityState,
            'source_updated_at' => $record->sourceUpdatedAt?->toIso8601String(),
            'lastmod_at' => $record->lastmodAt?->toIso8601String(),
        ]));
    }

    private function hasRedirectBoundary(UrlTruthInventoryRecord $record): bool
    {
        foreach (['redirect_boundary', 'has_redirect_boundary', 'canonical_alias_authority'] as $key) {
            if (data_get($record->metadata, $key) === true || data_get($record->attributes, $key) === true) {
                return true;
            }
        }

        foreach (['alias_hashes', 'redirect_from_hashes', 'canonical_alias_hashes'] as $key) {
            if ((array) data_get($record->metadata, $key, []) !== []
                || (array) data_get($record->attributes, $key, []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $candidates */
    private function aggregateRevision(array $candidates): string
    {
        $revisions = [];
        foreach ($candidates as $candidate) {
            $revisions[(string) $candidate['identity_hash']] = (string) $candidate['authority_revision'];
        }
        ksort($revisions);

        return hash('sha256', $this->canonicalJson($revisions));
    }

    /** @param array<string,string> $rejected */
    private function negativeSet(
        PageFamilyPolicyRegistry $registry,
        PageFamilyClassifier $classifier,
        array $rejected,
    ): array {
        $identities = [];
        $reasonCounts = [];
        foreach ($registry->negativeSetProbes() as $probe) {
            $classification = $classifier->classify($probe);
            if (($classification['classification_status'] ?? null) !== 'private_excluded') {
                continue;
            }
            $identities[] = hash('sha256', $this->canonicalJson($probe));
        }
        foreach ($rejected as $identityHash => $reason) {
            $identities[] = $identityHash;
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }
        $identities = array_values(array_unique($identities));
        sort($identities);
        ksort($reasonCounts);

        return [
            'identity_hashes' => $identities,
            'rejected_reason_counts' => $reasonCounts,
            'set_hash' => hash('sha256', $this->canonicalJson($identities)),
            'raw_url_emitted' => false,
            'query_emitted' => false,
        ];
    }

    /** @param array<string,mixed> $cell */
    private function hashableCell(array $cell): array
    {
        foreach ($cell['roles'] as &$role) {
            if (is_array($role['target'])) {
                unset($role['target']['canonical_url']);
            }
        }

        return $cell;
    }

    /** @param array<mixed> $value */
    private function canonicalJson(array $value): string
    {
        $this->sortRecursive($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        if (array_is_list($value)) {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $this->sortRecursive($item);
                }
            }

            return;
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }
    }
}
