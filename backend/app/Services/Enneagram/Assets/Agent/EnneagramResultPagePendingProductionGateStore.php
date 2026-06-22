<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPagePendingProductionGateStore
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.pending_production_activation_gate.v0.1';

    public const APPROVAL_PHRASE = '我同意';

    public const DEFAULT_TTL_MINUTES = 120;

    public const DEFAULT_RELATIVE_PATH = 'private/content_releases/ENNEAGRAM/v2/pending_production_activation_gate.json';

    private const REQUIRED_GREEN_EVIDENCE_GATES = [
        'candidate_export_staging_import',
        'web_rendered_qa',
        'api_smoke',
        'rollback_simulation',
    ];

    /**
     * @param  array<string,mixed>  $approvalPacket
     * @return array<string,mixed>
     */
    public function write(array $approvalPacket, string $runId, string $evidenceDir, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): array
    {
        $ttlMinutes = max(1, $ttlMinutes);
        $evidence = $this->evidenceInventory($evidenceDir);
        $errors = array_values(array_unique(array_merge(
            $this->approvalPacketErrors($approvalPacket),
            (array) ($evidence['errors'] ?? []),
        )));

        if ($errors !== []) {
            throw new RuntimeException('Pending production gate is not valid: '.implode(', ', $errors));
        }

        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes($ttlMinutes);
        $releaseId = (string) ($approvalPacket['release_id'] ?? '');
        $candidateManifestSha256 = (string) ($approvalPacket['candidate_manifest_sha256'] ?? '');
        $runtimeRegistrySha256 = (string) ($approvalPacket['runtime_registry_sha256'] ?? '');
        $rollbackWindow = (string) ($approvalPacket['rollback_window'] ?? '');
        $pendingGateId = hash('sha256', implode('|', [
            $releaseId,
            $candidateManifestSha256,
            $runtimeRegistrySha256,
            $rollbackWindow,
            $issuedAt->toIso8601String(),
        ]));

        $packet = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'pending',
            'pending_gate_id' => $pendingGateId,
            'single_pending_gate' => true,
            'approval_phrase' => self::APPROVAL_PHRASE,
            'run_id' => $runId,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_minutes' => $ttlMinutes,
            'locked_contract' => [
                'release_id' => $releaseId,
                'confirm_release_id' => $releaseId,
                'candidate_manifest_sha256' => $candidateManifestSha256,
                'runtime_registry_sha256' => $runtimeRegistrySha256,
                'rollback_window' => $rollbackWindow,
                'post_activation_smoke_acknowledged' => (bool) ($approvalPacket['post_activation_smoke_acknowledged'] ?? false),
            ],
            'evidence_bundle' => $evidence,
            'authorization_scope' => [
                'phrase_authorizes_only_this_pending_gate' => true,
                'permanent_authorization' => false,
                'agent_may_decide_production_rollout' => false,
                'chat_may_override_locked_release_or_hashes' => false,
            ],
            'negative_guarantees' => [
                'production_activation_happened' => false,
                'production_rollback_happened' => false,
                'runtime_switch_happened' => false,
                'bulk_content_generation_happened' => false,
                'frontend_change_happened' => false,
            ],
        ];

        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'written' => true,
            'path' => self::DEFAULT_RELATIVE_PATH,
            'sha256' => hash_file('sha256', $path) ?: '',
            'pending_gate_id' => $pendingGateId,
            'expires_at' => $expiresAt->toIso8601String(),
            'approval_phrase' => self::APPROVAL_PHRASE,
            'locked_contract' => $packet['locked_contract'],
        ];
    }

    /**
     * @return array{pending_gate_id:string,release_id:string,confirm_release_id:string,candidate_manifest_sha256:string,runtime_registry_sha256:string,rollback_window:string}
     */
    public function consume(string $approvalPhrase): array
    {
        if ($approvalPhrase !== self::APPROVAL_PHRASE) {
            throw new RuntimeException('Pending production gate approval phrase mismatch.');
        }

        $packet = $this->readPendingPacket();
        $errors = $this->pendingPacketErrors($packet);
        if ($errors !== []) {
            throw new RuntimeException('Pending production gate is not executable: '.implode(', ', $errors));
        }

        $locked = (array) ($packet['locked_contract'] ?? []);
        $releaseId = (string) ($locked['release_id'] ?? '');

        return [
            'pending_gate_id' => (string) ($packet['pending_gate_id'] ?? ''),
            'release_id' => $releaseId,
            'confirm_release_id' => $releaseId,
            'candidate_manifest_sha256' => (string) ($locked['candidate_manifest_sha256'] ?? ''),
            'runtime_registry_sha256' => (string) ($locked['runtime_registry_sha256'] ?? ''),
            'rollback_window' => (string) ($locked['rollback_window'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $activationSummary
     */
    public function markActivated(string $pendingGateId, string $releaseId, array $activationSummary): void
    {
        $packet = $this->readPendingPacket();
        if (($packet['pending_gate_id'] ?? null) !== $pendingGateId) {
            throw new RuntimeException('Pending production gate id mismatch after activation.');
        }

        $locked = (array) ($packet['locked_contract'] ?? []);
        if (($locked['release_id'] ?? null) !== $releaseId) {
            throw new RuntimeException('Pending production gate release id mismatch after activation.');
        }

        $packet['status'] = 'activated';
        $packet['activated_at'] = now()->toIso8601String();
        $packet['activation_summary'] = [
            'verdict' => (string) ($activationSummary['verdict'] ?? ''),
            'release_id' => (string) ($activationSummary['release_id'] ?? ''),
            'rollback_target_release_id' => (string) ($activationSummary['rollback_target_release_id'] ?? ''),
        ];
        $packet['negative_guarantees']['production_activation_happened'] = true;

        File::put($this->path(), json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    public function delete(): void
    {
        File::delete($this->path());
    }

    private function path(): string
    {
        return storage_path('app/'.self::DEFAULT_RELATIVE_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function readPendingPacket(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            throw new RuntimeException('Pending production gate file not found.');
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Pending production gate file is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $approvalPacket
     * @return list<string>
     */
    private function approvalPacketErrors(array $approvalPacket): array
    {
        $errors = [];
        if (($approvalPacket['valid'] ?? null) !== true) {
            $errors[] = 'manual_approval_packet_not_valid';
        }
        foreach (['release_id', 'candidate_manifest_sha256', 'runtime_registry_sha256', 'rollback_window'] as $field) {
            if (trim((string) ($approvalPacket[$field] ?? '')) === '') {
                $errors[] = 'manual_approval_packet_missing:'.$field;
            }
        }
        if (($approvalPacket['post_activation_smoke_acknowledged'] ?? null) !== true) {
            $errors[] = 'post_activation_smoke_acknowledgement_missing';
        }
        if (($approvalPacket['production_execution_allowed_for_agent'] ?? true) !== false) {
            $errors[] = 'agent_production_execution_must_remain_false';
        }
        if (($approvalPacket['manual_human_approval_required'] ?? null) !== true) {
            $errors[] = 'manual_human_approval_required_missing';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function pendingPacketErrors(array $packet): array
    {
        $errors = [];
        if (($packet['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version_mismatch';
        }
        if (($packet['status'] ?? null) !== 'pending') {
            $errors[] = 'pending_gate_not_pending';
        }
        if (($packet['single_pending_gate'] ?? null) !== true) {
            $errors[] = 'single_pending_gate_must_be_true';
        }
        if (($packet['approval_phrase'] ?? null) !== self::APPROVAL_PHRASE) {
            $errors[] = 'approval_phrase_not_locked';
        }
        if (strtotime((string) ($packet['expires_at'] ?? '')) <= time()) {
            $errors[] = 'pending_gate_expired';
        }
        $locked = (array) ($packet['locked_contract'] ?? []);
        if (($locked['release_id'] ?? '') === '' || ($locked['candidate_manifest_sha256'] ?? '') === '' || ($locked['runtime_registry_sha256'] ?? '') === '') {
            $errors[] = 'locked_contract_incomplete';
        }
        if (($locked['confirm_release_id'] ?? null) !== ($locked['release_id'] ?? null)) {
            $errors[] = 'locked_confirm_release_id_mismatch';
        }
        if (($locked['post_activation_smoke_acknowledged'] ?? null) !== true) {
            $errors[] = 'locked_post_activation_smoke_acknowledgement_missing';
        }
        if (($packet['authorization_scope']['phrase_authorizes_only_this_pending_gate'] ?? null) !== true) {
            $errors[] = 'phrase_scope_not_single_gate';
        }
        if (($packet['authorization_scope']['permanent_authorization'] ?? true) !== false) {
            $errors[] = 'permanent_authorization_must_be_false';
        }
        if (($packet['authorization_scope']['agent_may_decide_production_rollout'] ?? true) !== false) {
            $errors[] = 'agent_rollout_decision_must_be_false';
        }
        if (($packet['evidence_bundle']['all_green'] ?? null) !== true) {
            $errors[] = 'evidence_bundle_not_green';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceInventory(string $evidenceDir): array
    {
        $evidenceDir = rtrim(trim($evidenceDir), DIRECTORY_SEPARATOR);
        if ($evidenceDir === '') {
            return [
                'provided' => false,
                'all_green' => false,
                'gate_status' => [],
                'reports' => [],
                'errors' => ['evidence_dir_missing'],
            ];
        }
        if (! is_dir($evidenceDir)) {
            return [
                'provided' => true,
                'relative_path' => $this->redactPath($evidenceDir),
                'all_green' => false,
                'gate_status' => [],
                'reports' => [],
                'errors' => ['evidence_dir_not_found'],
            ];
        }

        $reports = [];
        $gateStatus = [];
        $errors = [];
        foreach (File::glob($evidenceDir.'/*.json') ?: [] as $path) {
            $payload = $this->readJson($path);
            $gate = $this->gateName($path, $payload);
            if ($gate === null) {
                continue;
            }

            $passing = $this->isPassingReport($payload);
            $gateStatus[$gate] = $passing ? 'pass' : 'fail';
            if (! $passing) {
                $errors[] = 'evidence_gate_failed:'.$gate;
            }
            $reports[] = [
                'relative_path' => $this->redactPath($path),
                'sha256' => hash_file('sha256', $path) ?: '',
                'gate' => $gate,
                'ok' => $passing,
            ];
        }

        foreach (self::REQUIRED_GREEN_EVIDENCE_GATES as $gate) {
            if (($gateStatus[$gate] ?? null) !== 'pass') {
                $errors[] = 'evidence_gate_missing_or_not_green:'.$gate;
            }
        }

        return [
            'provided' => true,
            'relative_path' => $this->redactPath($evidenceDir),
            'all_green' => $errors === [],
            'required_green_gates' => self::REQUIRED_GREEN_EVIDENCE_GATES,
            'gate_status' => $gateStatus,
            'reports' => $reports,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'status' => 'invalid_json'];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function gateName(string $path, array $payload): ?string
    {
        $rawGate = strtolower((string) ($payload['gate'] ?? pathinfo($path, PATHINFO_FILENAME)));
        $filename = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $source = $rawGate.' '.$filename;

        if (str_contains($source, 'candidate_export_staging_import') || str_contains($source, 'candidate_staging') || str_contains($source, 'staging_import')) {
            return 'candidate_export_staging_import';
        }
        if (str_contains($source, 'web_rendered_qa') || str_contains($source, 'phase8c') || str_contains($source, 'rendered_qa')) {
            return 'web_rendered_qa';
        }
        if (str_contains($source, 'api_smoke')) {
            return 'api_smoke';
        }
        if (str_contains($source, 'rollback_simulation')) {
            return 'rollback_simulation';
        }

        return in_array($rawGate, self::REQUIRED_GREEN_EVIDENCE_GATES, true) ? $rawGate : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isPassingReport(array $payload): bool
    {
        if (array_key_exists('ok', $payload)) {
            return $payload['ok'] === true;
        }

        $status = strtolower((string) ($payload['status'] ?? ''));
        if (in_array($status, ['success', 'pass', 'passed'], true)) {
            return true;
        }

        $verdict = strtoupper((string) ($payload['verdict'] ?? ''));

        return str_starts_with($verdict, 'PASS');
    }

    private function redactPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $base)) {
            return 'backend'.substr($path, strlen($base));
        }

        return basename($path);
    }
}
