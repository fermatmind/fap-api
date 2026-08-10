<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REGISTRY_TABLES = [
        'scales_registry',
        'scales_registry_v2',
    ];

    private const PRODUCT_TRUTH = [
        'MBTI' => [
            'default_form_code' => 'mbti_144',
            'forms' => ['mbti_144', 'mbti_93'],
            'questions' => 144,
            'minutes' => 15,
        ],
        'BIG5_OCEAN' => [
            'default_form_code' => 'big5_120',
            'forms' => ['big5_120', 'big5_90'],
            'questions' => 120,
            'minutes' => 15,
        ],
        'ENNEAGRAM' => [
            'default_form_code' => 'enneagram_likert_105',
            'forms' => ['enneagram_likert_105', 'enneagram_forced_choice_144'],
            'questions' => 105,
            'minutes' => 12,
        ],
        'RIASEC' => [
            'default_form_code' => 'riasec_60',
            'forms' => ['riasec_60', 'riasec_140'],
            'questions' => 60,
            'minutes' => 8,
        ],
        'IQ_RAVEN' => [
            'default_form_code' => 'IQ_OWNER_ORIGINAL_30',
            'forms' => ['IQ_OWNER_ORIGINAL_30'],
            'questions' => 30,
            'minutes' => 20,
        ],
        'EQ_60' => [
            'default_form_code' => 'eq_60',
            'forms' => ['eq_60'],
            'questions' => 60,
            'minutes' => 10,
        ],
    ];

    private const REPORT_UNLOCK_SCALE_CODES = [
        'MBTI',
        'BIG5_OCEAN',
        'EQ_60',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->retireAttemptReportUnlockSkus();

            foreach (self::REGISTRY_TABLES as $table) {
                $this->convergeRegistryTable($table);
            }
        });
    }

    public function down(): void
    {
        // Forward-only: do not reactivate historical report-unlock commerce.
    }

    private function retireAttemptReportUnlockSkus(): void
    {
        if (! Schema::hasTable('skus')) {
            return;
        }

        $rows = DB::table('skus')
            ->whereIn('scale_code', self::REPORT_UNLOCK_SCALE_CODES)
            ->where('kind', 'report_unlock')
            ->where('scope', 'attempt')
            ->get(['sku', 'meta_json']);

        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row->meta_json ?? null);
            $metadata['deprecated'] = true;
            $metadata['historical_only'] = true;
            $metadata['offer'] = false;
            unset($metadata['effective_default'], $metadata['offer_code']);

            DB::table('skus')
                ->where('sku', (string) ($row->sku ?? ''))
                ->update([
                    'is_active' => false,
                    'meta_json' => $this->encodeJson($metadata),
                    'updated_at' => now(),
                ]);
        }
    }

    private function convergeRegistryTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = ['org_id', 'code', 'capabilities_json', 'view_policy_json', 'commercial_json'];
        if (Schema::hasColumn($table, 'content_i18n_json')) {
            $columns[] = 'content_i18n_json';
        }

        $rows = DB::table($table)
            ->where('org_id', 0)
            ->whereIn('code', array_keys(self::PRODUCT_TRUTH))
            ->get($columns);

        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row->code ?? '')));
            $truth = self::PRODUCT_TRUTH[$code] ?? null;
            if (! is_array($truth)) {
                continue;
            }

            $capabilities = $this->decodeJson($row->capabilities_json ?? null);
            $capabilities['paywall_mode'] = 'free_only';
            $capabilities['forms'] = $truth['forms'];
            $capabilities['default_form_code'] = $truth['default_form_code'];

            $viewPolicy = $this->decodeJson($row->view_policy_json ?? null);
            $viewPolicy['blur_others'] = false;
            $viewPolicy['teaser_percent'] = 0.0;
            $viewPolicy['upgrade_sku'] = null;

            $updates = [
                'capabilities_json' => $this->encodeJson($capabilities),
                'view_policy_json' => $this->encodeJson($viewPolicy),
                'commercial_json' => $this->encodeJson($this->freeCommercialContract()),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($table, 'content_i18n_json')) {
                $content = $this->decodeJson($row->content_i18n_json ?? null);
                $updates['content_i18n_json'] = $this->encodeJson(
                    $this->convergeCatalogContent($content, $code, $truth)
                );
            }

            DB::table($table)
                ->where('org_id', 0)
                ->where('code', $code)
                ->update($updates);
        }
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  array{default_form_code:string,forms:list<string>,questions:int,minutes:int}  $truth
     * @return array<string,mixed>
     */
    private function convergeCatalogContent(array $content, string $code, array $truth): array
    {
        $claimCopy = $this->claimCopy($code);

        foreach (['en', 'zh'] as $language) {
            $localized = is_array($content[$language] ?? null) ? $content[$language] : [];
            $catalog = is_array($localized['catalog'] ?? null) ? $localized['catalog'] : [];
            $catalog['questions_count'] = $truth['questions'];
            $catalog['time_minutes'] = $truth['minutes'];
            $localized['catalog'] = $catalog;

            $highlight = is_array($localized['highlight'] ?? null) ? $localized['highlight'] : [];
            $highlight['rating'] = 0;
            if (isset($claimCopy[$language])) {
                $highlight['excerpt'] = $claimCopy[$language]['excerpt'];
                $highlight['seo_copy'] = $claimCopy[$language]['seo_copy'];
            }
            $localized['highlight'] = $highlight;
            $content[$language] = $localized;
        }

        return $content;
    }

    /**
     * @return array<string,array{excerpt:string,seo_copy:string}>
     */
    private function claimCopy(string $code): array
    {
        return match ($code) {
            'MBTI' => [
                'en' => [
                    'excerpt' => 'Describe your E/I, S/N, T/F, and J/P preference patterns as a structured reference for communication, self-reflection, and career exploration.',
                    'seo_copy' => 'This MBTI assessment describes preference patterns for self-reflection, communication, and career exploration. It does not determine hiring, career outcomes, ability, health, or future results.',
                ],
                'zh' => [
                    'excerpt' => '了解你的 E/I、S/N、T/F、J/P 偏好模式，作为沟通、自我观察与职业探索的结构化参考。',
                    'seo_copy' => '该 MBTI 测评用于描述偏好模式，供自我观察、沟通与职业探索参考；不用于决定录用、职业结果、能力、健康或未来表现。',
                ],
            ],
            'BIG5_OCEAN' => [
                'en' => [
                    'excerpt' => 'Explore your current pattern across five continuous trait dimensions and use the scores as a reference for self-observation and growth planning.',
                    'seo_copy' => 'This report describes five continuous trait dimensions for self-observation. FermatMind does not currently publish specific reliability, validity, norm, or percentile evidence for this assessment.',
                ],
                'zh' => [
                    'excerpt' => '了解你在五个连续特质维度上的当前分布，并将分数作为自我观察与成长规划的参考。',
                    'seo_copy' => '该报告描述五个连续特质维度，供自我观察参考；费马测试当前未公开本测评的具体信度、效度、常模或百分位证据。',
                ],
            ],
            'IQ_RAVEN' => [
                'en' => [
                    'excerpt' => 'Work through matrix-reasoning and pattern-analysis questions to observe your current problem-solving approach. The result is not a fixed judgment of intelligence or potential.',
                    'seo_copy' => 'This IQ assessment focuses on matrix reasoning and pattern analysis for self-evaluation. It does not determine fixed intelligence, potential, education, or employment outcomes.',
                ],
                'zh' => [
                    'excerpt' => '通过矩阵推理与模式分析题观察你当前的问题解决方式；结果不是对智力或潜能的固定判断。',
                    'seo_copy' => '该 IQ 测试聚焦矩阵推理与模式分析，用于自评参考；不用于决定固定智力、潜能、升学或录用结果。',
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function freeCommercialContract(): array
    {
        return [
            'price_tier' => 'FREE',
            'report_benefit_code' => null,
            'credit_benefit_code' => null,
            'report_unlock_sku' => null,
            'upgrade_sku' => null,
            'upgrade_sku_anchor' => null,
            'offers' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
