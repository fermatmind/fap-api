<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class EnneagramPublicAuthorityV224RuntimeCloseout
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RUNTIME-READBACK-22E';

    /** @var array<string,mixed> */
    private array $executionProgress = [];

    /** @var resource|null */
    private $rollbackTokenHandle = null;

    private ?string $rollbackTokenPath = null;

    public function __construct(
        private readonly EnneagramPublicAuthorityV205RevisionWorkspaceWriter $workspaceWriter,
        private readonly EnneagramPublicAuthorityV223ReviewEvidenceBinder $reviewBinder,
        private readonly EnneagramPublicAuthorityV206RevisionPromoter $promoter,
        private readonly EnneagramPublicAuthorityV224RuntimeManifest $manifest,
        private readonly EnneagramPublicAuthorityV224RuntimeReadback $readback,
        private readonly EnneagramPublicAuthorityV224CacheCoordinator $cacheCoordinator,
    ) {}

    /**
     * @param  array<string,mixed>  $releaseReport
     * @param  array<string,mixed>  $reviewRegister
     * @param  array<string,mixed>|null  $preReadback
     * @return array<string,mixed>
     */
    public function preflight(
        array $releaseReport,
        string $releaseReportSha256,
        array $reviewRegister,
        string $reviewRegisterSha256,
        string $backendDeployedSha,
        string $frontendDeployedSha,
        ?array $preReadback = null,
        ?string $preReadbackSha256 = null,
    ): array {
        $workspace = $this->workspaceWriter->preflight($releaseReport);
        $runtime = $this->manifest->preflight(
            $releaseReport,
            $releaseReportSha256,
            $reviewRegister,
            $reviewRegisterSha256,
            $backendDeployedSha,
            $frontendDeployedSha,
            (string) $workspace['preflight_fingerprint'],
            $preReadback,
            $preReadbackSha256,
        );

        return [
            ...$runtime,
            'workspace_preflight_fingerprint' => (string) $workspace['preflight_fingerprint'],
            'workspace_new_revision_count' => (int) $workspace['new_revision_count'],
            'workspace_reuse_count' => (int) $workspace['idempotent_reuse_count'],
        ];
    }

    /**
     * @param  array<string,mixed>  $releaseReport
     * @param  array<string,mixed>  $reviewRegister
     * @param  array<string,mixed>  $authorizationPacket
     * @param  array<string,mixed>|null  $preReadback
     * @return array<string,mixed>
     */
    public function execute(
        array $releaseReport,
        string $releaseReportSha256,
        array $reviewRegister,
        string $reviewRegisterSha256,
        string $backendDeployedSha,
        string $frontendDeployedSha,
        array $authorizationPacket,
        string $authorizationPacketSha256,
        string $operatorAuthorization,
        string $rollbackTokenOutput,
        string $apiBaseUrl,
        string $frontendBaseUrl,
        string $revalidationEndpoint,
        string $revalidationSecret,
        ?int $boundByAdminUserId = null,
        ?array $preReadback = null,
        ?string $preReadbackSha256 = null,
    ): array {
        $this->executionProgress = [
            'failure_stage' => 'authorization_validation',
            'writes_committed' => false,
            'working_import_committed' => false,
            'review_bind_committed' => false,
            'promotion_committed' => false,
            'rollback_token_persisted' => false,
        ];
        $current = $this->preflight(
            $releaseReport,
            $releaseReportSha256,
            $reviewRegister,
            $reviewRegisterSha256,
            $backendDeployedSha,
            $frontendDeployedSha,
            $preReadback,
            $preReadbackSha256,
        );
        if (! hash_equals((string) $current['authorization_packet_sha256'], $authorizationPacketSha256)
            || ! hash_equals($authorizationPacketSha256, $this->fingerprint($authorizationPacket))
            || $authorizationPacket !== $current['authorization_packet']) {
            throw new RuntimeException('Exact-SHA production authorization packet drifted before execution.');
        }
        if (! hash_equals((string) $authorizationPacket['authorization_phrase'], $operatorAuthorization)) {
            throw new RuntimeException('Separate exact-SHA production authorization phrase mismatch.');
        }

        $this->executionProgress['failure_stage'] = 'rollback_token_reservation';
        $this->reserveRollbackTokenOutput($rollbackTokenOutput);
        $this->executionProgress['failure_stage'] = 'working_import';
        $workspace = $this->workspaceWriter->write(
            $releaseReport,
            (string) $releaseReport['package_sha256'],
            (string) $current['workspace_preflight_fingerprint'],
        );
        $this->executionProgress['writes_committed'] = true;
        $this->executionProgress['working_import_committed'] = true;
        $this->executionProgress['failure_stage'] = 'review_bind';
        $binderPlan = $this->reviewBinder->preflight($releaseReport, $reviewRegister, $reviewRegisterSha256);
        $binder = $this->reviewBinder->bind(
            $releaseReport,
            $reviewRegister,
            $reviewRegisterSha256,
            (string) $releaseReport['package_sha256'],
            (string) $binderPlan['preflight_fingerprint'],
            $boundByAdminUserId,
        );
        $this->executionProgress['review_bind_committed'] = true;
        $this->executionProgress['failure_stage'] = 'promotion';
        $targets = $this->promotionTargets((string) $releaseReport['package_sha256']);
        $promotionPlan = $this->promoter->preflight($targets);
        try {
            $promoted = $this->promoter->promote(
                $targets,
                (string) $promotionPlan['preflight_fingerprint'],
                function (string $token): void {
                    $this->persistReservedRollbackToken($token);
                },
            );
        } catch (Throwable $throwable) {
            $this->releaseRollbackTokenReservation(false);
            throw $throwable;
        }
        $this->executionProgress['promotion_committed'] = true;
        $rollbackToken = (string) ($promoted['rollback_token'] ?? '');
        if ($rollbackToken === '') {
            throw new RuntimeException('Promotion did not return the required signed rollback token.');
        }
        if (($this->executionProgress['rollback_token_persisted'] ?? false) !== true
            || ! hash_equals((string) $this->executionProgress['rollback_token_sha256'], hash('sha256', $rollbackToken))) {
            throw new RuntimeException('Promotion committed without matching persisted rollback-token evidence.');
        }
        $this->releaseRollbackTokenReservation(true);
        $this->executionProgress['failure_stage'] = 'rollback_preflight';
        $rollbackPreflight = $this->promoter->rollbackPreflight($rollbackToken);
        unset($promoted['rollback_token']);

        $this->executionProgress['failure_stage'] = 'cache_invalidation';
        $cacheInvalidation = $this->cacheCoordinator->invalidatePreservingLkg($releaseReport);
        $this->executionProgress['failure_stage'] = 'cache_readback';
        $cacheReadback = $this->cacheCoordinator->warmAndVerifyFresh($releaseReport, $apiBaseUrl);
        $this->executionProgress['failure_stage'] = 'frontend_revalidation';
        $frontendRevalidation = $this->cacheCoordinator->revalidateFrontend(
            $releaseReport,
            $revalidationEndpoint,
            $revalidationSecret,
        );

        $batchResults = [];
        $privateReviewerNames = $this->privateReviewerNames($reviewRegister);
        foreach (array_keys($this->manifest->readbackBatches($releaseReport)) as $batch) {
            $this->executionProgress['failure_stage'] = 'post_readback:'.$batch;
            $batchResults[$batch] = $this->readback->run(
                'post',
                $batch,
                $releaseReport,
                $apiBaseUrl,
                $frontendBaseUrl,
                true,
                $privateReviewerNames,
            );
        }
        $this->executionProgress['failure_stage'] = 'post_fingerprint';
        $post = $this->readback->snapshot($releaseReport, $frontendBaseUrl);
        if ((string) $post['stable_identity_discoverability_fingerprint']
            !== (string) $authorizationPacket['stable_identity_discoverability_fingerprint']) {
            throw new RuntimeException('Stable identity/discoverability fingerprint changed after promotion.');
        }
        $preUrlSets = data_get($authorizationPacket, 'pre_readback.url_sets');
        if (is_array($preUrlSets)) {
            foreach (['sitemap', 'llms', 'llms_full'] as $surface) {
                if (data_get($preUrlSets, $surface.'.url_set_sha256') !== data_get($post, 'url_sets.'.$surface.'.url_set_sha256')) {
                    throw new RuntimeException($surface.' URL set changed during runtime closeout.');
                }
            }
        }

        return [
            'schema_version' => 'enneagram_public_authority_v2_runtime_closeout.v1',
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_AUTHORIZED_RUNTIME_CLOSEOUT',
            'backend_deployed_sha' => $backendDeployedSha,
            'frontend_deployed_sha' => $frontendDeployedSha,
            'package_sha256' => (string) $releaseReport['package_sha256'],
            'release_report_sha256' => $releaseReportSha256,
            'review_register_sha256' => $reviewRegisterSha256,
            'runtime_preflight_fingerprint' => (string) $current['runtime_preflight_fingerprint'],
            'authorization_packet_sha256' => $authorizationPacketSha256,
            'working_import' => $this->safeSummary($workspace),
            'review_bind' => $this->safeSummary($binder),
            'promotion' => $this->safeSummary($promoted),
            'rollback_preflight' => $rollbackPreflight,
            'rollback_token_sha256' => hash('sha256', $rollbackToken),
            'rollback_token_output' => false,
            'automatic_rollback' => false,
            'cache_invalidation' => $cacheInvalidation,
            'cache_readback' => $cacheReadback,
            'frontend_revalidation' => $frontendRevalidation,
            'readback_batches' => array_map(fn (array $result): array => $this->safeSummary($result), $batchResults),
            'pre_public_projection_fingerprint' => (string) $authorizationPacket['public_projection_fingerprint'],
            'post_public_projection_fingerprint' => (string) $post['public_projection_fingerprint'],
            'stable_identity_discoverability_fingerprint' => (string) $post['stable_identity_discoverability_fingerprint'],
            'url_sets' => $post['url_sets'],
            'target_count' => EnneagramPublicAuthorityV224RuntimeManifest::TARGET_COUNT,
            'media_write_count' => 0,
            'private_data_exposed_count' => 0,
            'writes_committed' => true,
            'production_execution' => app()->environment('production'),
        ];
    }

    /** @return array<string,mixed> */
    public function failureResult(Throwable $throwable): array
    {
        if (($this->executionProgress['promotion_committed'] ?? false) !== true) {
            $this->releaseRollbackTokenReservation(false);
        }
        $writesCommitted = ($this->executionProgress['writes_committed'] ?? false) === true;
        $result = [
            'schema_version' => 'enneagram_public_authority_v2_runtime_closeout.v1',
            'artifact' => self::ARTIFACT,
            'ok' => false,
            'status' => $writesCommitted ? 'FAIL_CLOSED_PARTIAL_WRITES_COMMITTED' : 'FAIL_CLOSED_NO_WRITES',
            'failure_stage' => (string) ($this->executionProgress['failure_stage'] ?? 'command_preflight'),
            'writes_committed' => $writesCommitted,
            'working_import_committed' => ($this->executionProgress['working_import_committed'] ?? false) === true,
            'review_bind_committed' => ($this->executionProgress['review_bind_committed'] ?? false) === true,
            'promotion_committed' => ($this->executionProgress['promotion_committed'] ?? false) === true,
            'rollback_token_persisted' => ($this->executionProgress['rollback_token_persisted'] ?? false) === true,
            'automatic_rollback' => false,
            'error' => $throwable->getMessage(),
            'production_execution' => app()->environment('production'),
        ];
        if (is_string($this->executionProgress['rollback_token_sha256'] ?? null)) {
            $result['rollback_token_sha256'] = $this->executionProgress['rollback_token_sha256'];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function promotionTargets(string $packageSha256): array
    {
        $targets = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->orderBy('id')
            ->get()
            ->map(function (PersonalityPublicContentAsset $asset) use ($packageSha256): array {
                $revision = $asset->working_revision_id !== null
                    ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->working_revision_id)
                    : null;
                if (! $revision instanceof PersonalityPublicContentAssetRevision
                    || (string) $revision->authority_package_sha256 !== $packageSha256) {
                    throw new RuntimeException('Promotion target working revision is missing or package-bound incorrectly.');
                }

                return [
                    'asset_id' => (int) $asset->id,
                    'asset_key' => (string) $revision->authority_asset_key,
                    'expected_current_published_revision_id' => $asset->published_revision_id !== null
                        ? (int) $asset->published_revision_id
                        : null,
                    'expected_working_revision_id' => (int) $revision->id,
                    'expected_package_sha256' => (string) $revision->authority_package_sha256,
                    'expected_source_hash' => (string) $revision->source_hash,
                    'expected_public_fingerprint_before' => (string) $revision->public_runtime_fingerprint_before,
                ];
            })
            ->all();
        if (count($targets) !== EnneagramPublicAuthorityV224RuntimeManifest::TARGET_COUNT) {
            throw new RuntimeException('Promotion target query did not resolve exactly 116 assets.');
        }

        return $targets;
    }

    private function reserveRollbackTokenOutput(string $path): void
    {
        $this->releaseRollbackTokenReservation(false);
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Rollback token output must be an absolute path outside the Git repository.');
        }
        $directory = realpath(dirname($path));
        $repository = realpath(base_path('..'));
        if (! is_string($directory) || ! is_string($repository)
            || $directory === $repository
            || str_starts_with($directory.DIRECTORY_SEPARATOR, $repository.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Rollback token output directory must already exist outside the Git repository.');
        }
        if (File::exists($path)) {
            throw new RuntimeException('Rollback token output already exists; refusing to overwrite it.');
        }
        $handle = @fopen($path, 'x+b');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to reserve rollback token output outside Git.');
        }
        $this->rollbackTokenHandle = $handle;
        $this->rollbackTokenPath = $path;
        if (! @chmod($path, 0600)) {
            $this->releaseRollbackTokenReservation(false);
            throw new RuntimeException('Unable to protect reserved rollback token output.');
        }
    }

    private function persistReservedRollbackToken(string $token): void
    {
        if (! is_resource($this->rollbackTokenHandle) || $this->rollbackTokenPath === null) {
            throw new RuntimeException('Rollback token output was not reserved before promotion.');
        }
        $payload = $token."\n";
        if (! rewind($this->rollbackTokenHandle) || ! ftruncate($this->rollbackTokenHandle, 0)) {
            throw new RuntimeException('Unable to prepare reserved rollback token output.');
        }
        $written = 0;
        while ($written < strlen($payload)) {
            $chunk = fwrite($this->rollbackTokenHandle, substr($payload, $written));
            if (! is_int($chunk) || $chunk < 1) {
                throw new RuntimeException('Unable to persist rollback token before promotion commit.');
            }
            $written += $chunk;
        }
        if (! fflush($this->rollbackTokenHandle)
            || (function_exists('fsync') && ! fsync($this->rollbackTokenHandle))) {
            throw new RuntimeException('Unable to durably persist rollback token before promotion commit.');
        }
        $this->executionProgress['rollback_token_sha256'] = hash('sha256', $token);
        $this->executionProgress['rollback_token_persisted'] = true;
    }

    private function releaseRollbackTokenReservation(bool $keepFile): void
    {
        if (is_resource($this->rollbackTokenHandle)) {
            fclose($this->rollbackTokenHandle);
        }
        if (! $keepFile && $this->rollbackTokenPath !== null) {
            File::delete($this->rollbackTokenPath);
            $this->executionProgress['rollback_token_persisted'] = false;
            unset($this->executionProgress['rollback_token_sha256']);
        }
        $this->rollbackTokenHandle = null;
        $this->rollbackTokenPath = null;
    }

    /** @param array<string,mixed> $reviewRegister @return list<string> */
    private function privateReviewerNames(array $reviewRegister): array
    {
        $names = [];
        foreach (is_array($reviewRegister['reviews'] ?? null) ? $reviewRegister['reviews'] : [] as $review) {
            $name = is_array($review) ? trim((string) ($review['reviewer_name'] ?? '')) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function safeSummary(array $result): array
    {
        return array_intersect_key($result, array_flip([
            'ok',
            'status',
            'target_count',
            'revision_created_count',
            'revision_reused_count',
            'review_evidence_created_count',
            'workflow_transition_count',
            'promoted_count',
            'rollback_token_sha256',
            'writes_committed',
            'phase',
            'batch',
            'api_read_count',
            'html_read_count',
            'public_projection_fingerprint',
            'stable_identity_discoverability_fingerprint',
        ]));
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->normalizeForHash($child), $value);
    }
}
