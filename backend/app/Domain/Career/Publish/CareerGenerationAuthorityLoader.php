<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class CareerGenerationAuthorityLoader
{
    public const POINTER_SCHEMA_VERSION = 'career.generation_pointer.v1';

    public const REVOCATION_SCHEMA_VERSION = 'career.generation_revocation_receipt.v1';

    public const ACTIVE_POINTER_FILENAME = 'active-generation.json';

    public const GENERATION_POINTER_FILENAME = 'generation-pointer.json';

    private const MAX_POINTER_BYTES = 256_000;

    private const MAX_ARTIFACT_BYTES = 64_000_000;

    /** @return array<string, mixed>|null */
    public function activeProjection(): ?array
    {
        try {
            return $this->loadStrict()['projection'];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{pointer:array<string,mixed>,projection:array<string,mixed>,ledger:array<string,mixed>} */
    public function loadStrict(): array
    {
        $root = storage_path('app/private/career_generation_authority');
        $this->assertSafeRoot($root);
        $activePointerPath = $root.DIRECTORY_SEPARATOR.self::ACTIVE_POINTER_FILENAME;
        $loaded = $this->loadFromPointer(
            root: $root,
            pointerPath: $activePointerPath,
            validateLineage: true,
        );
        $generationId = (string) $loaded['pointer']['generation_id'];
        $activeDocument = $this->readJsonFile($root, $activePointerPath, self::MAX_POINTER_BYTES);
        $immutableDocument = $this->readJsonFile(
            $root,
            $root.DIRECTORY_SEPARATOR.'generations'.DIRECTORY_SEPARATOR.$generationId.DIRECTORY_SEPARATOR.self::GENERATION_POINTER_FILENAME,
            self::MAX_POINTER_BYTES,
        );
        if (! hash_equals(
            CareerGenerationCanonicalJson::sha256($immutableDocument),
            CareerGenerationCanonicalJson::sha256($activeDocument),
        )) {
            throw new RuntimeException('career_generation_active_pointer_immutable_identity_mismatch');
        }

        return $loaded;
    }

    /**
     * @return array{pointer:array<string,mixed>,projection:array<string,mixed>,ledger:array<string,mixed>}
     */
    private function loadFromPointer(string $root, string $pointerPath, bool $validateLineage): array
    {
        $document = $this->readJsonFile($root, $pointerPath, self::MAX_POINTER_BYTES);
        $pointer = $this->validatePointerDocument($document);
        $generationId = (string) $pointer['generation_id'];

        $projectionDescriptor = $this->artifactDescriptor(
            pointer: $pointer,
            key: 'projection',
            expectedIdentity: 'career-runtime-publish-projection@'.$generationId,
            expectedPath: 'generations/'.$generationId.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
        );
        $ledgerDescriptor = $this->artifactDescriptor(
            pointer: $pointer,
            key: 'ledger',
            expectedIdentity: 'career-full-release-ledger@'.$generationId,
            expectedPath: 'generations/'.$generationId.'/'.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
        );

        $ledger = $this->readBoundArtifact($root, $ledgerDescriptor);
        $ledgerState = $this->validateLedger($ledger, $pointer);

        try {
            $projection = $this->readBoundArtifact($root, $projectionDescriptor);
        } catch (Throwable $primaryFailure) {
            $projection = $this->readSameGenerationLkg(
                root: $root,
                pointer: $pointer,
                generationId: $generationId,
                primaryDescriptor: $projectionDescriptor,
                primaryFailure: $primaryFailure,
            );
        }

        $projectionState = $this->validateProjection($projection, $pointer);
        $this->validateAuthorityIdentity($pointer, $projectionState, $ledgerState);

        if ($validateLineage) {
            $this->validateLineage($root, $pointer, $projectionState);
        }

        return [
            'pointer' => $pointer,
            'projection' => $projection,
            'ledger' => $ledger,
        ];
    }

    /** @return array<string, mixed> */
    private function validatePointerDocument(array $document): array
    {
        if (($document['schema_version'] ?? null) !== self::POINTER_SCHEMA_VERSION) {
            throw new RuntimeException('career_generation_pointer_schema_invalid');
        }

        $payload = $document['payload'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('career_generation_pointer_payload_invalid');
        }

        $expectedHash = $this->requiredSha256($document['payload_sha256'] ?? null, 'pointer_payload_sha256');
        if (! hash_equals($expectedHash, CareerGenerationCanonicalJson::sha256($payload))) {
            throw new RuntimeException('career_generation_pointer_payload_hash_mismatch');
        }

        $generationId = $this->requiredIdentity($payload['generation_id'] ?? null, 'generation_id');
        foreach (['frozen_manifest_sha256', 'target_slug_set_sha256', 'target_locale_row_set_sha256', 'receipt_set_sha256'] as $key) {
            $this->requiredSha256(data_get($payload, 'authority.'.$key), $key);
        }

        foreach (['public_slug_count', 'public_locale_row_count'] as $key) {
            if (! is_int(data_get($payload, 'counts.'.$key)) || data_get($payload, 'counts.'.$key) < 0) {
                throw new RuntimeException('career_generation_pointer_'.$key.'_invalid');
            }
        }

        $createdAt = $this->requiredTimestamp(data_get($payload, 'timestamps.created_at'), 'created_at');
        $activatedAt = $this->requiredTimestamp(data_get($payload, 'timestamps.activated_at'), 'activated_at');
        if ($createdAt > $activatedAt) {
            throw new RuntimeException('career_generation_pointer_timestamp_order_invalid');
        }
        if (data_get($payload, 'activation_receipt.identity') !== 'activation:'.$generationId) {
            throw new RuntimeException('career_generation_activation_receipt_identity_invalid');
        }
        $this->requiredSha256(data_get($payload, 'activation_receipt.sha256'), 'activation_receipt_sha256');

        foreach (['sitemap_mutated', 'llms_mutated', 'search_mutated'] as $key) {
            if (data_get($payload, 'discoverability.'.$key) !== false) {
                throw new RuntimeException('career_generation_pointer_discoverability_must_remain_closed');
            }
        }

        $previousGenerationId = data_get($payload, 'lineage.previous_generation_id');
        if ($previousGenerationId !== null) {
            $previousGenerationId = $this->requiredIdentity($previousGenerationId, 'previous_generation_id');
            if ($previousGenerationId === $generationId) {
                throw new RuntimeException('career_generation_pointer_self_lineage');
            }
            $this->requiredSha256(data_get($payload, 'lineage.previous_pointer_sha256'), 'previous_pointer_sha256');
        } elseif (data_get($payload, 'lineage.previous_pointer_sha256') !== null) {
            throw new RuntimeException('career_generation_pointer_bootstrap_lineage_invalid');
        }

        if (data_get($payload, 'rollback.previous_generation_id') !== $previousGenerationId) {
            throw new RuntimeException('career_generation_pointer_rollback_lineage_mismatch');
        }
        if (data_get($payload, 'rollback.eligible') !== ($previousGenerationId !== null)) {
            throw new RuntimeException('career_generation_pointer_rollback_eligibility_invalid');
        }

        return $payload;
    }

    /** @return array{identity:string,path:string,sha256:string} */
    private function artifactDescriptor(array $pointer, string $key, string $expectedIdentity, string $expectedPath): array
    {
        $descriptor = data_get($pointer, 'artifacts.'.$key);
        if (! is_array($descriptor)
            || ($descriptor['identity'] ?? null) !== $expectedIdentity
            || ($descriptor['path'] ?? null) !== $expectedPath) {
            throw new RuntimeException('career_generation_'.$key.'_identity_invalid');
        }

        return [
            'identity' => $expectedIdentity,
            'path' => $expectedPath,
            'sha256' => $this->requiredSha256($descriptor['sha256'] ?? null, $key.'_sha256'),
        ];
    }

    /** @param array{identity:string,path:string,sha256:string} $descriptor */
    private function readBoundArtifact(string $root, array $descriptor): array
    {
        $path = $root.DIRECTORY_SEPARATOR.$descriptor['path'];
        $raw = $this->readFile($root, $path, self::MAX_ARTIFACT_BYTES);
        if (! hash_equals($descriptor['sha256'], hash('sha256', $raw))) {
            throw new RuntimeException('career_generation_artifact_hash_mismatch');
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('career_generation_artifact_json_invalid');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function readSameGenerationLkg(
        string $root,
        array $pointer,
        string $generationId,
        array $primaryDescriptor,
        Throwable $primaryFailure,
    ): array {
        $lkg = data_get($pointer, 'artifacts.projection_lkg');
        $expectedPath = 'generations/'.$generationId.'/lkg-'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        if (! is_array($lkg)
            || ($lkg['identity'] ?? null) !== $primaryDescriptor['identity']
            || ($lkg['path'] ?? null) !== $expectedPath
            || ($lkg['sha256'] ?? null) !== $primaryDescriptor['sha256']) {
            throw new RuntimeException('career_generation_primary_unavailable_without_same_generation_lkg', previous: $primaryFailure);
        }

        return $this->readBoundArtifact($root, [
            'identity' => $primaryDescriptor['identity'],
            'path' => $expectedPath,
            'sha256' => $primaryDescriptor['sha256'],
        ]);
    }

    /** @return array{slug_count:int,slug_set_sha256:string,locale_row_count:int,locale_row_set_sha256:string,public_slug_count:int,public_locale_row_count:int,public_slugs:list<string>} */
    private function validateProjection(array $projection, array $pointer): array
    {
        $generationId = (string) $pointer['generation_id'];
        if (($projection['projection_kind'] ?? null) !== CareerRuntimePublishProjectionService::PROJECTION_KIND
            || ($projection['projection_version'] ?? null) !== CareerRuntimePublishProjectionService::PROJECTION_VERSION
            || ($projection['source_authority'] ?? null) !== 'CareerFullReleaseLedger') {
            throw new RuntimeException('career_generation_projection_kind_invalid');
        }
        if (($projection['generation_id'] ?? null) !== $generationId
            || ($projection['artifact_identity'] ?? null) !== 'career-runtime-publish-projection@'.$generationId) {
            throw new RuntimeException('career_generation_projection_generation_mismatch');
        }
        $this->validateEmbeddedAuthority($projection, $pointer, 'projection');

        $items = $projection['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('career_generation_projection_items_invalid');
        }

        $slugLocales = [];
        $publicSlugLocales = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('career_generation_projection_item_invalid');
            }
            $slug = $this->requiredSlug($item['slug'] ?? null);
            $locale = $item['locale'] ?? null;
            if (! in_array($locale, CareerRuntimePublishProjectionService::LOCALES, true)) {
                throw new RuntimeException('career_generation_projection_locale_invalid');
            }
            $state = $item['runtime_publish_state'] ?? null;
            if (! in_array($state, [
                CareerRuntimePublishProjectionService::STATE_BLOCKED,
                CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
                CareerRuntimePublishProjectionService::STATE_PUBLISHED,
                CareerRuntimePublishProjectionService::STATE_QUARANTINED,
            ], true)) {
                throw new RuntimeException('career_generation_projection_state_invalid');
            }
            $identity = $slug.'|'.$locale;
            if (isset($slugLocales[$identity])) {
                throw new RuntimeException('career_generation_projection_duplicate_locale_row');
            }
            $slugLocales[$identity] = true;

            if ($state === CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $publicSlugLocales[$identity] = true;
            }
        }

        $slugs = array_values(array_unique(array_map(
            static fn (string $identity): string => explode('|', $identity, 2)[0],
            array_keys($slugLocales),
        )));
        foreach ($slugs as $slug) {
            foreach (CareerRuntimePublishProjectionService::LOCALES as $locale) {
                if (! isset($slugLocales[$slug.'|'.$locale])) {
                    throw new RuntimeException('career_generation_projection_locale_pair_incomplete');
                }
            }
        }

        $publicSlugs = array_values(array_unique(array_map(
            static fn (string $identity): string => explode('|', $identity, 2)[0],
            array_keys($publicSlugLocales),
        )));
        sort($publicSlugs, SORT_STRING);

        return [
            'slug_count' => count($slugs),
            'slug_set_sha256' => CareerGenerationCanonicalJson::setSha256($slugs),
            'locale_row_count' => count($slugLocales),
            'locale_row_set_sha256' => CareerGenerationCanonicalJson::setSha256(array_keys($slugLocales)),
            'public_slug_count' => count($publicSlugs),
            'public_locale_row_count' => count($publicSlugLocales),
            'public_slugs' => $publicSlugs,
        ];
    }

    /** @return array{slug_count:int,slug_set_sha256:string} */
    private function validateLedger(array $ledger, array $pointer): array
    {
        $generationId = (string) $pointer['generation_id'];
        if (($ledger['generation_id'] ?? null) !== $generationId
            || ($ledger['artifact_identity'] ?? null) !== 'career-full-release-ledger@'.$generationId) {
            throw new RuntimeException('career_generation_ledger_generation_mismatch');
        }
        $this->validateEmbeddedAuthority($ledger, $pointer, 'ledger');
        if (($ledger['ledger_kind'] ?? null) !== CareerFullReleaseLedgerService::LEDGER_KIND) {
            throw new RuntimeException('career_generation_ledger_kind_invalid');
        }

        $rows = data_get($ledger, 'public_resolution.rows');
        if (! is_array($rows) || $rows === []) {
            $rows = $ledger['members'] ?? null;
        }
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('career_generation_ledger_rows_invalid');
        }

        $slugs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('career_generation_ledger_row_invalid');
            }
            $slug = $this->requiredSlug($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? null);
            $slugs[$slug] = true;
        }

        return [
            'slug_count' => count($slugs),
            'slug_set_sha256' => CareerGenerationCanonicalJson::setSha256(array_keys($slugs)),
        ];
    }

    private function validateAuthorityIdentity(array $pointer, array $projection, array $ledger): void
    {
        $expected = [
            'target_slug_set_sha256' => $projection['slug_set_sha256'],
            'target_locale_row_set_sha256' => $projection['locale_row_set_sha256'],
        ];
        foreach ($expected as $key => $value) {
            if (data_get($pointer, 'authority.'.$key) !== $value) {
                throw new RuntimeException('career_generation_'.$key.'_mismatch');
            }
        }
        if ($projection['slug_count'] !== $ledger['slug_count']
            || $projection['slug_set_sha256'] !== $ledger['slug_set_sha256']) {
            throw new RuntimeException('career_generation_projection_ledger_set_mismatch');
        }
        if (data_get($pointer, 'counts.public_slug_count') !== $projection['public_slug_count']
            || data_get($pointer, 'counts.public_locale_row_count') !== $projection['public_locale_row_count']) {
            throw new RuntimeException('career_generation_public_count_mismatch');
        }
    }

    private function validateLineage(string $root, array $pointer, array $projection): void
    {
        $previousGenerationId = data_get($pointer, 'lineage.previous_generation_id');
        if ($previousGenerationId === null) {
            if (data_get($pointer, 'revocation_receipt') !== null) {
                throw new RuntimeException('career_generation_bootstrap_revocation_forbidden');
            }

            return;
        }

        $previousPointerPath = $root.DIRECTORY_SEPARATOR.'generations'.DIRECTORY_SEPARATOR.$previousGenerationId.DIRECTORY_SEPARATOR.self::GENERATION_POINTER_FILENAME;
        $previousDocument = $this->readJsonFile($root, $previousPointerPath, self::MAX_POINTER_BYTES);
        $previousDocumentHash = CareerGenerationCanonicalJson::sha256($previousDocument);
        if (! hash_equals((string) data_get($pointer, 'lineage.previous_pointer_sha256'), $previousDocumentHash)) {
            throw new RuntimeException('career_generation_previous_pointer_hash_mismatch');
        }
        $previous = $this->loadFromPointer($root, $previousPointerPath, false);
        $previousProjection = $this->validateProjection($previous['projection'], $previous['pointer']);

        $removedPublicSlugs = array_values(array_diff(
            $previousProjection['public_slugs'],
            $projection['public_slugs'],
        ));
        if ($removedPublicSlugs !== []) {
            $this->validateRevocationReceipt($root, $pointer, $previousProjection['public_slugs'], $projection['public_slugs']);
        } elseif (data_get($pointer, 'revocation_receipt') !== null) {
            throw new RuntimeException('career_generation_unnecessary_revocation_receipt');
        }
    }

    /** @param list<string> $previousPublicSlugs @param list<string> $currentPublicSlugs */
    private function validateRevocationReceipt(string $root, array $pointer, array $previousPublicSlugs, array $currentPublicSlugs): void
    {
        $generationId = (string) $pointer['generation_id'];
        $descriptor = data_get($pointer, 'revocation_receipt');
        $expectedPath = 'generations/'.$generationId.'/revocation-receipt.json';
        if (! is_array($descriptor)
            || ($descriptor['identity'] ?? null) !== 'career-generation-revocations@'.$generationId
            || ($descriptor['path'] ?? null) !== $expectedPath) {
            throw new RuntimeException('career_generation_shrink_requires_revocation_receipt');
        }
        $receipt = $this->readBoundArtifact($root, [
            'identity' => 'career-generation-revocations@'.$generationId,
            'path' => $expectedPath,
            'sha256' => $this->requiredSha256($descriptor['sha256'] ?? null, 'revocation_receipt_sha256'),
        ]);
        if (($receipt['schema_version'] ?? null) !== self::REVOCATION_SCHEMA_VERSION
            || ($receipt['from_generation_id'] ?? null) !== data_get($pointer, 'lineage.previous_generation_id')
            || ($receipt['to_generation_id'] ?? null) !== $generationId) {
            throw new RuntimeException('career_generation_revocation_receipt_identity_invalid');
        }

        $expectedRevoked = array_values(array_diff($previousPublicSlugs, $currentPublicSlugs));
        sort($expectedRevoked, SORT_STRING);
        $items = $receipt['items'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('career_generation_revocation_items_invalid');
        }
        $actualRevoked = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('career_generation_revocation_item_invalid');
            }
            $slug = $this->requiredSlug($item['slug'] ?? null);
            if (isset($actualRevoked[$slug])
                || ! in_array($item['decision'] ?? null, ['revoked', 'unpublished'], true)) {
                throw new RuntimeException('career_generation_revocation_item_invalid');
            }
            $this->requiredIdentity($item['receipt_identity'] ?? null, 'revocation_item_receipt_identity');
            $this->requiredTimestamp($item['approved_at'] ?? null, 'revocation_item_approved_at');
            $actualRevoked[$slug] = true;
        }
        $actualSlugs = array_keys($actualRevoked);
        sort($actualSlugs, SORT_STRING);
        if ($actualSlugs !== $expectedRevoked) {
            throw new RuntimeException('career_generation_revocation_set_mismatch');
        }
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $root, string $path, int $maxBytes): array
    {
        $payload = json_decode($this->readFile($root, $path, $maxBytes), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('career_generation_json_shape_invalid');
        }

        return $payload;
    }

    private function validateEmbeddedAuthority(array $artifact, array $pointer, string $artifactName): void
    {
        $embedded = $artifact['generation_authority'] ?? null;
        if (! is_array($embedded)) {
            throw new RuntimeException('career_generation_'.$artifactName.'_authority_missing');
        }
        foreach (['frozen_manifest_sha256', 'target_slug_set_sha256', 'target_locale_row_set_sha256', 'receipt_set_sha256'] as $key) {
            if (($embedded[$key] ?? null) !== data_get($pointer, 'authority.'.$key)) {
                throw new RuntimeException('career_generation_'.$artifactName.'_'.$key.'_mismatch');
            }
        }
    }

    private function readFile(string $root, string $path, int $maxBytes): string
    {
        $this->assertContainedRegularFile($root, $path);
        $size = filesize($path);
        if (! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('career_generation_file_size_invalid');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw new RuntimeException('career_generation_file_unreadable');
        }

        return $raw;
    }

    private function assertSafeRoot(string $root): void
    {
        if (is_link($root) || ! is_dir($root)) {
            throw new RuntimeException('career_generation_root_invalid');
        }
    }

    private function assertContainedRegularFile(string $root, string $path): void
    {
        $rootReal = realpath($root);
        if (! is_string($rootReal) || is_link($root)) {
            throw new RuntimeException('career_generation_root_invalid');
        }
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new RuntimeException('career_generation_path_invalid');
        }
        $cursor = $root;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('career_generation_path_invalid');
            }
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('career_generation_symlink_forbidden');
            }
        }
        $real = realpath($path);
        if (! is_string($real)
            || ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)
            || ! is_file($real)
            || ! is_readable($real)) {
            throw new RuntimeException('career_generation_file_invalid');
        }
    }

    private function requiredIdentity(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/', $value)) {
            throw new RuntimeException('career_generation_'.$field.'_invalid');
        }

        return $value;
    }

    private function requiredSlug(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            throw new RuntimeException('career_generation_slug_invalid');
        }

        return $value;
    }

    private function requiredSha256(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{64}$/', $value)) {
            throw new RuntimeException('career_generation_'.$field.'_invalid');
        }

        return $value;
    }

    private function requiredTimestamp(mixed $value, string $field): string
    {
        $parsed = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value)
            : false;
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('career_generation_'.$field.'_invalid');
        }

        return $value;
    }
}
