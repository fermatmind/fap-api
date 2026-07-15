<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\MediaAuthority;

use App\Models\MediaAsset;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BigFiveAuthorityV2MediaMappingPreflight
{
    public const INTAKE_SCHEMA = 'big5-approved-media-intake.v1';

    public const PACKAGE_SCHEMA = 'big5-media-authority-mapping-package.v1';

    public const CANDIDATE_COUNT = 231;

    public const PAGE_SLOT_COUNT = 693;

    public const FAMILY_LOCALE_GROUP_COUNT = 18;

    public const GROUP_SLOT_REQUIREMENT_COUNT = 54;

    /** @return array<string, mixed> */
    public function preflight(string $intakePath, string $candidateMapPath, string $requirementsPath): array
    {
        [$intake, $resolvedIntake] = $this->readJson($intakePath, 'approved media intake');
        [$candidateMap, $resolvedCandidateMap] = $this->readJson($candidateMapPath, 'PR34 candidate media map');
        [$requirements, $resolvedRequirements] = $this->readJson($requirementsPath, 'PR34 upload requirements');

        $this->assertSourceInventory($candidateMap, $requirements);
        $approvedByRequirement = $this->validatedApprovedEntries($intake, $requirements);
        $package = $this->buildPackage(
            $intake,
            $candidateMap,
            $requirements,
            $approvedByRequirement,
            $resolvedIntake,
            $resolvedCandidateMap,
            $resolvedRequirements,
        );
        $packageSha256 = $this->canonicalSha256($package);

        return [
            'ok' => true,
            'status' => $approvedByRequirement === []
                ? 'PASS_FAIL_CLOSED_NO_APPROVED_ASSETS'
                : 'PASS_APPROVED_MEDIA_PREFLIGHT',
            'mode' => 'preflight_only_zero_write',
            'mapping_package_sha256' => $packageSha256,
            'counts' => $package['counts'],
            'actions' => $package['actions'],
            'mapping_package' => $package,
        ];
    }

    /** @param array<string, mixed> $candidateMap @param array<string, mixed> $requirements */
    private function assertSourceInventory(array $candidateMap, array $requirements): void
    {
        if (($candidateMap['schema_version'] ?? null) !== 'big5-candidate-media-map.v1') {
            throw new RuntimeException('PR34 candidate map schema mismatch.');
        }
        if (($requirements['schema_version'] ?? null) !== 'big5-media-upload-mapping-manifest.v1') {
            throw new RuntimeException('PR34 requirements schema mismatch.');
        }

        $mappings = $candidateMap['mappings'] ?? null;
        $groups = $requirements['requirements'] ?? null;
        if (! is_array($mappings) || count($mappings) !== self::CANDIDATE_COUNT) {
            throw new RuntimeException('PR34 candidate map must contain exactly 231 rows.');
        }
        if (! is_array($groups) || count($groups) !== self::FAMILY_LOCALE_GROUP_COUNT) {
            throw new RuntimeException('PR34 requirements must contain exactly 18 family-locale groups.');
        }

        $candidateKeys = [];
        $routes = [];
        foreach ($mappings as $index => $mapping) {
            if (! is_array($mapping)) {
                throw new RuntimeException('Candidate mapping must be an object at index '.$index.'.');
            }
            $candidateKey = $this->requiredString($mapping, 'candidate_key', 'candidate mapping');
            $route = $this->requiredString($mapping, 'route', 'candidate mapping');
            $locale = $this->requiredString($mapping, 'locale', 'candidate mapping');
            if (! in_array($locale, ['en', 'zh-CN'], true)
                || ! str_starts_with($route, $locale === 'en' ? '/en/' : '/zh/')) {
                throw new RuntimeException('Candidate locale/route identity mismatch at '.$candidateKey.'.');
            }
            $candidateKeys[] = $candidateKey;
            $routes[] = $route;
        }
        if (count(array_unique($candidateKeys)) !== self::CANDIDATE_COUNT
            || count(array_unique($routes)) !== self::CANDIDATE_COUNT) {
            throw new RuntimeException('PR34 candidate keys and routes must be unique.');
        }

        $groupCandidateCount = 0;
        $groupSlotCount = 0;
        $groupKeys = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                throw new RuntimeException('Media requirement group must be an object.');
            }
            $family = $this->requiredString($group, 'page_family', 'requirement group');
            $locale = $this->requiredString($group, 'locale', 'requirement group');
            $key = $family.'|'.$locale;
            $groupKeys[] = $key;
            $groupCandidateCount += (int) ($group['candidate_count'] ?? 0);
            $slotRequirements = $group['slot_requirements'] ?? null;
            if (! is_array($slotRequirements)
                || array_column($slotRequirements, 'slot') !== ['hero', 'inline', 'og']) {
                throw new RuntimeException('Each requirement group must contain hero, inline, and og slots.');
            }
            $groupSlotCount += count($slotRequirements);
        }
        if (count(array_unique($groupKeys)) !== self::FAMILY_LOCALE_GROUP_COUNT
            || $groupCandidateCount !== self::CANDIDATE_COUNT
            || $groupSlotCount !== self::GROUP_SLOT_REQUIREMENT_COUNT) {
            throw new RuntimeException('PR34 grouped media requirements do not match the locked inventory.');
        }
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, mixed>  $requirements
     * @return array<string, array<string, mixed>>
     */
    private function validatedApprovedEntries(array $intake, array $requirements): array
    {
        if (($intake['schema_version'] ?? null) !== self::INTAKE_SCHEMA) {
            throw new RuntimeException('Approved media intake schema mismatch.');
        }
        $entries = $intake['approved_assets'] ?? null;
        if (! is_array($entries)) {
            throw new RuntimeException('approved_assets must be an array.');
        }
        if (($intake['operator_approval_claimed'] ?? null) !== ($entries !== [])) {
            throw new RuntimeException('operator_approval_claimed must be false for empty intake and true for approved entries.');
        }
        if (count($entries) > self::GROUP_SLOT_REQUIREMENT_COUNT) {
            throw new RuntimeException('Approved intake exceeds the 54 locked grouped slot requirements.');
        }

        $allowedRequirements = [];
        foreach ($requirements['requirements'] as $group) {
            foreach ($group['slot_requirements'] as $slot) {
                $identity = $this->contentIdentity(
                    (string) $group['page_family'],
                    (string) $group['locale'],
                    (string) $slot['slot'],
                );
                $allowedRequirements[$identity] = true;
            }
        }

        $validated = [];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Approved media entry must be an object at index '.$index.'.');
            }
            $context = 'approved media entry '.$index;
            $family = $this->requiredString($entry, 'page_family', $context);
            $locale = $this->requiredString($entry, 'locale', $context);
            $slot = $this->requiredString($entry, 'slot', $context);
            $identity = $this->requiredString($entry, 'content_identity', $context);
            $expectedIdentity = $this->contentIdentity($family, $locale, $slot);
            if ($identity !== $expectedIdentity || ! isset($allowedRequirements[$identity])) {
                throw new RuntimeException('Approved media content identity is not a locked PR34 requirement: '.$identity.'.');
            }
            if (isset($validated[$identity])) {
                throw new RuntimeException('Duplicate approved media requirement: '.$identity.'.');
            }
            if (($entry['approval_status'] ?? null) !== 'operator_approved') {
                throw new RuntimeException('Approved media entry is missing operator_approved status: '.$identity.'.');
            }
            if (! is_int($entry['media_asset_id'] ?? null) || $entry['media_asset_id'] < 1) {
                throw new RuntimeException('Approved media asset id must be a positive integer: '.$identity.'.');
            }

            $assetKey = $this->requiredString($entry, 'media_asset_key', $context);
            $variantKey = $this->requiredString($entry, 'variant_key', $context);
            $publicUrl = $this->requiredString($entry, 'public_url', $context);
            foreach (['alt', 'rights', 'license', 'provenance', 'operator_approval_ref'] as $field) {
                $this->requiredString($entry, $field, $context);
            }
            if (! PublicMediaUrlGuard::isAllowedPublicMediaUrl($publicUrl)) {
                throw new RuntimeException('Approved media URL is not public-safe: '.$identity.'.');
            }
            if ($variantKey !== $slot) {
                throw new RuntimeException('Approved media variant must exactly match its hero/inline/og slot: '.$identity.'.');
            }

            $asset = MediaAsset::query()
                ->withoutGlobalScopes()
                ->with('variants')
                ->where('org_id', 0)
                ->whereKey($entry['media_asset_id'])
                ->where('asset_key', $assetKey)
                ->first();
            if (! $asset instanceof MediaAsset) {
                throw new RuntimeException('Approved Media Library asset identity was not found: '.$identity.'.');
            }
            $this->assertAssetAuthority($asset, $entry, $identity);
            $validated[$identity] = $entry;
        }

        ksort($validated);

        return $validated;
    }

    /** @param array<string, mixed> $entry */
    private function assertAssetAuthority(MediaAsset $asset, array $entry, string $identity): void
    {
        if ($asset->status !== MediaAsset::STATUS_PUBLISHED
            || $asset->is_public !== true
            || $asset->sync_status !== MediaAsset::SYNC_SYNCED
            || $asset->cdn_status !== MediaAsset::CDN_VERIFIED) {
            throw new RuntimeException('Media Library asset is not published, public, synced, and CDN verified: '.$identity.'.');
        }
        if (trim((string) $asset->alt) !== (string) $entry['alt']) {
            throw new RuntimeException('Approved media alt does not match Media Library authority: '.$identity.'.');
        }

        $payload = is_array($asset->payload_json) ? $asset->payload_json : [];
        foreach (['locale', 'rights', 'license', 'provenance', 'operator_approval_ref', 'content_identity'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) !== (string) $entry[$field]) {
                throw new RuntimeException('Approved media '.$field.' does not match Media Library authority: '.$identity.'.');
            }
        }

        $variant = $asset->variants->firstWhere('variant_key', $entry['variant_key']);
        if ($variant === null
            || $variant->sync_status !== MediaAsset::SYNC_SYNCED
            || $variant->cdn_status !== MediaAsset::CDN_VERIFIED) {
            throw new RuntimeException('Approved media variant is missing or unverified: '.$identity.'.');
        }
        $authorityUrl = PublicMediaUrlGuard::canonicalMediaUrl($asset->disk, $variant->path, $variant->url);
        if ($authorityUrl === null || $authorityUrl !== $entry['public_url']) {
            throw new RuntimeException('Approved media public URL does not match the selected Media Library variant: '.$identity.'.');
        }
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, mixed>  $candidateMap
     * @param  array<string, mixed>  $requirements
     * @param  array<string, array<string, mixed>>  $approvedByRequirement
     * @return array<string, mixed>
     */
    private function buildPackage(
        array $intake,
        array $candidateMap,
        array $requirements,
        array $approvedByRequirement,
        string $intakePath,
        string $candidateMapPath,
        string $requirementsPath,
    ): array {
        $mappings = [];
        $mappedPageSlots = 0;
        foreach ($candidateMap['mappings'] as $candidate) {
            $slots = [];
            foreach (['hero', 'inline', 'og'] as $slot) {
                $identity = $this->contentIdentity(
                    (string) $candidate['page_family'],
                    (string) $candidate['locale'],
                    $slot,
                );
                $approved = $approvedByRequirement[$identity] ?? null;
                if (is_array($approved)) {
                    $mappedPageSlots++;
                    $slots[] = [
                        'slot' => $slot,
                        'status' => 'approved_mapped',
                        'content_identity' => $identity,
                        'media_asset_id' => $approved['media_asset_id'],
                        'media_asset_key' => $approved['media_asset_key'],
                        'variant_key' => $approved['variant_key'],
                        'public_url' => $approved['public_url'],
                        'alt' => $approved['alt'],
                        'rights' => $approved['rights'],
                        'license' => $approved['license'],
                        'provenance' => $approved['provenance'],
                        'operator_approval_ref' => $approved['operator_approval_ref'],
                        'reason' => null,
                    ];
                } else {
                    $slots[] = [
                        'slot' => $slot,
                        'status' => 'missing_pending',
                        'content_identity' => $identity,
                        'media_asset_id' => null,
                        'media_asset_key' => null,
                        'variant_key' => null,
                        'public_url' => null,
                        'alt' => null,
                        'rights' => null,
                        'license' => null,
                        'provenance' => null,
                        'operator_approval_ref' => null,
                        'reason' => 'No approved intake entry passed every Media Library authority gate for this grouped slot requirement.',
                    ];
                }
            }
            $mappings[] = [
                'candidate_key' => $candidate['candidate_key'],
                'page_family' => $candidate['page_family'],
                'locale' => $candidate['locale'],
                'route' => $candidate['route'],
                'source_package' => $candidate['source_package'],
                'source_type' => $candidate['source_type'],
                'mapping_status' => count(array_filter($slots, static fn (array $row): bool => $row['status'] === 'approved_mapped')) === 3
                    ? 'approved_mapped'
                    : 'missing_pending',
                'slots' => $slots,
                'cms_write_executed' => false,
                'media_upload_executed' => false,
                'publish_state_change' => false,
                'indexability_change' => false,
            ];
        }

        $approvedCount = count($approvedByRequirement);

        return [
            'schema_version' => self::PACKAGE_SCHEMA,
            'source_inventory' => [
                'pr34_candidate_map_path' => $this->repositoryRelativePath($candidateMapPath),
                'pr34_candidate_map_sha256' => hash_file('sha256', $candidateMapPath),
                'pr34_requirements_path' => $this->repositoryRelativePath($requirementsPath),
                'pr34_requirements_sha256' => hash_file('sha256', $requirementsPath),
            ],
            'intake' => [
                'path' => $this->repositoryRelativePath($intakePath),
                'sha256' => hash_file('sha256', $intakePath),
                'schema_version' => $intake['schema_version'],
                'operator_approval_claimed' => $intake['operator_approval_claimed'],
                'approved_entry_count' => count($intake['approved_assets']),
            ],
            'counts' => [
                'candidate_pages' => self::CANDIDATE_COUNT,
                'family_locale_requirement_groups' => count($requirements['requirements']),
                'grouped_slot_requirements' => self::GROUP_SLOT_REQUIREMENT_COUNT,
                'approved_grouped_slot_requirements' => $approvedCount,
                'pending_grouped_slot_requirements' => self::GROUP_SLOT_REQUIREMENT_COUNT - $approvedCount,
                'total_page_slots' => self::PAGE_SLOT_COUNT,
                'mapped_page_slots' => $mappedPageSlots,
                'missing_pending_page_slots' => self::PAGE_SLOT_COUNT - $mappedPageSlots,
            ],
            'mappings' => $mappings,
            'actions' => [
                'database_reads' => $approvedCount,
                'database_writes' => 0,
                'media_uploads' => 0,
                'media_library_writes' => 0,
                'cms_mapping_writes' => 0,
                'publish_state_changes' => 0,
                'indexability_changes' => 0,
                'deployments' => 0,
            ],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException(ucfirst($label).' was not found: '.$resolved.'.');
        }
        $canonicalPath = realpath($resolved);
        if (! is_string($canonicalPath) || $canonicalPath === '') {
            throw new RuntimeException(ucfirst($label).' could not be resolved: '.$resolved.'.');
        }
        $resolved = $canonicalPath;
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException(ucfirst($label).' must decode to an object.');
        }

        return [$decoded, $resolved];
    }

    /** @param array<string, mixed> $record */
    private function requiredString(array $record, string $field, string $context): string
    {
        $value = trim((string) ($record[$field] ?? ''));
        if ($value === '') {
            throw new RuntimeException($context.' is missing '.$field.'.');
        }

        return $value;
    }

    private function contentIdentity(string $family, string $locale, string $slot): string
    {
        if (! in_array($locale, ['en', 'zh-CN'], true)
            || ! in_array($slot, ['hero', 'inline', 'og'], true)
            || trim($family) === '') {
            throw new RuntimeException('Invalid Big Five media requirement identity.');
        }

        return 'big5:'.$family.':'.$locale.':'.$slot;
    }

    /** @param array<string, mixed> $payload */
    private function canonicalSha256(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursive($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }

    private function repositoryRelativePath(string $path): string
    {
        $repositoryRoot = dirname(base_path());
        $prefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
