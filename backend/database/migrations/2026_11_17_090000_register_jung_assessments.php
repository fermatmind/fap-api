<?php

declare(strict_types=1);

use App\Services\Assessment\Jung\JungBank;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $writer = app(ScaleRegistryWriter::class);
            foreach ([
                [JungBank::ARCHETYPES, 'jung-archetypes', '荣格十二原型测评', 'Jungian 12 Archetypes Assessment',
                    '通过48道情境自评，探索十二种内在动机与角色表达偏好。',
                    'Explore twelve motives and preferred ways of taking on roles through 48 scenario ratings.'],
                [JungBank::FUNCTIONS, 'jung-cognitive-functions', '荣格八维认知功能测评', 'Jungian 8 Cognitive Functions Assessment',
                    '通过64道日常情境题，查看八维分布、四组功能轴和类型候选。',
                    'Use 64 everyday scenarios to explore eight function scores, four axes, and possible type matches.'],
            ] as [$code, $slug, $zh, $en, $zhDescription, $enDescription]) {
                $bank = new JungBank($code);
                $catalog = ['questions_count' => count($bank->data['items'])];
                $writer->upsertScale([
                    'org_id' => 0,
                    'code' => $code,
                    'primary_slug' => $slug,
                    'slugs_json' => [$slug],
                    'driver_type' => 'jung_likert',
                    'assessment_driver' => 'jung_likert',
                    'default_pack_id' => $code.'.v1',
                    'default_dir_version' => 'v1',
                    'default_region' => 'GLOBAL',
                    'default_locale' => 'zh-CN',
                    'capabilities_json' => ['paywall_mode' => 'free_only'],
                    'is_public' => true,
                    'is_active' => true,
                    'is_indexable' => false,
                    'content_i18n_json' => [
                        'zh' => ['title' => $zh, 'description' => $zhDescription, 'catalog' => $catalog],
                        'en' => ['title' => $en, 'description' => $enDescription, 'catalog' => $catalog],
                    ],
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep identities and their attempts available to newer code during rollback.
    }
};
