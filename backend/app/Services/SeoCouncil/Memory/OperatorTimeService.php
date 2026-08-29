<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Memory;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Persistence\CouncilRunRepository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OperatorTimeService
{
    private const CATEGORIES = [
        'routine_seo_maintenance', 'seo_growth_project', 'p0_p1_incident',
        'content_research', 'offsite_authority',
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly CouncilRunRepository $runs,
    ) {}

    /** @return array<string, mixed> */
    public function record(string $date, string $category, int $minutes, string $missionHash, string $runHash, string $noteSummary): array
    {
        if (! in_array($category, self::CATEGORIES, true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
            || $minutes < 1 || $minutes > 1440
            || preg_match('/^[a-f0-9]{64}$/D', $missionHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $runHash) !== 1
            || preg_match('/^[A-Z0-9_.:-]{1,160}$/D', $noteSummary) !== 1) {
            throw new InvalidArgumentException('OPERATOR_TIME_ENTRY_INVALID');
        }
        $entry = [
            'entry_id' => $this->hasher->hash([$date, $category, $minutes, $missionHash, $runHash, $noteSummary]),
            'entry_date' => $date,
            'category' => $category,
            'minutes' => $minutes,
            'mission_hash' => $missionHash,
            'run_hash' => $runHash,
            'note_summary' => $noteSummary,
        ];
        $entry['entry_hash'] = $this->hasher->hash($entry);
        if ($this->runs->enabled()) {
            DB::connection((string) config('seo_council.connection', 'seo_intel'))
                ->table('seo_operator_time_entries')->insert([
                    ...$entry,
                    'created_at' => now(),
                ]);
        }

        return $entry;
    }

    /** @return array{state:string,total_minutes:?int,observation_count:int} */
    public function routineMaintenanceBaseline(): array
    {
        if (! $this->runs->enabled()) {
            return ['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0];
        }
        $query = DB::connection((string) config('seo_council.connection', 'seo_intel'))
            ->table('seo_operator_time_entries')
            ->where('category', 'routine_seo_maintenance');
        $count = $query->count();

        return $count === 0
            ? ['state' => 'NO_OBSERVATIONS', 'total_minutes' => null, 'observation_count' => 0]
            : ['state' => 'OBSERVED', 'total_minutes' => (int) $query->sum('minutes'), 'observation_count' => $count];
    }
}
