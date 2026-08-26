<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class ProductionCalibrationCloseoutService
{
    public const SCHEMA_VERSION = 'seo-platform-07-production-closeout.v1';

    /** @param array<string,mixed> $window @return array<string,mixed> */
    public function evaluate(array $window): array
    {
        $blockers = [];
        $registry = new PageFamilyPolicyRegistry;
        $receipts = array_values(array_filter((array) ($window['receipts'] ?? []), 'is_array'));
        if (($window['state'] ?? null) !== 'complete') {
            $blockers[] = 'scheduled_window_incomplete';
        }
        if (($window['consecutive'] ?? null) !== true
            || ($window['fresh'] ?? null) !== true
            || ($window['successful'] ?? null) !== true) {
            $blockers[] = 'scheduled_window_direct_evidence_incomplete';
        }
        if (count($receipts) !== 3) {
            $blockers[] = 'three_natural_slots_missing';
        }

        $deployRevisions = [];
        $receiptHashes = [];
        foreach ($receipts as $index => $receipt) {
            $prefix = 'slot_'.($index + 1).'_';
            if (($receipt['schema_version'] ?? null) !== ScheduledRuntimeProbeReceiptService::SCHEMA_VERSION
                || ($receipt['trigger_mode'] ?? null) !== 'scheduled'
                || ($receipt['status'] ?? null) !== 'success') {
                $blockers[] = $prefix.'receipt_invalid';
            }
            $receiptHash = $receipt['receipt_hash'] ?? null;
            $hashPayload = $receipt;
            unset($hashPayload['receipt_hash']);
            $computedHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));
            if (! is_string($receiptHash)
                || preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1
                || ! hash_equals($computedHash, $receiptHash)) {
                $blockers[] = $prefix.'receipt_hash_missing';
            } else {
                $receiptHashes[] = $receiptHash;
            }
            if (data_get($receipt, 'crawler_source_receipt.complete') !== true) {
                $blockers[] = $prefix.'crawler_source_incomplete';
            }

            $calibration = (array) ($receipt['production_calibration'] ?? []);
            if (($calibration['schema_version'] ?? null) !== ProductionCalibrationProbeService::SCHEMA_VERSION
                || ($calibration['state'] ?? null) !== 'success'
                || ($calibration['policy_version'] ?? null) !== PageFamilyPolicyRegistry::VERSION
                || ($calibration['policy_hash'] ?? null) !== $registry->policyHash()) {
                $blockers[] = $prefix.'calibration_invalid';
            }
            $this->validateCells($calibration, $prefix, $blockers);
            if (data_get($calibration, 'private_negative_set.checked') !== true
                || data_get($calibration, 'private_negative_set.accepted') !== true
                || data_get($calibration, 'private_negative_set.contract_probe_count') !== count($registry->negativeSetProbes())
                || data_get($calibration, 'private_negative_set.http_probe_count') !== count($registry->privatePathSegments())
                || data_get($calibration, 'private_negative_set.accepted_http_probe_count') !== count($registry->privatePathSegments())
                || data_get($calibration, 'private_negative_set.exposure_count') !== 0
                || data_get($calibration, 'private_negative_set.unobserved_count') !== 0
                || data_get($calibration, 'private_negative_set.unexpected_response_count') !== 0) {
                $blockers[] = $prefix.'private_negative_set_unaccepted';
            }
            $deployRevision = $calibration['deploy_revision'] ?? null;
            if (! is_string($deployRevision) || preg_match('/^[a-f0-9]{40}$/', $deployRevision) !== 1) {
                $blockers[] = $prefix.'deploy_revision_missing';
            } else {
                $deployRevisions[] = $deployRevision;
            }
        }

        if (count(array_unique($deployRevisions)) !== 1 || count($deployRevisions) !== 3) {
            $blockers[] = 'window_not_bound_to_one_deploy';
        }
        $blockers = array_values(array_unique($blockers));
        $proven = $blockers === [];
        $windowHash = count($receiptHashes) === 3
            ? hash('sha256', implode('|', $receiptHashes))
            : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $proven ? 'production_proven' : 'production_unproven',
            'direct_evidence_complete' => $proven,
            'blockers' => $blockers,
            'scheduled_slot_count' => count($receipts),
            'expected_cell_count_per_slot' => 12,
            'private_negative_set_accepted' => $proven,
            'deploy_revision_hash' => count($deployRevisions) === 3 && count(array_unique($deployRevisions)) === 1
                ? hash('sha256', 'seo-platform-07|'.$deployRevisions[0])
                : null,
            'receipt_window_hash' => $windowHash,
            'contract_projection_hash' => $windowHash === null
                ? null
                : hash('sha256', self::SCHEMA_VERSION.'|'.($proven ? 'production_proven' : 'production_unproven').'|'.$windowHash),
            'boundaries' => [
                'natural_scheduled_slots_only' => true,
                'manual_state_override_allowed' => false,
                'inferred_completion_allowed' => false,
                'api_ui_receipt_share_one_projection' => true,
                'read_only' => true,
                'production_write_authorization_granted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $calibration @param list<string> $blockers */
    private function validateCells(array $calibration, string $prefix, array &$blockers): void
    {
        $cells = (array) ($calibration['cells'] ?? []);
        $expectedKeys = [];
        foreach (PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS as $family) {
            foreach (AuthorityDrivenCohortResolver::LOCALES as $locale) {
                $key = $family.'|'.$locale;
                $expectedKeys[] = $key;
                $cell = (array) ($cells[$key] ?? []);
                $status = $cell['http_status'] ?? null;
                if (($cell['family'] ?? null) !== $family
                    || ($cell['locale'] ?? null) !== $locale
                    || ($cell['state'] ?? null) !== 'success'
                    || ($cell['sample_count'] ?? null) !== 1
                    || ($cell['success_count'] ?? null) !== 1
                    || ($cell['failure_count'] ?? null) !== 0
                    || ! is_numeric($cell['availability_rate'] ?? null)
                    || (float) $cell['availability_rate'] !== 1.0
                    || ! is_int($status)
                    || $status < 200
                    || $status >= 300
                    || ! $this->hash($cell['identity_hash'] ?? null)
                    || ! $this->hash($cell['authority_revision_hash'] ?? null)) {
                    $blockers[] = $prefix.'cell_'.$family.'_'.$locale.'_invalid';
                }
            }
        }
        $actualKeys = array_keys($cells);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys
            || ($calibration['expected_cell_count'] ?? null) !== 12
            || ($calibration['observed_cell_count'] ?? null) !== 12) {
            $blockers[] = $prefix.'cell_set_incomplete';
        }
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
