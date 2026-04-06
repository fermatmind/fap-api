<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReportMbtiAttributionDailyCommand extends Command
{
    protected $signature = 'analytics:report-mbti-attribution-daily
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--org=* : Limit to one or more org ids}
        {--entry-surface= : Filter one entry surface}
        {--json : Output JSON rows}';

    protected $description = 'Read MBTI attribution funnel metrics grouped by entry surface/source page type.';

    public function handle(): int
    {
        $from = $this->parseDateOption((string) $this->option('from'), now()->subDays(6)->toDateString());
        $to = $this->parseDateOption((string) $this->option('to'), now()->toDateString());

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');

            return self::FAILURE;
        }

        $orgIds = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            (array) $this->option('org')
        ), static fn (int $value): bool => $value >= 0));

        $query = DB::table('analytics_mbti_attribution_daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('entry_surface, source_page_type, locale, SUM(entry_views) as entry_views, SUM(start_clicks) as start_clicks, SUM(start_attempts) as start_attempts, SUM(result_views) as result_views, SUM(unlock_clicks) as unlock_clicks, SUM(orders_created) as orders_created, SUM(payments_confirmed) as payments_confirmed, SUM(unlock_successes) as unlock_successes, SUM(payment_unlock_successes) as payment_unlock_successes, SUM(invite_creates) as invite_creates, SUM(invite_shares) as invite_shares, SUM(invite_completions) as invite_completions, SUM(invite_unlock_successes) as invite_unlock_successes')
            ->groupBy(['entry_surface', 'source_page_type', 'locale'])
            ->orderByDesc('payments_confirmed');

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $entrySurface = strtolower(trim((string) $this->option('entry-surface')));
        if ($entrySurface !== '') {
            $query->where('entry_surface', $entrySurface);
        }

        $rows = $query->get()->map(static function (object $row): array {
            $unlockSuccesses = max(0, (int) ($row->unlock_successes ?? 0));
            $inviteUnlockSuccesses = max(0, (int) ($row->invite_unlock_successes ?? 0));

            return [
                'entry_surface' => (string) ($row->entry_surface ?? 'unknown'),
                'source_page_type' => (string) ($row->source_page_type ?? 'unknown'),
                'locale' => (string) ($row->locale ?? 'en'),
                'entry_views' => max(0, (int) ($row->entry_views ?? 0)),
                'start_clicks' => max(0, (int) ($row->start_clicks ?? 0)),
                'start_attempts' => max(0, (int) ($row->start_attempts ?? 0)),
                'result_views' => max(0, (int) ($row->result_views ?? 0)),
                'unlock_clicks' => max(0, (int) ($row->unlock_clicks ?? 0)),
                'orders_created' => max(0, (int) ($row->orders_created ?? 0)),
                'payments_confirmed' => max(0, (int) ($row->payments_confirmed ?? 0)),
                'unlock_successes' => $unlockSuccesses,
                'payment_unlock_successes' => max(0, (int) ($row->payment_unlock_successes ?? 0)),
                'invite_creates' => max(0, (int) ($row->invite_creates ?? 0)),
                'invite_shares' => max(0, (int) ($row->invite_shares ?? 0)),
                'invite_completions' => max(0, (int) ($row->invite_completions ?? 0)),
                'invite_unlock_successes' => $inviteUnlockSuccesses,
                'invite_unlock_contribution_rate' => $unlockSuccesses > 0
                    ? round($inviteUnlockSuccesses / $unlockSuccesses, 4)
                    : 0.0,
            ];
        })->values()->all();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'rows' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('No MBTI attribution rows found for the selected scope.');

            return self::SUCCESS;
        }

        $this->table([
            'entry_surface',
            'source_page_type',
            'locale',
            'entry_views',
            'start_clicks',
            'start_attempts',
            'result_views',
            'unlock_clicks',
            'orders_created',
            'payments_confirmed',
            'unlock_successes',
            'invite_creates',
            'invite_shares',
            'invite_completions',
            'invite_unlock_rate',
        ], array_map(static fn (array $row): array => [
            $row['entry_surface'],
            $row['source_page_type'],
            $row['locale'],
            $row['entry_views'],
            $row['start_clicks'],
            $row['start_attempts'],
            $row['result_views'],
            $row['unlock_clicks'],
            $row['orders_created'],
            $row['payments_confirmed'],
            $row['unlock_successes'],
            $row['invite_creates'],
            $row['invite_shares'],
            $row['invite_completions'],
            $row['invite_unlock_contribution_rate'],
        ], $rows));

        return self::SUCCESS;
    }

    private function parseDateOption(string $value, string $fallback): CarbonImmutable
    {
        $candidate = trim($value) !== '' ? trim($value) : $fallback;

        return CarbonImmutable::createFromFormat('Y-m-d', $candidate)?->startOfDay()
            ?? CarbonImmutable::parse($candidate)->startOfDay();
    }
}
