<?php

namespace App\Jobs;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\AI\BudgetLedger;
use App\Services\AI\BudgetLedgerException;
use App\Services\AI\EvidenceBuilder;
use App\Services\AI\InsightGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GenerateInsightJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $insightId;

    public function __construct(string $insightId)
    {
        $this->insightId = $insightId;
        $this->onQueue((string) config('ai.queue_name', 'insights'));
    }

    public function handle(InsightGenerator $generator, EvidenceBuilder $evidenceBuilder, BudgetLedger $ledger): void
    {
        if (!\App\Support\SchemaBaseline::hasTable('ai_insights')) {
            Log::warning('[ai_insights] table missing', ['id' => $this->insightId]);
            return;
        }

        $insight = DB::table('ai_insights')->where('id', $this->insightId)->first();
        if (!$insight) {
            Log::warning('[ai_insights] record missing', ['id' => $this->insightId]);
            return;
        }

        DB::table('ai_insights')->where('id', $this->insightId)->update([
            'status' => 'running',
            'updated_at' => now(),
        ]);

        try {
            [$attempt, $result] = $this->resolveAttemptAndResult($insight);
            $evidence = $evidenceBuilder->build($attempt, $result);

            $generated = $generator->generate($insight, $evidence);
            $tokensIn = (int) ($generated['tokens_in'] ?? 0);
            $tokensOut = (int) ($generated['tokens_out'] ?? 0);
            $costUsd = (float) ($generated['cost_usd'] ?? 0.0);
            $outputJson = $this->normalizeJson($generated['output_json'] ?? []);
            $evidenceJson = $this->normalizeJson($generated['evidence_json'] ?? []);

            DB::table('ai_insights')->where('id', $this->insightId)->update([
                'status' => 'succeeded',
                'output_json' => $outputJson,
                'evidence_json' => $evidenceJson,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd' => $costUsd,
                'error_code' => null,
                'updated_at' => now(),
            ]);

            $subject = $this->resolveSubject($insight);
            $provider = (string) ($insight->provider ?? config('ai.provider', 'mock'));
            $model = (string) ($insight->model ?? config('ai.model', 'mock-model'));

            $ledger->incrementTokens($provider, $model, $subject, $tokensIn, $tokensOut, $costUsd, now());
        } catch (BudgetLedgerException $e) {
            $failOpen = (bool) config('ai.fail_open_when_redis_down', false);
            if (!$failOpen) {
                $env = \App\Support\RuntimeConfig::raw('AI_FAIL_OPEN_WHEN_REDIS_DOWN');
                if ($env !== false && $env !== '') {
                    $failOpen = filter_var($env, FILTER_VALIDATE_BOOLEAN);
                }
            }

            if ($e->errorCode() === 'AI_BUDGET_LEDGER_UNAVAILABLE' && $failOpen) {
                Log::warning('[ai_insights] ledger unavailable (fail-open)', [
                    'id' => $this->insightId,
                ]);
                return;
            }

            DB::table('ai_insights')->where('id', $this->insightId)->update([
                'status' => 'failed',
                'error_code' => $e->errorCode(),
                'updated_at' => now(),
            ]);

            Log::warning('[ai_insights] budget ledger failed', [
                'id' => $this->insightId,
                'error' => $e->errorCode(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            DB::table('ai_insights')->where('id', $this->insightId)->update([
                'status' => 'failed',
                'error_code' => 'AI_INSIGHT_FAILED',
                'updated_at' => now(),
            ]);

            Log::warning('[ai_insights] generation failed', [
                'id' => $this->insightId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function resolveAttemptAndResult(object $insight): array
    {
        $userId = trim((string) ($insight->user_id ?? ''));
        $anonId = trim((string) ($insight->anon_id ?? ''));

        $query = Attempt::query();
        if ($userId !== '') {
            $query->where('user_id', $userId);
        } elseif ($anonId !== '') {
            $query->where('anon_id', $anonId);
        }

        $start = $this->parseDate((string) ($insight->period_start ?? ''));
        $end = $this->parseDate((string) ($insight->period_end ?? ''));

        if ($start && $end) {
            $column = \App\Support\SchemaBaseline::hasColumn('attempts', 'submitted_at') ? 'submitted_at' : 'created_at';
            $query->whereBetween($column, [$start->startOfDay(), $end->endOfDay()]);
            $query->orderByDesc($column);
        } else {
            $query->orderByDesc('created_at');
        }

        $attempt = $query->first();
        $result = null;

        if ($attempt) {
            $result = Result::query()->where('attempt_id', $attempt->id)->first();
        }

        return [$attempt, $result];
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveSubject(object $insight): string
    {
        $userId = trim((string) ($insight->user_id ?? ''));
        if ($userId !== '') {
            return 'user:' . $userId;
        }

        $anonId = trim((string) ($insight->anon_id ?? ''));
        if ($anonId !== '') {
            return 'anon:' . $anonId;
        }

        return 'unknown';
    }

    private function normalizeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
