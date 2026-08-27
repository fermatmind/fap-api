<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoDecisionCardReadService
{
    public function __construct(
        private readonly string $connection = 'seo_intel',
    ) {}

    /** @return array{state:string,items:list<array<string,mixed>>,count:int,read_only:bool} */
    public function snapshot(): array
    {
        try {
            $schema = Schema::connection($this->connection);
            if (! $schema->hasTable('seo_decision_cards')
                || ! $schema->hasTable('seo_current_decision_cards')) {
                return $this->unavailable();
            }

            $connection = DB::connection($this->connection);
            $pointerCount = (int) $connection->table('seo_current_decision_cards')->count();
            $rows = $connection->table('seo_current_decision_cards as current')
                ->join('seo_decision_cards as cards', function ($join): void {
                    $join->on('cards.decision_revision_id', '=', 'current.decision_revision_id')
                        ->on('cards.decision_card_id', '=', 'current.decision_card_id')
                        ->on('cards.cluster_uid', '=', 'current.cluster_uid');
                })
                ->orderBy('current.cluster_uid')
                ->select('cards.*')
                ->get();

            if ($rows->count() !== $pointerCount) {
                return $this->unavailable();
            }

            $items = [];
            foreach ($rows as $row) {
                $card = $this->present($row);
                if (! SeoDecisionCardContract::isCard($card)) {
                    return $this->unavailable();
                }
                $items[] = $card;
            }

            return [
                'state' => $items === [] ? 'verified_zero' : 'available',
                'items' => $items,
                'count' => count($items),
                'read_only' => true,
            ];
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $card = [
            'schema_version' => (string) $row->schema_version,
            'decision_revision_id' => (string) $row->decision_revision_id,
            'revision_number' => (int) $row->revision_number,
            'ledger_id' => (string) $row->ledger_id,
            'selection_revision' => $row->selection_revision === null ? null : (string) $row->selection_revision,
            'runtime_revision' => $row->runtime_revision === null ? null : (string) $row->runtime_revision,
            'cache_revision' => $row->cache_revision === null ? null : (string) $row->cache_revision,
            'release_revision' => $row->release_revision === null ? null : (string) $row->release_revision,
            'owner' => (string) $row->owner,
            'evidence_hash' => (string) $row->evidence_hash,
        ];
        foreach (SeoDecisionCardContract::REQUIRED_FIELDS as $field) {
            $card[$field] = match ($field) {
                'affected_unique_url_count' => (int) $row->{$field},
                'measurement_independent' => (bool) $row->{$field},
                'priority_score' => $row->{$field} === null ? null : (float) $row->{$field},
                default => $row->{$field},
            };
        }

        return $card;
    }

    /** @return array{state:string,items:list<array<string,mixed>>,count:int,read_only:bool} */
    private function unavailable(): array
    {
        return [
            'state' => 'unavailable',
            'items' => [],
            'count' => 0,
            'read_only' => true,
        ];
    }
}
