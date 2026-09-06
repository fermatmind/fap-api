<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORG_ID = 0;

    private const LOCALE = 'zh-CN';

    private const SURFACE_KEY = 'test_detail_big_five_personality_test_ocean_model';

    public function up(): void
    {
        if (! Schema::hasTable('landing_surfaces')) {
            return;
        }

        DB::transaction(function (): void {
            $existing = DB::table('landing_surfaces')
                ->where('org_id', self::ORG_ID)
                ->where('surface_key', self::SURFACE_KEY)
                ->where('locale', self::LOCALE)
                ->lockForUpdate()
                ->first();

            $desired = $this->desired();
            if ($existing === null) {
                $insert = $desired;
                $insert['payload_json'] = json_encode(
                    $desired['payload_json'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                DB::table('landing_surfaces')->insert([
                    ...$insert,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $current = [
                'org_id' => (int) $existing->org_id,
                'surface_key' => (string) $existing->surface_key,
                'locale' => (string) $existing->locale,
                'title' => $existing->title,
                'description' => $existing->description,
                'schema_version' => (string) $existing->schema_version,
                'payload_json' => json_decode((string) ($existing->payload_json ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
                'status' => (string) $existing->status,
                'is_public' => (bool) $existing->is_public,
                'is_indexable' => (bool) $existing->is_indexable,
                'published_at' => $existing->published_at,
                'scheduled_at' => $existing->scheduled_at,
            ];

            if ($current != $desired) {
                throw new RuntimeException('Big Five Chinese landing surface differs from the reviewed frozen authority; refusing to overwrite.');
            }
        });
    }

    public function down(): void
    {
        // Published content survives code rollback. Corrections require a new fail-closed migration.
    }

    /** @return array<string, mixed> */
    private function desired(): array
    {
        return [
            'org_id' => self::ORG_ID,
            'surface_key' => self::SURFACE_KEY,
            'locale' => self::LOCALE,
            'title' => '免费大五人格测试（OCEAN）',
            'description' => '约15分钟完成120题大五人格测试，查看开放性、尽责性、外倾性、宜人性与神经质结果。用于自我理解，不作诊断或筛选。',
            'schema_version' => 'seo-top100-frozen.v1',
            'payload_json' => [
                'seo_title' => '免费大五人格测试（OCEAN）：120题完整结果 | FermatMind',
                'seo_description' => '约15分钟完成120题大五人格测试，查看开放性、尽责性、外倾性、宜人性与神经质结果。用于自我理解，不作诊断或筛选。',
                'h1_or_hero_title' => '免费大五人格测试（OCEAN）',
                'intro' => '用约15分钟完成120题，获得开放性、尽责性、外倾性、宜人性与神经质五个维度的结果。结果是自我观察线索，不是诊断、能力判断或职业保证。',
                'hero_copy' => '用约15分钟完成120题，获得开放性、尽责性、外倾性、宜人性与神经质五个维度的结果。结果是自我观察线索，不是诊断、能力判断或职业保证。',
                'internal_links' => [],
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => null,
            'scheduled_at' => null,
        ];
    }
};
