<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use RuntimeException;

final class EnneagramPublicAuthorityV205RevisionWorkspaceWriter
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-REVISION-WORKSPACE-05';

    public const TARGET_COUNT = 116;

    public const SOURCE_PACKAGE = 'enneagram-public-authority-v2-revision-workspace-05';

    public function __construct(
        private readonly EnneagramPublicAuthorityV2IntegrityGate $integrityGate,
        private readonly PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter $writer,
    ) {}

    /** @param array<string, mixed> $scorecard @return array<string, mixed> */
    public function preflight(array $scorecard): array
    {
        $descriptors = $this->descriptors($scorecard);
        $packageSha256 = $this->packageSha256($descriptors);
        $plan = $this->writer->preflight(
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            $packageSha256,
            $descriptors,
        );
        $this->assertTargetCount($plan);

        return [
            ...$plan,
            'artifact' => self::ARTIFACT,
            'source_page_count' => self::TARGET_COUNT,
            'production_command_executed' => false,
            'database_migration_required' => false,
        ];
    }

    /** @param array<string, mixed> $scorecard @return array<string, mixed> */
    public function write(
        array $scorecard,
        string $expectedPackageSha256,
        string $expectedPreflightFingerprint,
    ): array {
        $descriptors = $this->descriptors($scorecard);
        $packageSha256 = $this->packageSha256($descriptors);
        if (! hash_equals($packageSha256, $expectedPackageSha256)) {
            throw new RuntimeException('Enneagram target package SHA-256 changed before write.');
        }

        $result = $this->writer->write(
            PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            $packageSha256,
            $descriptors,
            self::TARGET_COUNT,
            $expectedPreflightFingerprint,
        );

        return [
            ...$result,
            'artifact' => self::ARTIFACT,
            'source_page_count' => self::TARGET_COUNT,
            'database_migration_required' => false,
        ];
    }

    public function approvalPhrase(string $deploySha, string $packageSha256, string $preflightFingerprint): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1) {
            throw new RuntimeException('Deploy SHA must be an exact lowercase 40-character Git SHA.');
        }
        foreach (['package SHA-256' => $packageSha256, 'preflight fingerprint' => $preflightFingerprint] as $label => $value) {
            if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
                throw new RuntimeException(ucfirst($label).' must be an exact lowercase SHA-256.');
            }
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 ISOLATED WORKING REVISION WRITE FOR DEPLOY_SHA=%s PACKAGE_SHA256=%s PREFLIGHT_FINGERPRINT=%s TARGET_COUNT=116 PRIMARY_CONTENT_OVERWRITE=0 PUBLISHED_POINTER_UPDATE=0 PUBLIC_RELEASE=0 INDEXABILITY=0 SITEMAP=0 LLMS=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            $packageSha256,
            $preflightFingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @return list<array<string, mixed>>
     */
    private function descriptors(array $scorecard): array
    {
        $integrity = $this->integrityGate->validate($scorecard);
        if (($integrity['ok'] ?? false) !== true) {
            throw new RuntimeException('Frozen Enneagram 116-page integrity gate failed before revision preflight.');
        }

        $descriptors = [];
        foreach (array_values($scorecard['rows']) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Enneagram target descriptor row must be an object.');
            }
            $assetKey = implode(':', [
                PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                (string) $row['entity_type'],
                (string) $row['code'],
                (string) $row['locale'],
            ]);
            $sourceDescriptor = [
                'identity_key' => (string) $row['identity_key'],
                'locale' => (string) $row['locale'],
                'entity_type' => (string) $row['entity_type'],
                'code' => (string) $row['code'],
                'path' => (string) $row['path'],
                'canonical' => (string) $row['canonical'],
                'hreflang' => $row['hreflang'],
                'authority_source_package' => (string) data_get($row, 'revision_state.source_package', ''),
                'authority_source_hash' => (string) data_get($row, 'revision_state.source_hash', ''),
            ];
            $descriptors[] = [
                'asset_key' => $assetKey,
                'identity' => [
                    'org_id' => 0,
                    'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                    'entity_type' => (string) $row['entity_type'],
                    'entity_key' => (string) $row['code'],
                    'locale' => (string) $row['locale'],
                ],
                'source_package' => self::SOURCE_PACKAGE,
                'source_hash' => $this->fingerprint($sourceDescriptor),
                'target_descriptor' => $sourceDescriptor,
                'expected_attributes' => [
                    'canonical_json.path' => (string) $row['path'],
                ],
            ];
        }
        usort($descriptors, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        if (count($descriptors) !== self::TARGET_COUNT
            || count(array_unique(array_column($descriptors, 'asset_key'))) !== self::TARGET_COUNT) {
            throw new RuntimeException('Enneagram revision workspace requires exactly 116 unique target descriptors.');
        }

        return $descriptors;
    }

    /** @param list<array<string, mixed>> $descriptors */
    private function packageSha256(array $descriptors): string
    {
        return $this->fingerprint(array_map(static fn (array $descriptor): array => [
            'asset_key' => $descriptor['asset_key'],
            'identity' => $descriptor['identity'],
            'source_package' => $descriptor['source_package'],
            'source_hash' => $descriptor['source_hash'],
            'target_descriptor' => $descriptor['target_descriptor'],
        ], $descriptors));
    }

    /** @param array<string, mixed> $plan */
    private function assertTargetCount(array $plan): void
    {
        if ((int) ($plan['target_count'] ?? 0) !== self::TARGET_COUNT) {
            throw new RuntimeException('Enneagram revision workspace preflight did not resolve exactly 116 targets.');
        }
    }

    /** @param array<string, mixed>|list<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
