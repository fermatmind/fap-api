<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Throwable;

final class SeoWeeklyDecisionReceiptValidator
{
    public const HASH_ALGORITHM = 'canonical_json_sha256.v1';

    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    /**
     * @return array{valid:bool,payload:?array<string,mixed>,mismatch_codes:list<string>}
     */
    public static function decodeAndVerify(?object $row, string $prefix): array
    {
        if ($row === null) {
            return [
                'valid' => false,
                'payload' => null,
                'mismatch_codes' => [$prefix.'_receipt_missing'],
            ];
        }

        try {
            $payload = json_decode((string) ($row->receipt_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                'valid' => false,
                'payload' => null,
                'mismatch_codes' => [$prefix.'_receipt_json_invalid'],
            ];
        }

        if (! is_array($payload)) {
            return [
                'valid' => false,
                'payload' => null,
                'mismatch_codes' => [$prefix.'_receipt_json_invalid'],
            ];
        }

        $storedHash = (string) ($row->receipt_hash ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/', $storedHash) !== 1
            || ! hash_equals($storedHash, self::hash($payload))) {
            return [
                'valid' => false,
                'payload' => $payload,
                'mismatch_codes' => [$prefix.'_receipt_hash_mismatch'],
            ];
        }

        return ['valid' => true, 'payload' => $payload, 'mismatch_codes' => []];
    }

    /**
     * @return array{
     *   valid:bool,
     *   capability_receipt:?array<string,mixed>,
     *   selection_receipt:?array<string,mixed>,
     *   scheduled_for:?CarbonImmutable,
     *   mismatch_codes:list<string>
     * }
     */
    public static function validatePair(
        ?object $capabilityRow,
        ?object $selectionRow,
        string $capabilityRevision,
        ?string $expectedIsoWeek = null,
        ?CarbonImmutable $expectedSlot = null,
    ): array {
        $capability = self::decodeAndVerify($capabilityRow, 'capability');
        $selection = self::decodeAndVerify($selectionRow, 'selection');
        $capabilityReceipt = $capability['payload'];
        $selectionReceipt = $selection['payload'];
        $codes = array_merge($capability['mismatch_codes'], $selection['mismatch_codes']);
        $scheduledFor = null;

        if ($capabilityReceipt !== null) {
            try {
                $parsed = CarbonImmutable::createFromFormat(
                    '!Y-m-d\TH:i:s\Z',
                    (string) ($capabilityReceipt['scheduled_for'] ?? ''),
                    'UTC',
                );
                $scheduledFor = $parsed === false ? null : $parsed;
            } catch (Throwable) {
                $scheduledFor = null;
            }

            self::appendUnless(
                $codes,
                ($capabilityReceipt['schema_version'] ?? null) === SeoWeeklyDecisionReceiptService::CONTRACT_VERSION,
                'capability_schema_mismatch',
            );
            self::appendUnless(
                $codes,
                ($capabilityReceipt['receipt_hash_algorithm'] ?? null) === self::HASH_ALGORITHM,
                'capability_hash_algorithm_mismatch',
            );
            self::appendUnless($codes, ($capabilityReceipt['trigger'] ?? null) === 'scheduled', 'capability_trigger_mismatch');
            self::appendUnless(
                $codes,
                ($capabilityReceipt['manual_receipts_excluded'] ?? null) === true,
                'capability_manual_exclusion_mismatch',
            );
            self::appendUnless(
                $codes,
                ($capabilityReceipt['capability_version'] ?? null) === SeoWeeklyDecisionReceiptService::CAPABILITY_VERSION,
                'capability_version_mismatch',
            );
            self::appendUnless(
                $codes,
                hash_equals($capabilityRevision, (string) ($capabilityReceipt['capability_revision'] ?? '')),
                'capability_revision_mismatch',
            );
            self::appendUnless(
                $codes,
                preg_match('/\A[a-f0-9]{40}\z/', (string) ($capabilityReceipt['release_sha'] ?? '')) === 1,
                'capability_release_sha_invalid',
            );
            self::appendUnless($codes, $scheduledFor !== null, 'capability_scheduled_for_invalid');
            if ($scheduledFor !== null) {
                self::appendUnless(
                    $codes,
                    SeoWeeklyDecisionReceiptService::isCapabilitySlot($scheduledFor),
                    'capability_not_natural_slot',
                );
                self::appendUnless(
                    $codes,
                    (string) ($capabilityReceipt['iso_week'] ?? '') === $scheduledFor->format('o-\WW'),
                    'capability_iso_week_mismatch',
                );
            }
            if ($expectedIsoWeek !== null) {
                self::appendUnless(
                    $codes,
                    (string) ($capabilityReceipt['iso_week'] ?? '') === $expectedIsoWeek,
                    'capability_expected_week_mismatch',
                );
            }
            if ($expectedSlot !== null) {
                self::appendUnless(
                    $codes,
                    $scheduledFor?->getTimestamp() === $expectedSlot->setTimezone('UTC')->getTimestamp(),
                    'capability_expected_slot_mismatch',
                );
            }

            self::appendUnless(
                $codes,
                hash_equals($capabilityRevision, (string) ($capabilityRow->capability_revision ?? '')),
                'capability_row_revision_mismatch',
            );
            self::appendUnless(
                $codes,
                (string) ($capabilityRow->iso_week ?? '') === (string) ($capabilityReceipt['iso_week'] ?? ''),
                'capability_row_week_mismatch',
            );
            self::appendUnless(
                $codes,
                (string) ($capabilityRow->evidence_release_sha ?? '') === (string) ($capabilityReceipt['release_sha'] ?? ''),
                'capability_row_release_sha_mismatch',
            );
        }

        if ($selectionReceipt !== null) {
            self::appendUnless(
                $codes,
                ($selectionReceipt['schema_version'] ?? null) === SeoWeeklyDecisionReceiptService::SELECTION_CONTRACT_VERSION,
                'selection_schema_mismatch',
            );
            self::appendUnless(
                $codes,
                ($selectionReceipt['receipt_hash_algorithm'] ?? null) === self::HASH_ALGORITHM,
                'selection_hash_algorithm_mismatch',
            );
            self::appendUnless($codes, ($selectionReceipt['trigger'] ?? null) === 'scheduled', 'selection_trigger_mismatch');
            self::appendUnless(
                $codes,
                ($selectionReceipt['manual_receipts_excluded'] ?? null) === true,
                'selection_manual_exclusion_mismatch',
            );
        }

        if ($capabilityReceipt !== null && $selectionReceipt !== null) {
            $selectionRevision = (string) ($capabilityReceipt['selection_revision'] ?? '');
            self::appendUnless(
                $codes,
                preg_match('/\Aseo_weekly_[0-9]{4}-W[0-9]{2}_[a-f0-9]{16}\z/', $selectionRevision) === 1,
                'selection_revision_invalid',
            );
            self::appendUnless(
                $codes,
                hash_equals($selectionRevision, (string) ($capabilityRow->selection_revision ?? '')),
                'capability_row_selection_revision_mismatch',
            );
            self::appendUnless(
                $codes,
                hash_equals($selectionRevision, (string) ($selectionReceipt['selection_revision'] ?? '')),
                'selection_receipt_revision_mismatch',
            );
            self::appendUnless(
                $codes,
                hash_equals($selectionRevision, (string) ($selectionRow->selection_revision ?? '')),
                'selection_row_revision_mismatch',
            );
            self::appendUnless(
                $codes,
                (int) ($selectionReceipt['decision_count'] ?? -1) === (int) ($capabilityReceipt['decision_count'] ?? -2),
                'decision_count_mismatch',
            );
            self::appendUnless(
                $codes,
                array_values((array) ($selectionReceipt['decision_card_ids'] ?? []))
                    === array_values((array) ($capabilityReceipt['decision_card_ids'] ?? [])),
                'decision_card_ids_mismatch',
            );
            self::appendUnless(
                $codes,
                array_values((array) ($selectionReceipt['decision_revision_ids'] ?? []))
                    === array_values((array) ($capabilityReceipt['decision_revision_ids'] ?? [])),
                'decision_revision_ids_mismatch',
            );
            $decisionCount = (int) ($capabilityReceipt['decision_count'] ?? -1);
            self::appendUnless(
                $codes,
                $decisionCount >= 0 && $decisionCount <= SeoWeeklyDecisionSelector::MAX_COUNT,
                'decision_count_out_of_range',
            );
        }

        $codes = array_values(array_unique($codes));

        return [
            'valid' => $codes === [],
            'capability_receipt' => $capabilityReceipt,
            'selection_receipt' => $selectionReceipt,
            'scheduled_for' => $scheduledFor,
            'mismatch_codes' => $codes,
        ];
    }

    /** @param list<string> $codes */
    private static function appendUnless(array &$codes, bool $condition, string $code): void
    {
        if (! $condition) {
            $codes[] = $code;
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }
}
