<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Services\Report\ReportAccess;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BenefitModuleRuleCatalog
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return list<string>
     */
    public function modulesForBenefitCode(int $orgId, string $benefitCode): array
    {
        $resolved = $this->resolve($orgId, $benefitCode);

        return ReportAccess::normalizeModules($resolved['modules']);
    }

    public function freeModuleForBenefitCode(int $orgId, string $benefitCode): string
    {
        $resolved = $this->resolve($orgId, $benefitCode);
        $freeModule = strtolower(trim((string) ($resolved['free_module'] ?? '')));

        if ($freeModule === '') {
            return ReportAccess::MODULE_CORE_FREE;
        }

        return $freeModule;
    }

    /**
     * @return array{modules:list<string>,free_module:string}
     */
    private function resolve(int $orgId, string $benefitCode): array
    {
        $benefitCode = strtoupper(trim($benefitCode));
        if ($benefitCode === '') {
            return [
                'modules' => [],
                'free_module' => ReportAccess::MODULE_CORE_FREE,
            ];
        }

        if (! SchemaBaseline::hasTable('benefit_module_rules')) {
            return [
                'modules' => [],
                'free_module' => ReportAccess::MODULE_CORE_FREE,
            ];
        }

        $cacheKey = $this->cacheKey($orgId, $benefitCode);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $modules = ReportAccess::normalizeModules(is_array($cached['modules'] ?? null) ? $cached['modules'] : []);
            $freeModule = strtolower(trim((string) ($cached['free_module'] ?? '')));
            if ($freeModule === '') {
                $freeModule = ReportAccess::MODULE_CORE_FREE;
            }

            return [
                'modules' => $modules,
                'free_module' => $freeModule,
            ];
        }

        $resolved = $this->queryRules($orgId, $benefitCode);
        Cache::put($cacheKey, $resolved, self::CACHE_TTL_SECONDS);

        return $resolved;
    }

    private function cacheKey(int $orgId, string $benefitCode): string
    {
        return 'fap:benefit_module_rules:'.$orgId.':'.$benefitCode;
    }

    /**
     * @return array{modules:list<string>,free_module:string}
     */
    private function queryRules(int $orgId, string $benefitCode): array
    {
        $orgIds = $orgId > 0 ? [0, $orgId] : [0];

        $rows = DB::table('benefit_module_rules')
            ->whereIn('org_id', $orgIds)
            ->where('benefit_code', $benefitCode)
            ->where('is_active', true)
            ->get();

        if ($rows->isEmpty()) {
            return [
                'modules' => [],
                'free_module' => ReportAccess::MODULE_CORE_FREE,
            ];
        }

        $selectedByModule = [];
        foreach ($rows as $row) {
            $moduleCode = strtolower(trim((string) ($row->module_code ?? '')));
            if ($moduleCode === '') {
                continue;
            }

            $rowOrgId = (int) ($row->org_id ?? 0);
            $rowPriority = (int) ($row->priority ?? 100);
            $rowTier = strtolower(trim((string) ($row->access_tier ?? 'paid')));
            if ($rowTier !== 'free') {
                $rowTier = 'paid';
            }

            $candidate = [
                'module_code' => $moduleCode,
                'priority' => $rowPriority,
                'access_tier' => $rowTier,
                'org_rank' => $rowOrgId === $orgId ? 1 : 0,
            ];

            $existing = $selectedByModule[$moduleCode] ?? null;
            if (! is_array($existing)) {
                $selectedByModule[$moduleCode] = $candidate;

                continue;
            }

            $existingRank = (int) ($existing['org_rank'] ?? 0);
            $existingPriority = (int) ($existing['priority'] ?? 100);

            if ($candidate['org_rank'] > $existingRank) {
                $selectedByModule[$moduleCode] = $candidate;

                continue;
            }

            if ($candidate['org_rank'] === $existingRank && $candidate['priority'] < $existingPriority) {
                $selectedByModule[$moduleCode] = $candidate;
            }
        }

        if ($selectedByModule === []) {
            return [
                'modules' => [],
                'free_module' => ReportAccess::MODULE_CORE_FREE,
            ];
        }

        $selectedRows = array_values($selectedByModule);
        usort($selectedRows, function (array $a, array $b): int {
            $left = (int) ($a['priority'] ?? 100);
            $right = (int) ($b['priority'] ?? 100);
            if ($left !== $right) {
                return $left <=> $right;
            }

            return strcmp((string) ($a['module_code'] ?? ''), (string) ($b['module_code'] ?? ''));
        });

        $modules = [];
        $freeModule = '';
        foreach ($selectedRows as $selected) {
            $moduleCode = strtolower(trim((string) ($selected['module_code'] ?? '')));
            if ($moduleCode === '') {
                continue;
            }
            $modules[] = $moduleCode;
            if ($freeModule === '' && (($selected['access_tier'] ?? 'paid') === 'free')) {
                $freeModule = $moduleCode;
            }
        }

        if ($freeModule === '') {
            $freeModule = ReportAccess::MODULE_CORE_FREE;
        }

        return [
            'modules' => ReportAccess::normalizeModules($modules),
            'free_module' => $freeModule,
        ];
    }
}
