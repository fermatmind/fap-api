<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoWeeklyDecisionReceiptService
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_receipt.v4';

    public const SELECTION_CONTRACT_VERSION = 'seo.weekly_decision_selection_receipt.v3';

    public const CAPABILITY_VERSION = 'seo.weekly_decision_natural.v3';

    public const NATURAL_SLOT_DAY = 4;

    public const NATURAL_SLOT_TIME = '13:45';

    public const CAPABILITY_EFFECTIVE_SLOT = '2026-09-10T13:45:00Z';

    public const TRANSACTION_DEADLINE_SECONDS = 50;

    public const LOCK_KEY = 'seo-weekly-decisions:planning';

    public function __construct(
        private readonly SeoWeeklyDecisionSelector $selector,
        private readonly string $connection = 'seo_intel',
    ) {}

    /** @return array<string, mixed> */
    public function record(string $trigger, ?CarbonImmutable $scheduledFor = null): array
    {
        if ($trigger !== 'scheduled') {
            return [
                'schema_version' => self::CONTRACT_VERSION,
                'status' => 'MEASUREMENT_HOLD',
                'reason' => 'natural_scheduler_receipt_required',
                'trigger' => 'manual',
                'persisted' => false,
                'manual_receipts_excluded' => true,
                'decision_count' => null,
                'l3_enabled' => false,
                'l4_enabled' => false,
                'search_submission_allowed' => false,
            ];
        }

        $slot = ($scheduledFor ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        if (! self::isCapabilitySlot($slot)) {
            return [
                'schema_version' => self::CONTRACT_VERSION,
                'status' => 'MEASUREMENT_HOLD',
                'reason' => 'outside_natural_scheduler_slot',
                'trigger' => 'scheduled',
                'persisted' => false,
                'manual_receipts_excluded' => true,
                'decision_count' => null,
                'l3_enabled' => false,
                'l4_enabled' => false,
                'search_submission_allowed' => false,
            ];
        }
        if (! app()->environment('testing') && $scheduledFor !== null) {
            throw new RuntimeException('Natural time cannot be supplied by a caller.');
        }
        if ($this->selector->connectionName() !== $this->connection) {
            throw new RuntimeException('Natural planning must share one database connection.');
        }
        $releaseSha = $this->releaseSha();
        if ($releaseSha === null) {
            throw new RuntimeException('Weekly decision release SHA is unavailable.');
        }
        $capabilityRevision = self::capabilityRevision();
        $deadline = hrtime(true) + (self::TRANSACTION_DEADLINE_SECONDS * 1_000_000_000);
        // Reuse the scheduler's shared atomic cache-lock backend. This exists even
        // with empty receipt/card tables, and its identity never includes a SHA or version.
        $lock = \Illuminate\Support\Facades\Cache::lock(self::LOCK_KEY, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Natural weekly planning is already locked.');
        }
        try {
            return $this->db()->transaction(function () use ($slot, $releaseSha, $capabilityRevision, $deadline): array {
                $this->assertWithinDeadline($deadline);
                $week = $slot->format('o-\WW');
                $existingRows = $this->db()->table('seo_weekly_decision_capability_receipts')
                    ->where('iso_week', $week)->lockForUpdate()->get();
                $selectionRows = $this->db()->table('seo_weekly_decision_receipts')
                    ->where('iso_week', $week)->lockForUpdate()->get();
                if ($existingRows->count() > 1 || $selectionRows->count() > 1
                    || ($existingRows->isEmpty() !== $selectionRows->isEmpty())) {
                    throw new RuntimeException('Natural window receipts conflict or are incomplete.');
                }
                if ($existingRows->isNotEmpty()) {
                    $existing = $existingRows->first();
                    $selectionRow = $selectionRows->first();
                    $result = $this->presentCapability($existing, $selectionRow, ['iso_week' => $week], CarbonImmutable::parse($existing->scheduled_for, 'UTC'), true);
                    $this->assertReferences($result, false);
                    $this->assertWithinDeadline($deadline);

                    return $result;
                }
                $summary = (new SeoOpportunityCardGenerator($this->connection))->generate(
                    $slot, $releaseSha, fn () => $this->assertWithinDeadline($deadline),
                );
                $selection = $this->selector->snapshot($slot);
                if ($selection['state'] === 'unavailable') {
                    throw new RuntimeException('Weekly decision authority is unavailable.');
                }
                // Natural identity is independent of candidate order/content, release and generator.
                $selection['selection_revision'] = 'seo_weekly_'.$week.'_'.substr(hash('sha256', 'natural-week|'.$week), 0, 16);
                $selection['generation_summary'] = $summary;

                $selectionRow = $this->db()->table('seo_weekly_decision_receipts')
                    ->where('selection_revision', $selection['selection_revision'])
                    ->lockForUpdate()
                    ->first();
                if ($selectionRow === null) {
                    [$selectionPayload, $revisionIds, $createdRevisionCount] = $this->createSelectionReceipt(
                        $selection,
                        $slot,
                        $releaseSha,
                    );
                } else {
                    $selectionPayload = $this->decodeSelectionReceipt($selectionRow, $selection);
                    $revisionIds = array_values($selectionPayload['decision_revision_ids']);
                    $createdRevisionCount = 0;
                }
                $this->assertWithinDeadline($deadline);

                $payload = [
                    'schema_version' => self::CONTRACT_VERSION,
                    'receipt_hash_algorithm' => SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM,
                    'status' => 'scheduled_completed',
                    'trigger' => 'scheduled',
                    'iso_week' => $selection['iso_week'],
                    'selection_revision' => $selection['selection_revision'],
                    'capability_version' => self::CAPABILITY_VERSION,
                    'capability_revision' => $capabilityRevision,
                    'release_sha' => $releaseSha,
                    'scheduled_for' => $slot->format('Y-m-d\TH:i:s\Z'),
                    'decision_count' => $selection['count'],
                    'generation_summary' => $summary,
                    'planning_records_write_allowed' => true,
                    'business_execution_allowed' => false,
                    'decision_card_ids' => array_values($selectionPayload['decision_card_ids']),
                    'decision_revision_ids' => $revisionIds,
                    'created_selection_revision_count' => $createdRevisionCount,
                    'padded' => false,
                    'manual_receipts_excluded' => true,
                    'read_only_snapshot' => true,
                    'l3_enabled' => false,
                    'l4_enabled' => false,
                    'search_submission_allowed' => false,
                ];
                $receiptJson = SeoWeeklyDecisionReceiptValidator::encode($payload);
                $receiptHash = SeoWeeklyDecisionReceiptValidator::hash($payload);
                $row = [
                    'receipt_id' => $this->deterministicUuid((string) $selection['selection_revision'].'|'.$capabilityRevision),
                    'selection_revision' => $selection['selection_revision'],
                    'capability_revision' => $capabilityRevision,
                    'iso_week' => $selection['iso_week'],
                    'evidence_release_sha' => $releaseSha,
                    'scheduled_for' => $slot,
                    'decision_count' => $selection['count'],
                    'decision_card_ids_json' => json_encode($payload['decision_card_ids'], JSON_THROW_ON_ERROR),
                    'decision_revision_ids_json' => json_encode($revisionIds, JSON_THROW_ON_ERROR),
                    'receipt_json' => $receiptJson,
                    'receipt_hash' => $receiptHash,
                    'created_at' => $slot,
                ];
                $this->db()->table('seo_weekly_decision_capability_receipts')->insert($row);
                $this->assertWithinDeadline($deadline);

                $storedCapability = $this->db()->table('seo_weekly_decision_capability_receipts')
                    ->where('receipt_id', $row['receipt_id'])
                    ->first();
                $storedSelection = $this->db()->table('seo_weekly_decision_receipts')
                    ->where('selection_revision', $selection['selection_revision'])
                    ->first();
                $validation = SeoWeeklyDecisionReceiptValidator::validatePair(
                    $storedCapability,
                    $storedSelection,
                    $capabilityRevision,
                    (string) $selection['iso_week'],
                    $slot,
                );
                if (! $validation['valid']) {
                    throw new RuntimeException('Weekly decision receipt readback failed: '.implode(',', $validation['mismatch_codes']));
                }

                $this->assertReferences($payload, true);
                $this->assertWithinDeadline($deadline);

                return array_merge($payload, [
                    'receipt_id' => $row['receipt_id'],
                    'receipt_hash' => $receiptHash,
                    'persisted' => true,
                    'idempotent_replay' => false,
                ]);
            }, 1);
        } finally {
            $lock->release();
        }
    }

    private function assertReferences(array $receipt, bool $currentRequired): void
    {
        foreach ($receipt['decision_revision_ids'] as $i => $id) {
            $card = $this->db()->table('seo_decision_cards')->where('decision_revision_id', $id)->first();
            if ($card === null || $card->decision_card_id !== ($receipt['decision_card_ids'][$i] ?? null)
                || ! $this->db()->table('seo_change_ledgers')->where('ledger_id', $card->ledger_id)->exists()) {
                throw new RuntimeException('Weekly decision reference readback failed.');
            }
            if ($currentRequired && ! $this->db()->table('seo_current_decision_cards')->where('cluster_uid', $card->cluster_uid)
                ->where('decision_revision_id', $id)->where('decision_card_id', $card->decision_card_id)->exists()) {
                throw new RuntimeException('Weekly decision pointer readback failed.');
            }
            (new SeoDecisionBrief($this->connection))->load($card);
        }
        if ($currentRequired) {
            // Include generated-but-not-selected cards in transaction-wide integrity validation.
            foreach ($this->db()->table('seo_current_decision_cards as p')->join('seo_decision_cards as c', 'c.decision_revision_id', '=', 'p.decision_revision_id')
                ->where('c.detector', SeoOpportunityCardGenerator::ID)->select('c.*')->get() as $card) {
                if (! $this->db()->table('seo_change_ledgers')->where('ledger_id', $card->ledger_id)->exists()
                    || (new SeoDecisionBrief($this->connection))->load($card) === null) {
                    throw new RuntimeException('Generated decision readback failed.');
                }
            }
        }
    }

    private function assertWithinDeadline(int $deadline): void
    {
        if (hrtime(true) > $deadline) {
            throw new RuntimeException('Weekly decision transaction exceeded its 50 second deadline.');
        }
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    private function presentCapability(
        object $row,
        ?object $selectionRow,
        array $selection,
        CarbonImmutable $slot,
        bool $replay,
    ): array {
        $validation = SeoWeeklyDecisionReceiptValidator::validatePair(
            $row,
            $selectionRow,
            self::capabilityRevision(),
            (string) $selection['iso_week'],
            $slot,
        );
        if (! $validation['valid'] || $validation['capability_receipt'] === null) {
            throw new RuntimeException('Weekly decision receipt integrity check failed: '.implode(',', $validation['mismatch_codes']));
        }
        $payload = $validation['capability_receipt'];

        return array_merge($payload, [
            'receipt_id' => (string) $row->receipt_id,
            'receipt_hash' => (string) $row->receipt_hash,
            'persisted' => true,
            'idempotent_replay' => $replay,
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array{0:array<string,mixed>,1:list<string>,2:int}
     */
    private function createSelectionReceipt(array $selection, CarbonImmutable $slot, string $releaseSha): array
    {
        $revisionIds = [];
        $createdRevisionCount = 0;
        foreach ($selection['decisions'] as $card) {
            $revisionId = (string) $card['decision_revision_id'];
            if ($card['status'] === 'candidate') {
                $card['selection_revision'] = $selection['selection_revision'];
                $result = (new SeoDecisionLifecycleMaterializer($this->connection))->materialize(
                    $card,
                    'selected',
                    'weekly-selection:'.hash('sha256', $selection['selection_revision'].'|'.$card['cluster_uid']),
                    ['evidence_fresh' => true],
                );
                $revisionId = (string) $result['decision_revision_id'];
                $createdRevisionCount += $result['idempotent_replay'] ? 0 : 1;
            }
            $revisionIds[] = $revisionId;
        }

        $payload = [
            'schema_version' => self::SELECTION_CONTRACT_VERSION,
            'receipt_hash_algorithm' => SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM,
            'status' => 'scheduled_completed',
            'trigger' => 'scheduled',
            'iso_week' => $selection['iso_week'],
            'selection_revision' => $selection['selection_revision'],
            'release_sha' => $releaseSha,
            'scheduled_for' => $slot->format('Y-m-d\TH:i:s\Z'),
            'decision_count' => $selection['count'],
            'generation_summary' => $selection['generation_summary'],
            'planning_records_write_allowed' => true,
            'business_execution_allowed' => false,
            'decision_card_ids' => array_column($selection['decisions'], 'decision_card_id'),
            'decision_revision_ids' => $revisionIds,
            'created_selection_revision_count' => $createdRevisionCount,
            'padded' => false,
            'manual_receipts_excluded' => true,
            'read_only_snapshot' => true,
            'l3_enabled' => false,
            'l4_enabled' => false,
            'search_submission_allowed' => false,
        ];
        $receiptJson = SeoWeeklyDecisionReceiptValidator::encode($payload);
        $this->db()->table('seo_weekly_decision_receipts')->insert([
            'receipt_id' => $this->deterministicUuid((string) $selection['selection_revision']),
            'selection_revision' => $selection['selection_revision'],
            'iso_week' => $selection['iso_week'],
            'release_sha' => $releaseSha,
            'scheduled_for' => $slot,
            'decision_count' => $selection['count'],
            'decision_card_ids_json' => json_encode($payload['decision_card_ids'], JSON_THROW_ON_ERROR),
            'decision_revision_ids_json' => json_encode($revisionIds, JSON_THROW_ON_ERROR),
            'receipt_json' => $receiptJson,
            'receipt_hash' => SeoWeeklyDecisionReceiptValidator::hash($payload),
            'created_at' => $slot,
        ]);

        return [$payload, $revisionIds, $createdRevisionCount];
    }

    /** @param array<string, mixed> $selection @return array<string, mixed> */
    private function decodeSelectionReceipt(object $row, array $selection): array
    {
        $verified = SeoWeeklyDecisionReceiptValidator::decodeAndVerify($row, 'selection');
        $payload = $verified['payload'];
        if (! $verified['valid']
            || ! is_array($payload)
            || ($payload['schema_version'] ?? null) !== self::SELECTION_CONTRACT_VERSION
            || ($payload['receipt_hash_algorithm'] ?? null) !== SeoWeeklyDecisionReceiptValidator::HASH_ALGORITHM
            || ($payload['trigger'] ?? null) !== 'scheduled'
            || ! hash_equals((string) $selection['selection_revision'], (string) ($payload['selection_revision'] ?? ''))
            || (int) ($payload['decision_count'] ?? -1) !== (int) $selection['count']
            || array_values((array) ($payload['decision_card_ids'] ?? [])) !== array_values(array_column($selection['decisions'], 'decision_card_id'))
            || count((array) ($payload['decision_revision_ids'] ?? [])) !== (int) $selection['count']) {
            throw new RuntimeException('Weekly decision selection receipt integrity check failed.');
        }

        return $payload;
    }

    private function releaseSha(): ?string
    {
        $configured = trim((string) config('app.git_sha', ''));
        $file = dirname(base_path()).'/REVISION';
        $active = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($active !== '' && $configured !== '' && ! hash_equals(strtolower($active), strtolower($configured))) {
            throw new RuntimeException('Active release SHA differs from configured SHA.');
        }
        $candidate = $active !== '' ? $active : $configured;
        if (preg_match('/\A[a-f0-9]{40}\z/i', $candidate) === 1) {
            return strtolower($candidate);
        }

        return null;
    }

    public static function capabilityRevision(): string
    {
        return hash('sha256', implode('|', [
            self::CAPABILITY_VERSION,
            (string) self::NATURAL_SLOT_DAY,
            self::NATURAL_SLOT_TIME,
            self::CAPABILITY_EFFECTIVE_SLOT,
            SeoWeeklyDecisionSelector::CONTRACT_VERSION,
        ]));
    }

    public static function isNaturalSlot(CarbonImmutable $slot): bool
    {
        $slot = $slot->setTimezone('UTC');

        return (int) $slot->isoWeekday() === self::NATURAL_SLOT_DAY
            && $slot->format('H:i') === self::NATURAL_SLOT_TIME;
    }

    public static function isCapabilitySlot(CarbonImmutable $slot): bool
    {
        $slot = $slot->setTimezone('UTC');

        return self::isNaturalSlot($slot)
            && $slot->greaterThanOrEqualTo(CarbonImmutable::parse(self::CAPABILITY_EFFECTIVE_SLOT, 'UTC'));
    }

    public static function naturalSlotForWeek(CarbonImmutable $now): CarbonImmutable
    {
        $now = $now->setTimezone('UTC');
        [$hour, $minute] = array_map('intval', explode(':', self::NATURAL_SLOT_TIME));

        return $now->startOfWeek()
            ->addDays(self::NATURAL_SLOT_DAY - 1)
            ->setTime($hour, $minute);
    }

    private function deterministicUuid(string $identity): string
    {
        $hex = hash('sha256', 'fermatmind-seo-weekly-receipt|'.$identity);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
