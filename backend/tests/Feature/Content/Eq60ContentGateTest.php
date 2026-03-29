<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\Eq60PackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Eq60ContentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq60_lint_compile_and_questions_api_contract(): void
    {
        $this->artisan('content:lint --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);

        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $this->assertIsArray($questions);
        $this->assertSame('eq_60.questions.compiled.v1', (string) ($questions['schema'] ?? ''));
        $this->assertCount(60, (array) data_get($questions, 'questions_doc_by_locale.zh-CN.items', []));
        $this->assertCount(60, (array) data_get($questions, 'questions_doc_by_locale.en.items', []));

        $manifest = $loader->readCompiledJson('manifest.json', 'v1');
        $this->assertIsArray($manifest);
        $this->assertNotSame('', (string) ($manifest['compiled_hash'] ?? ''));

        $report = $loader->readCompiledJson('report.compiled.json', 'v1');
        $this->assertIsArray($report);
        $this->assertSame('eq_60.report.compiled.v2', (string) ($report['schema'] ?? ''));

        $sectionKeys = array_values(array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            array_filter((array) data_get($report, 'layout.sections', []), 'is_array')
        ));
        $this->assertSame([
            'disclaimer_top',
            'quality_notice',
            'global_overview',
            'self_awareness',
            'emotion_regulation',
            'empathy',
            'relationship_management',
            'cross_quadrant_insight',
            'action_plan_14d',
            'methodology',
            'disclaimer_bottom',
        ], $sectionKeys);
        $this->assertCount(102, (array) data_get($report, 'blocks', []));
        $this->assertNotEmpty((array) data_get($report, 'variables_allowlist.allowed', []));

        (new ScaleRegistrySeeder)->run();

        $zh = $this->getJson('/api/v0.3/scales/EQ_60/questions?locale=zh-CN&region=CN_MAINLAND');
        $zh->assertStatus(200);
        $zh->assertJsonPath('scale_code', 'EQ_60');
        $zh->assertJsonPath('locale', 'zh-CN');
        $this->assertCount(60, (array) data_get($zh->json(), 'questions.items', []));
        $this->assertCount(5, (array) data_get($zh->json(), 'meta.option_anchors', []));
        $this->assertSame(['SA', 'ER', 'EM', 'RM'], array_values((array) data_get($zh->json(), 'meta.dimension_codes', [])));

        $en = $this->getJson('/api/v0.3/scales/EQ_60/questions?locale=en&region=GLOBAL');
        $en->assertStatus(200);
        $en->assertJsonPath('scale_code', 'EQ_60');
        $en->assertJsonPath('locale', 'en');
        $this->assertCount(60, (array) data_get($en->json(), 'questions.items', []));
        $this->assertCount(5, (array) data_get($en->json(), 'meta.option_anchors', []));
    }
}
