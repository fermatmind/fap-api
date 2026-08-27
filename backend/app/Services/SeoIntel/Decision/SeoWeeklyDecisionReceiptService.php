<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoWeeklyDecisionReceiptService
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_receipt.v2';

    private const SELECTION_CONTRACT_VERSION = 'seo.weekly_decision_receipt.v1';

    public const CAPABILITY_VERSION = 'seo.weekly_decision_natural.v2';

    public const NATURAL_SLOT_DAY = 4;

    public const NATURAL_SLOT_TIME = '13:45';

    public const TRANSACTION_DEADLINE_SECONDS = 50;

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
        if (! self::isNaturalSlot($slot)) {
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
        $selection = $this->selector->snapshot($slot);
        $releaseSha = $this->releaseSha();
        if ($selection['state'] === 'unavailable' || $releaseSha === null) {
            throw new RuntimeException('Weekly decision authority or release SHA is unavailable.');
        }

        $capabilityRevision = self::capabilityRevision();

        $deadline = hrtime(true) + (self::TRANSACTION_DEADLINE_SECONDS * 1_000_000_000);

        return $this->db()->transaction(function () use ($selection, $slot, $releaseSha, $capabilityRevision, $deadline): array {
            $this->assertWithinDeadline($deadline);
            $existing = $this->db()->table('seo_weekly_decision_capability_receipts')
                ->where('selection_revision', $selection['selection_revision'])
                ->where('capability_revision', $capabilityRevision)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertWithinDeadline($deadline);

                return $this->presentCapability($existing, true);
            }

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
                'status' => 'scheduled_completed',
                'trigger' => 'scheduled',
                'iso_week' => $selection['iso_week'],
                'selection_revision' => $selection['selection_revision'],
                'capability_version' => self::CAPABILITY_VERSION,
                'capability_revision' => $capabilityRevision,
                'release_sha' => $releaseSha,
                'scheduled_for' => $slot->format('Y-m-d\TH:i:s\Z'),
                'decision_count' => $selection['count'],
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
            $receiptJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $receiptHash = hash('sha256', $receiptJson);
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

            return array_merge($payload, [
                'receipt_id' => $row['receipt_id'],
                'receipt_hash' => $receiptHash,
                'persisted' => true,
                'idempotent_replay' => false,
            ]);
        }, 3);
    }

    private function assertWithinDeadline(int $deadline): void
    {
        if (hrtime(true) > $deadline) {
            throw new RuntimeException('Weekly decision transaction exceeded its 50 second deadline.');
        }
    }

    /** @return array<string, mixed> */
    private function presentCapability(object $row, bool $replay): array
    {
        $payload = json_decode((string) $row->receipt_json, true);
        if (! is_array($payload)
            || ! hash_equals((string) $row->receipt_hash, hash('sha256', (string) $row->receipt_json))) {
            throw new RuntimeException('Weekly decision receipt integrity check failed.');
        }

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
            'status' => 'scheduled_completed',
            'trigger' => 'scheduled',
            'iso_week' => $selection['iso_week'],
            'selection_revision' => $selection['selection_revision'],
            'release_sha' => $releaseSha,
            'scheduled_for' => $slot->format('Y-m-d\TH:i:s\Z'),
            'decision_count' => $selection['count'],
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
        $receiptJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
            'receipt_hash' => hash('sha256', $receiptJson),
            'created_at' => $slot,
        ]);

        return [$payload, $revisionIds, $createdRevisionCount];
    }

    /** @param array<string, mixed> $selection @return array<string, mixed> */
    private function decodeSelectionReceipt(object $row, array $selection): array
    {
        $payload = json_decode((string) $row->receipt_json, true);
        if (! is_array($payload)
            || ! hash_equals((string) $row->receipt_hash, hash('sha256', (string) $row->receipt_json))
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
        foreach ([
            trim((string) config('app.git_sha', '')),
            is_file(dirname(base_path()).'/REVISION') ? trim((string) file_get_contents(dirname(base_path()).'/REVISION')) : '',
        ] as $candidate) {
            if (preg_match('/\A[a-f0-9]{40}\z/i', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    public static function capabilityRevision(): string
    {
        return hash('sha256', implode('|', [
            self::CAPABILITY_VERSION,
            (string) self::NATURAL_SLOT_DAY,
            self::NATURAL_SLOT_TIME,
            SeoWeeklyDecisionSelector::CONTRACT_VERSION,
        ]));
    }

    public static function isNaturalSlot(CarbonImmutable $slot): bool
    {
        $slot = $slot->setTimezone('UTC');

        return (int) $slot->isoWeekday() === self::NATURAL_SLOT_DAY
            && $slot->format('H:i') === self::NATURAL_SLOT_TIME;
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
