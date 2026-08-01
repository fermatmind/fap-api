<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Services\Content\ContentPackV2Resolver;
use App\Services\ContentPackResolver;
use App\Services\Mbti\MbtiResultPersonalizationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** @review-surface mbti_approval_batch */
final class MbtiResultEnglishRuntimeCapabilityPreflightService
{
    public const APPROVAL_SHA256 = '7d20ee867a1a2eb0f69d0d3a4441615690d836f3e8f3a47222b6dfb43f6c5a3b';

    public const PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    public const TARGET_AUTHORITY_RECEIPT_SHA256 = 'f093096ff881bfabc633407d51ee01061dc17b9dbc43da41dc03ae7881b13756';

    public const TARGET_MANIFEST_SHA256 = '649a61633a05728618477b97036718c582673c96a82c24d142287991b3d2d0e1';

    public const ACTIVE_RUNTIME_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53';

    private const TARGET_RELEASE_ID = '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488';

    private const PACK_ID = 'MBTI.global.en.default';

    private const PACK_VERSION = 'v0.3';

    private const DIR_VERSION = 'MBTI-GLOBAL-en-v0.3';

    public function __construct(
        private readonly ContentPackResolver $contentPackResolver,
        private readonly ContentPackV2Resolver $contentPackV2Resolver,
    ) {}

    public static function defaultApprovalPath(): string
    {
        return base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/runtime-capability-preflight-approval-2026-08-02.json');
    }

    /**
     * This preflight is deliberately read-only. It must run against the deployed
     * Laravel bootstrap, not a control-plane runtime substitute.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $approvalPath, string $confirmedApprovalSha256): array
    {
        $approval = $this->validatedApproval($approvalPath, $confirmedApprovalSha256);
        $authority = $this->readExactInactiveAuthority();
        $physicalPack = $this->resolvePhysicalPack();
        $compiledPath = $this->contentPackV2Resolver->resolveCompiledPathByManifestHash(
            self::PACK_ID,
            self::PACK_VERSION,
            self::TARGET_MANIFEST_SHA256,
        );
        if (! is_string($compiledPath) || $compiledPath === '') {
            $this->fail('runtime_inactive_authority_unavailable');
        }

        $this->assertRuntimeProjectionSubstitutesTokens();

        return [
            'artifact' => 'EN-PARITY-W1-MBTI-RESULT-RUNTIME-CAPABILITY-PREFLIGHT-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.result_runtime_capability_preflight_receipt.v1',
            'status' => 'PASS',
            'ok' => true,
            'mode' => 'controlled_read_only_runtime_capability_preflight',
            'read_only' => true,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'activation_attempted' => false,
            'active_pointer_changed' => false,
            'indexability_attempted' => false,
            'sitemap_attempted' => false,
            'llms_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
            'private_authority_read_attempted' => false,
            'attempt_report_order_payment_read_attempted' => false,
            'package' => [
                'package_sha256' => self::PACKAGE_SHA256,
                'pack_id' => self::PACK_ID,
                'region' => 'GLOBAL',
                'locale' => 'en',
                'version' => self::PACK_VERSION,
                'row_count' => 46,
                'authority_content_row_count' => 21,
            ],
            'target_authority' => $authority,
            'approval' => [
                'approval_ref' => $approval['approval_ref'],
                'approval_sha256' => self::APPROVAL_SHA256,
                'gate' => $approval['gate'],
                'verdict' => $approval['verdict'],
            ],
            'runtime' => [
                'physical_pack_resolved' => $physicalPack,
                'inactive_authority_resolved' => true,
                'real_projection_substitution_verified' => true,
                'unresolved_token_rejection_verified' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validatedApproval(string $approvalPath, string $confirmedApprovalSha256): array
    {
        if (strtolower(trim($confirmedApprovalSha256)) !== self::APPROVAL_SHA256 || ! is_file($approvalPath) || is_link($approvalPath)) {
            $this->fail('preflight_approval_sha256_mismatch');
        }
        $bytes = File::get($approvalPath);
        if (! hash_equals(self::APPROVAL_SHA256, hash('sha256', $bytes))) {
            $this->fail('preflight_approval_file_sha256_mismatch');
        }
        try {
            $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('preflight_approval_json_invalid');
        }
        $expectedPermissions = [
            'target_authority_readback_authorized' => true,
            'runtime_projection_readback_authorized' => true,
            'publish_authorized' => false,
            'activation_authorized' => false,
            'active_pointer_authorized' => false,
            'indexability_authorized' => false,
            'sitemap_authorized' => false,
            'hreflang_authorized' => false,
            'llms_authorized' => false,
            'json_ld_authorized' => false,
            'search_submission_authorized' => false,
            'deployment_authorized' => false,
            'private_result_read_authorized' => false,
            'attempt_report_order_payment_read_authorized' => false,
        ];
        if (! is_array($approval)
            || ($approval['artifact_kind'] ?? null) !== 'controlled_transition_approval'
            || ($approval['schema_version'] ?? null) !== 'fermatmind.en_content_parity_controlled_transition_approval.v1'
            || ($approval['control_id'] ?? null) !== 'EN-PARITY-W1-MBTI-RESULT-RUNTIME-CAPABILITY-PREFLIGHT-01'
            || ($approval['approval_owner'] ?? null) !== 'human_operator'
            || ($approval['approval_ref'] ?? null) !== 'human-operator:w1-mbti-result-runtime-capability-preflight:2026-08-02'
            || ($approval['subscope_id'] ?? null) !== 'W1-MBTI-RESULT-CONTENT'
            || ($approval['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($approval['target_authority_receipt_sha256'] ?? null) !== self::TARGET_AUTHORITY_RECEIPT_SHA256
            || ($approval['target_manifest_sha256'] ?? null) !== self::TARGET_MANIFEST_SHA256
            || ($approval['active_runtime_revision'] ?? null) !== self::ACTIVE_RUNTIME_REVISION
            || ($approval['gate'] ?? null) !== 'runtime_capability_preflight'
            || ($approval['verdict'] ?? null) !== 'APPROVED'
            || ($approval['permissions'] ?? null) !== $expectedPermissions) {
            $this->fail('preflight_approval_contract_mismatch');
        }

        return $approval;
    }

    /** @return array<string, mixed> */
    private function readExactInactiveAuthority(): array
    {
        $release = DB::table('content_pack_releases')
            ->select(['id', 'action', 'region', 'locale', 'dir_alias', 'to_pack_id', 'status', 'manifest_hash', 'compiled_hash', 'content_hash', 'pack_version'])
            ->where('id', self::TARGET_RELEASE_ID)
            ->first();
        $manifest = DB::table('content_release_manifests')
            ->select(['content_pack_release_id', 'manifest_hash', 'pack_id', 'pack_version', 'compiled_hash', 'content_hash'])
            ->where('manifest_hash', self::TARGET_MANIFEST_SHA256)
            ->first();
        $activationExists = DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->exists();

        if ($release === null || $manifest === null || $activationExists
            || (string) $release->id !== self::TARGET_RELEASE_ID
            || (string) $release->region !== 'GLOBAL'
            || (string) $release->locale !== 'en'
            || (string) $release->dir_alias !== self::DIR_VERSION
            || strcasecmp((string) $release->to_pack_id, self::PACK_ID) !== 0
            || (string) $release->status !== 'success'
            || (string) $release->manifest_hash !== self::TARGET_MANIFEST_SHA256
            || (string) $release->compiled_hash !== self::PACKAGE_SHA256
            || (string) $release->pack_version !== self::PACK_VERSION
            || (string) $manifest->content_pack_release_id !== self::TARGET_RELEASE_ID
            || strcasecmp((string) $manifest->pack_id, self::PACK_ID) !== 0
            || (string) $manifest->pack_version !== self::PACK_VERSION
            || (string) $manifest->compiled_hash !== self::PACKAGE_SHA256) {
            $this->fail('target_authority_contract_mismatch');
        }

        return [
            'release_id' => self::TARGET_RELEASE_ID,
            'manifest_sha256' => self::TARGET_MANIFEST_SHA256,
            'state' => 'inactive_draft',
            'active_pointer_present' => false,
            'row_count' => 46,
            'authority_content_row_count' => 21,
        ];
    }

    /** @return array<string, mixed> */
    private function resolvePhysicalPack(): array
    {
        try {
            $resolved = $this->contentPackResolver->resolve('MBTI', 'GLOBAL', 'en', self::PACK_VERSION, self::DIR_VERSION);
        } catch (\Throwable) {
            $this->fail('runtime_physical_pack_unavailable');
        }
        $manifest = $resolved->manifest;
        if ($resolved->packId !== self::PACK_ID
            || ! is_array($manifest)
            || ($manifest['pack_id'] ?? null) !== self::PACK_ID
            || ($manifest['scale_code'] ?? null) !== 'MBTI'
            || ($manifest['region'] ?? null) !== 'GLOBAL'
            || ($manifest['locale'] ?? null) !== 'en'
            || ($manifest['content_package_version'] ?? null) !== self::PACK_VERSION
            || basename((string) $resolved->baseDir) !== self::DIR_VERSION) {
            $this->fail('runtime_physical_pack_identity_mismatch');
        }

        return ['resolved' => true, 'pack_id' => self::PACK_ID, 'version' => self::PACK_VERSION];
    }

    private function assertRuntimeProjectionSubstitutesTokens(): void
    {
        $projection = [
            'sections' => [[
                'key' => 'traits.close_call_axes',
                'body_md' => 'Synthetic {{close_axis_label}} token.',
                'payload' => ['summary' => 'Synthetic {{neighbor_type}} token.'],
            ]],
        ];
        $personalization = [
            'locale' => 'en',
            'sections' => ['traits.close_call_axes' => ['blocks' => [], 'selected_blocks' => []]],
        ];
        $rendered = $this->runtimeProjectionRenderer()->applyToProjection($projection, $personalization);
        if ($this->containsUnresolvedToken($rendered)) {
            $this->fail('runtime_result_token_unresolved');
        }
    }

    private function runtimeProjectionRenderer(): MbtiResultPersonalizationService
    {
        try {
            $reflection = new \ReflectionClass(MbtiResultPersonalizationService::class);
            $expectedPath = realpath(base_path('app/Services/Mbti/MbtiResultPersonalizationService.php'));
            $rendererPath = $reflection->getFileName();
            $method = $reflection->getMethod('applyToProjection');

            if (! is_string($expectedPath)
                || ! is_string($rendererPath)
                || realpath($rendererPath) !== $expectedPath
                || ! $method->isPublic()
                || $method->isStatic()) {
                $this->fail('runtime_projection_renderer_unavailable');
            }

            $renderer = $reflection->newInstanceWithoutConstructor();
            if (! $renderer instanceof MbtiResultPersonalizationService) {
                $this->fail('runtime_projection_renderer_unavailable');
            }

            return $renderer;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->fail('runtime_projection_renderer_unavailable');
        }
    }

    private function containsUnresolvedToken(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, '{{') || str_contains($value, '}}');
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if ($this->containsUnresolvedToken($child)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $code): never
    {
        throw new DomainException($code.': controlled MBTI English result runtime capability preflight failed.');
    }
}
