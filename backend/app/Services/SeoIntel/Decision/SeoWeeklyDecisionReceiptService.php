<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoWeeklyDecisionReceiptService
{
    public const CONTRACT_VERSION = 'seo.weekly_decision_receipt.v1';

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
        if ((int) $slot->isoWeekday() !== 4 || $slot->format('H:i') !== '09:30') {
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

        return $this->db()->transaction(function () use ($selection, $slot, $releaseSha): array {
            $existing = $this->db()->table('seo_weekly_decision_receipts')
                ->where('selection_revision', $selection['selection_revision'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->present($existing, true);
            }

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
                'schema_version' => self::CONTRACT_VERSION,
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
            $receiptHash = hash('sha256', $receiptJson);
            $row = [
                'receipt_id' => $this->deterministicUuid((string) $selection['selection_revision']),
                'selection_revision' => $selection['selection_revision'],
                'iso_week' => $selection['iso_week'],
                'release_sha' => $releaseSha,
                'scheduled_for' => $slot,
                'decision_count' => $selection['count'],
                'decision_card_ids_json' => json_encode($payload['decision_card_ids'], JSON_THROW_ON_ERROR),
                'decision_revision_ids_json' => json_encode($revisionIds, JSON_THROW_ON_ERROR),
                'receipt_json' => $receiptJson,
                'receipt_hash' => $receiptHash,
                'created_at' => $slot,
            ];
            $this->db()->table('seo_weekly_decision_receipts')->insert($row);

            return array_merge($payload, [
                'receipt_id' => $row['receipt_id'],
                'receipt_hash' => $receiptHash,
                'persisted' => true,
                'idempotent_replay' => false,
            ]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function present(object $row, bool $replay): array
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
