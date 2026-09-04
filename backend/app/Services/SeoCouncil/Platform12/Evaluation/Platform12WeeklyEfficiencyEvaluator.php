<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12WeeklyEfficiencyEvaluator
{
    private const METRICS = [
        'routing_precision',
        'routing_recall',
        'required_mode_recall',
        'unnecessary_mode_rate',
        'all_team_invocation_rate',
        'human_route_correction_rate',
    ];

    private const TIME_CATEGORIES = [
        'routine_maintenance_minutes',
        'growth_project_minutes',
        'incident_minutes',
        'research_minutes',
        'outreach_minutes',
    ];

    private const LOCALES = ['zh-CN', 'en'];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            $routing = $this->routing($evidence['routing'] ?? null);
            $cost = $this->cost($evidence['cost'] ?? null);
            $humanTime = $this->humanTime($evidence['human_time'] ?? null);
            $localeBriefs = $this->localeBriefs($evidence['locale_briefs'] ?? null);
            $state = 'READY';
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $routing = array_fill_keys(self::METRICS, $this->notMeasuredRatio());
            $cost = ['model_cost_microusd' => null, 'tool_cost_microusd' => null, 'measurement_state' => 'NOT_MEASURED'];
            $humanTime = array_fill_keys(self::TIME_CATEGORIES, null);
            $humanTime['measurement_state'] = 'NOT_MEASURED';
            $localeBriefs = array_map(
                static fn (string $locale): array => ['locale' => $locale, 'measurement_state' => 'NOT_MEASURED', 'brief_code' => null, 'unknowns' => ['evidence_invalid']],
                self::LOCALES,
            );
            $state = 'MEASUREMENT_HOLD';
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_weekly_efficiency.v1',
            'mission_id' => 'seo.platform12.weekly_routing_cost_time_locale_brief',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'routing' => $routing,
            'cost' => $cost,
            'human_time' => $humanTime,
            'locale_briefs' => $localeBriefs,
            'routine_time_excludes_projects_incidents_research_outreach' => true,
            'artifact_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return array<string,array<string,int|string|null>> */
    private function routing(mixed $source): array
    {
        if (! is_array($source) || ! $this->hasExactKeys($source, self::METRICS)) {
            throw new InvalidArgumentException('ROUTING_METRICS_INVALID');
        }

        $routing = [];
        foreach (self::METRICS as $metric) {
            $routing[$metric] = $this->ratio($source[$metric]);
        }

        return $routing;
    }

    /** @return array{numerator:int|null,denominator:int,measurement_state:string} */
    private function ratio(mixed $source): array
    {
        if (! is_array($source)
            || ! $this->hasExactKeys($source, ['numerator', 'denominator'])
            || ! is_int($source['numerator'] ?? null)
            || ! is_int($source['denominator'] ?? null)
            || $source['numerator'] < 0
            || $source['denominator'] < 0
            || $source['numerator'] > $source['denominator']
            || $source['denominator'] > 100000000) {
            throw new InvalidArgumentException('ROUTING_RATIO_INVALID');
        }
        if ($source['denominator'] === 0) {
            return $this->notMeasuredRatio();
        }

        return ['numerator' => $source['numerator'], 'denominator' => $source['denominator'], 'measurement_state' => 'OBSERVED'];
    }

    /** @return array{numerator:null,denominator:int,measurement_state:string} */
    private function notMeasuredRatio(): array
    {
        return ['numerator' => null, 'denominator' => 0, 'measurement_state' => 'NOT_MEASURED'];
    }

    /** @return array{model_cost_microusd:int,tool_cost_microusd:int,measurement_state:string} */
    private function cost(mixed $source): array
    {
        if (! is_array($source) || ! $this->hasExactKeys($source, ['model_cost_microusd', 'tool_cost_microusd'])) {
            throw new InvalidArgumentException('COST_INVALID');
        }
        foreach (['model_cost_microusd', 'tool_cost_microusd'] as $field) {
            if (! is_int($source[$field] ?? null) || $source[$field] < 0 || $source[$field] > 1000000000000) {
                throw new InvalidArgumentException('COST_INVALID');
            }
        }

        return ['model_cost_microusd' => $source['model_cost_microusd'], 'tool_cost_microusd' => $source['tool_cost_microusd'], 'measurement_state' => 'OBSERVED'];
    }

    /** @return array<string,int|string> */
    private function humanTime(mixed $source): array
    {
        if (! is_array($source) || ! $this->hasExactKeys($source, self::TIME_CATEGORIES)) {
            throw new InvalidArgumentException('HUMAN_TIME_INVALID');
        }
        foreach (self::TIME_CATEGORIES as $category) {
            if (! is_int($source[$category]) || $source[$category] < 0 || $source[$category] > 1000000) {
                throw new InvalidArgumentException('HUMAN_TIME_INVALID');
            }
        }

        return [...$source, 'measurement_state' => 'OBSERVED'];
    }

    /** @return list<array<string,mixed>> */
    private function localeBriefs(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || array_column($source, 'locale') !== self::LOCALES) {
            throw new InvalidArgumentException('LOCALE_BRIEFS_INVALID');
        }

        return array_map(function (mixed $brief): array {
            if (! is_array($brief)
                || ! $this->hasExactKeys($brief, ['locale', 'measurement_state', 'brief_code', 'unknowns'])
                || ! in_array($brief['measurement_state'] ?? null, ['OBSERVED', 'NOT_MEASURED'], true)
                || ! is_array($brief['unknowns'] ?? null)
                || ! array_is_list($brief['unknowns'])
                || count($brief['unknowns']) > 20) {
                throw new InvalidArgumentException('LOCALE_BRIEF_INVALID');
            }
            $briefCode = $brief['brief_code'] ?? null;
            if (($brief['measurement_state'] === 'OBSERVED' && (! is_string($briefCode) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $briefCode) !== 1))
                || ($brief['measurement_state'] === 'NOT_MEASURED' && $briefCode !== null)) {
                throw new InvalidArgumentException('LOCALE_BRIEF_INVALID');
            }
            foreach ($brief['unknowns'] as $unknown) {
                if (! is_string($unknown) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $unknown) !== 1) {
                    throw new InvalidArgumentException('LOCALE_BRIEF_INVALID');
                }
            }

            return array_intersect_key($brief, array_flip(['locale', 'measurement_state', 'brief_code', 'unknowns']));
        }, $source);
    }

    /** @param array<string,mixed> $evidence */
    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }

    /** @param array<mixed> $source @param list<string> $expected */
    private function hasExactKeys(array $source, array $expected): bool
    {
        $keys = array_keys($source);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
