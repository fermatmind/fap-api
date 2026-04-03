<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive;

use App\Exceptions\Api\ApiProblemException;
use App\Services\BigFive\BigFiveFormCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveFormCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_default_big5_120_form_truth(): void
    {
        $resolved = app(BigFiveFormCatalog::class)->resolve(null);

        $this->assertSame('big5_120', $resolved['form_code']);
        $this->assertSame('BIG5_OCEAN', $resolved['pack_id']);
        $this->assertSame('v1', $resolved['dir_version']);
        $this->assertSame('v1', $resolved['content_package_version']);
        $this->assertSame('big5_spec_2026Q1_v1', $resolved['scoring_spec_version']);
        $this->assertSame('big5_spec_2026Q1_v1', $resolved['quality_version']);
        $this->assertSame('2026Q1_v1', $resolved['norm_version']);
        $this->assertSame(120, $resolved['question_count']);
    }

    public function test_it_resolves_big5_90_form_truth_and_aliases(): void
    {
        $resolved = app(BigFiveFormCatalog::class)->resolve('90');

        $this->assertSame('big5_90', $resolved['form_code']);
        $this->assertSame('BIG5_OCEAN', $resolved['pack_id']);
        $this->assertSame('v1-form-90', $resolved['dir_version']);
        $this->assertSame('v1-form-90', $resolved['content_package_version']);
        $this->assertSame('big5_spec_2026Q2_form90_v1', $resolved['scoring_spec_version']);
        $this->assertSame('big5_quality_2026Q2_form90_v1', $resolved['quality_version']);
        $this->assertSame('big5.norms.2026Q2.form90.v1', $resolved['norm_version']);
        $this->assertSame(90, $resolved['question_count']);
    }

    public function test_it_rejects_unknown_big5_form_code(): void
    {
        $this->expectException(ApiProblemException::class);
        $this->expectExceptionMessage('unsupported BIG5 form_code');

        app(BigFiveFormCatalog::class)->resolve('big5_unknown');
    }

    public function test_big5_90_source_keeps_runtime_fields_and_reindexed_order(): void
    {
        $base = base_path('content_packs/BIG5_OCEAN/v1-form-90/compiled');
        $this->assertTrue(File::exists($base.'/questions.compiled.json'));
        $this->assertTrue(File::exists($base.'/questions.min.compiled.json'));
        $this->assertTrue(File::exists($base.'/policy.compiled.json'));
        $this->assertTrue(File::exists($base.'/norms.compiled.json'));
        $this->assertTrue(File::exists($base.'/manifest.json'));

        $full = json_decode((string) File::get($base.'/questions.compiled.json'), true);
        $this->assertIsArray($full);

        $items = data_get($full, 'questions_doc.items', []);
        $this->assertIsArray($items);
        $this->assertCount(90, $items);

        foreach ($items as $index => $item) {
            $this->assertIsArray($item);
            $this->assertArrayHasKey('question_id', $item);
            $this->assertArrayHasKey('order', $item);
            $this->assertArrayHasKey('dimension', $item);
            $this->assertArrayHasKey('facet_code', $item);
            $this->assertArrayHasKey('direction', $item);
            $this->assertArrayHasKey('options', $item);
            $this->assertSame($index + 1, (int) ($item['order'] ?? 0));
        }

        $min = json_decode((string) File::get($base.'/questions.min.compiled.json'), true);
        $this->assertIsArray($min);
        $questionIndex = data_get($min, 'question_index', []);
        $this->assertIsArray($questionIndex);
        $this->assertCount(90, $questionIndex);
        $keys = array_map('intval', array_keys($questionIndex));
        $this->assertSame(range(1, 90), $keys);
    }
}
