<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\ReviewGovernance\ReviewPolicyRegistry;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class ActionManifestVerifier
{
    private const SIGNATURE_DOMAIN = 'fermatmind.seo.action_scoped_manifest.v1';

    /** @param null|array<string, string> $inMemoryTrustedKeys Test-only in-memory public keys; production DI uses null. */
    public function __construct(
        private readonly PolicyGatewayContractValidator $contracts,
        private readonly PolicyGatewayRegistry $gatewayRegistry,
        private readonly SeoRoleCapabilityRegistry $roleRegistry,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly ?array $inMemoryTrustedKeys = null,
    ) {}

    /** @param array<string, mixed> $manifest @return array{valid:bool,code:string} */
    public function verify(array $manifest, ?CarbonImmutable $now = null): array
    {
        if (! extension_loaded('sodium')) {
            return ['valid' => false, 'code' => 'SODIUM_UNAVAILABLE'];
        }
        if (! $this->contracts->manifest($manifest)) {
            return ['valid' => false, 'code' => 'MANIFEST_CONTRACT_INVALID'];
        }
        if ($this->gatewayRegistry->dependencyStatus() !== 'READY') {
            return ['valid' => false, 'code' => 'DEPENDENCY_HOLD'];
        }

        $registry = $this->gatewayRegistry->registry();
        if (($manifest['policy_registry_ref'] ?? null) !== [
            'id' => $registry['registry_id'],
            'version' => $registry['registry_version'],
            'hash' => $registry['registry_hash'],
        ]) {
            return ['valid' => false, 'code' => 'POLICY_REGISTRY_MISMATCH'];
        }

        $roles = $this->roleRegistry->registry();
        $role = collect((array) ($roles['roles'] ?? []))->firstWhere('role_id', $manifest['role_id']);
        $capability = collect((array) ($roles['capabilities'] ?? []))->firstWhere('capability_id', $manifest['capability_id']);
        if (! is_array($role) || ! is_array($capability)
            || ! in_array($manifest['mission_type'], (array) ($role['allowed_missions'] ?? []), true)
            || ! in_array($manifest['family'], (array) ($role['page_family_scope'] ?? []), true)
            || ! in_array($manifest['locale'], (array) ($role['locale_scope'] ?? []), true)) {
            return ['valid' => false, 'code' => 'MANIFEST_ROLE_SCOPE_INVALID'];
        }
        if ($manifest['autonomy'] === 'L4') {
            return ['valid' => false, 'code' => 'L4_DORMANT_NOT_AUTHORIZED'];
        }

        $catalog = $this->gatewayRegistry->fieldCatalog();
        $actionFields = (array) ($catalog['actions'][$manifest['action']] ?? []);
        $globalForbidden = (array) ($catalog['global_forbidden_fields'] ?? []);
        $knownFields = array_values(array_unique([
            ...array_merge(...array_values((array) ($catalog['actions'] ?? []))),
            ...$globalForbidden,
        ]));
        if ($actionFields === []
            || array_diff((array) $manifest['allowed_fields'], $actionFields) !== []
            || array_diff((array) $manifest['forbidden_fields'], $knownFields) !== []
            || array_intersect((array) $manifest['allowed_fields'], [...(array) $manifest['forbidden_fields'], ...$globalForbidden]) !== []) {
            return ['valid' => false, 'code' => 'MANIFEST_FIELD_SCOPE_INVALID'];
        }

        try {
            $approval = ReviewPolicyRegistry::policy((string) $manifest['approval']['surface_id']);
            if (($approval['production_execution_separate'] ?? null) !== true
                || ($manifest['approval']['production_execution_separate'] ?? null) !== true) {
                return ['valid' => false, 'code' => 'APPROVAL_SURFACE_INVALID'];
            }
        } catch (Throwable) {
            return ['valid' => false, 'code' => 'APPROVAL_SURFACE_INVALID'];
        }

        $revocations = $this->gatewayRegistry->revocationRegistry();
        if (($manifest['revocation']['registry_id'] ?? null) !== ($revocations['registry_id'] ?? null)
            || ($manifest['revocation']['registry_version'] ?? null) !== ($revocations['registry_version'] ?? null)
            || in_array($manifest['manifest_id'], (array) ($revocations['revoked_manifest_ids'] ?? []), true)) {
            return ['valid' => false, 'code' => 'MANIFEST_REVOKED_OR_REGISTRY_MISMATCH'];
        }

        $now ??= CarbonImmutable::now('UTC');
        try {
            $notBefore = $this->strictUtc((string) $manifest['expiry']['not_before']);
            $expiresAt = $this->strictUtc((string) $manifest['expiry']['expires_at']);
            if ($now->lt($notBefore)) {
                return ['valid' => false, 'code' => 'MANIFEST_NOT_YET_VALID'];
            }
            if ($now->gte($expiresAt) || ! $expiresAt->gt($notBefore)) {
                return ['valid' => false, 'code' => 'MANIFEST_EXPIRED'];
            }
        } catch (Throwable) {
            return ['valid' => false, 'code' => 'MANIFEST_TIME_INVALID'];
        }

        $hashPayload = $manifest;
        unset($hashPayload['manifest_hash'], $hashPayload['signature']);
        if (! hash_equals($this->hasher->hash($hashPayload), (string) $manifest['manifest_hash'])) {
            return ['valid' => false, 'code' => 'MANIFEST_HASH_INVALID'];
        }

        $keys = $this->trustedKeys();
        $keyId = (string) ($manifest['signature']['key_id'] ?? '');
        $publicKey = $keys[$keyId] ?? null;
        if (! is_string($publicKey)) {
            return ['valid' => false, 'code' => 'MANIFEST_KEY_UNKNOWN'];
        }
        $signature = base64_decode((string) ($manifest['signature']['value'] ?? ''), true);
        $decodedKey = base64_decode($publicKey, true);
        if (! is_string($signature) || ! is_string($decodedKey)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($signature, $this->signatureMessage($manifest), $decodedKey)) {
            return ['valid' => false, 'code' => 'MANIFEST_SIGNATURE_INVALID'];
        }

        return ['valid' => true, 'code' => 'PASS'];
    }

    /** @param array<string, mixed> $manifest */
    public function signatureMessage(array $manifest): string
    {
        $payload = $manifest;
        unset($payload['manifest_hash'], $payload['signature']);

        return self::SIGNATURE_DOMAIN."\n".(string) ($manifest['manifest_hash'] ?? '')."\n".$this->hasher->json($payload);
    }

    /** @return array<string, string> */
    private function trustedKeys(): array
    {
        if ($this->inMemoryTrustedKeys !== null) {
            return $this->inMemoryTrustedKeys;
        }

        $keys = [];
        foreach ((array) ($this->gatewayRegistry->trustRegistry()['trusted_public_keys'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['key_id'] ?? null) && is_string($entry['public_key'] ?? null)) {
                $keys[$entry['key_id']] = $entry['public_key'];
            }
        }

        return $keys;
    }

    private function strictUtc(string $value): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            throw new \InvalidArgumentException('Timestamp is not strict UTC.');
        }
        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \InvalidArgumentException('Timestamp is invalid.');
        }

        return $parsed;
    }
}
