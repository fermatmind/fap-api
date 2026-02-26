<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use App\Services\Report\ReportAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLE = 'benefit_module_rules';

    public function up(): void
    {
        $this->createOrConvergeTable();
        $this->ensureIndexes();
        $this->seedGlobalDefaults();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeTable(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('benefit_code', 64);
                $table->string('module_code', 64);
                $table->string('access_tier', 16)->default('paid');
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn(self::TABLE, 'benefit_code')) {
                $table->string('benefit_code', 64);
            }
            if (!Schema::hasColumn(self::TABLE, 'module_code')) {
                $table->string('module_code', 64);
            }
            if (!Schema::hasColumn(self::TABLE, 'access_tier')) {
                $table->string('access_tier', 16)->default('paid');
            }
            if (!Schema::hasColumn(self::TABLE, 'priority')) {
                $table->integer('priority')->default(100);
            }
            if (!Schema::hasColumn(self::TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!SchemaIndex::indexExists(self::TABLE, 'benefit_module_rules_org_benefit_module_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['org_id', 'benefit_code', 'module_code'],
                    'benefit_module_rules_org_benefit_module_unique'
                );
            });
        }

        if (!SchemaIndex::indexExists(self::TABLE, 'benefit_module_rules_org_benefit_active_priority_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['org_id', 'benefit_code', 'is_active', 'priority'],
                    'benefit_module_rules_org_benefit_active_priority_idx'
                );
            });
        }

        if (!SchemaIndex::indexExists(self::TABLE, 'benefit_module_rules_org_benefit_tier_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['org_id', 'benefit_code', 'access_tier'],
                    'benefit_module_rules_org_benefit_tier_idx'
                );
            });
        }
    }

    private function seedGlobalDefaults(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($this->defaultBenefitMappings() as $benefitCode => $mapping) {
            $freeModule = trim((string) ($mapping['free_module'] ?? ''));
            if ($freeModule !== '') {
                $rows[] = $this->seedRow(0, $benefitCode, $freeModule, 'free', 0, $now);
            }

            $priority = 10;
            $modules = is_array($mapping['modules'] ?? null) ? $mapping['modules'] : [];
            foreach ($modules as $moduleCode) {
                $normalizedModuleCode = trim((string) $moduleCode);
                if ($normalizedModuleCode === '') {
                    continue;
                }
                $rows[] = $this->seedRow(0, $benefitCode, $normalizedModuleCode, 'paid', $priority, $now);
                $priority += 10;
            }
        }

        if ($rows === []) {
            return;
        }

        DB::table(self::TABLE)->upsert(
            $rows,
            ['org_id', 'benefit_code', 'module_code'],
            ['access_tier', 'priority', 'is_active', 'updated_at']
        );
    }

    private function seedRow(
        int $orgId,
        string $benefitCode,
        string $moduleCode,
        string $accessTier,
        int $priority,
        \Illuminate\Support\Carbon $now
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'benefit_code' => strtoupper(trim($benefitCode)),
            'module_code' => strtolower(trim($moduleCode)),
            'access_tier' => strtolower(trim($accessTier)) === 'free' ? 'free' : 'paid',
            'priority' => $priority,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string,array{free_module:string,modules:list<string>}>
     */
    private function defaultBenefitMappings(): array
    {
        return [
            'MBTI_REPORT_FULL' => [
                'free_module' => ReportAccess::MODULE_CORE_FREE,
                'modules' => [
                    ReportAccess::MODULE_CORE_FULL,
                    ReportAccess::MODULE_CAREER,
                    ReportAccess::MODULE_RELATIONSHIPS,
                ],
            ],
            'MBTI_CAREER' => [
                'free_module' => ReportAccess::MODULE_CORE_FREE,
                'modules' => [ReportAccess::MODULE_CAREER],
            ],
            'MBTI_RELATIONSHIP' => [
                'free_module' => ReportAccess::MODULE_CORE_FREE,
                'modules' => [ReportAccess::MODULE_RELATIONSHIPS],
            ],
            'MBTI_RELATIONSHIPS' => [
                'free_module' => ReportAccess::MODULE_CORE_FREE,
                'modules' => [ReportAccess::MODULE_RELATIONSHIPS],
            ],
            'BIG5_FULL_REPORT' => [
                'free_module' => ReportAccess::MODULE_BIG5_CORE,
                'modules' => [
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
            ],
            'BIG5_FULL' => [
                'free_module' => ReportAccess::MODULE_BIG5_CORE,
                'modules' => [
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
            ],
            'BIG5_ACTION_PLAN' => [
                'free_module' => ReportAccess::MODULE_BIG5_CORE,
                'modules' => [ReportAccess::MODULE_BIG5_ACTION_PLAN],
            ],
            'CLINICAL_COMBO_68_PRO' => [
                'free_module' => ReportAccess::MODULE_CLINICAL_CORE,
                'modules' => [
                    ReportAccess::MODULE_CLINICAL_FULL,
                    ReportAccess::MODULE_CLINICAL_RESILIENCE,
                    ReportAccess::MODULE_CLINICAL_PERFECTIONISM,
                    ReportAccess::MODULE_CLINICAL_ACTION_PLAN,
                ],
            ],
            'SDS_20_FULL' => [
                'free_module' => ReportAccess::MODULE_SDS_CORE,
                'modules' => [
                    ReportAccess::MODULE_SDS_FULL,
                    ReportAccess::MODULE_SDS_FACTOR_DEEPDIVE,
                    ReportAccess::MODULE_SDS_ACTION_PLAN,
                ],
            ],
            'EQ_60_FULL' => [
                'free_module' => ReportAccess::MODULE_EQ_CORE,
                'modules' => [
                    ReportAccess::MODULE_EQ_FULL,
                    ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
                    ReportAccess::MODULE_EQ_GROWTH_PLAN,
                ],
            ],
        ];
    }
};
