<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Mbti\MbtiFormCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiFormCatalogTest extends TestCase
{
    public function test_it_resolves_default_mbti_144_form_truth(): void
    {
        $resolved = app(MbtiFormCatalog::class)->resolve(null);

        $this->assertSame('mbti_144', $resolved['form_code']);
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', $resolved['pack_id']);
        $this->assertSame('MBTI-CN-v0.3', $resolved['dir_version']);
        $this->assertSame('v0.3', $resolved['content_package_version']);
        $this->assertSame('mbti.cn-mainland.zh-CN.2026', $resolved['norm_version']);
        $this->assertSame('2026.01', $resolved['scoring_spec_version']);
        $this->assertSame('2026.01', $resolved['quality_version']);
        $this->assertSame(144, $resolved['question_count']);
    }

    public function test_mbti_144_mapping_is_explicit_and_does_not_follow_global_default_dir_version(): void
    {
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v999');

        $resolved = app(MbtiFormCatalog::class)->resolve('mbti_144');

        $this->assertSame('mbti_144', $resolved['form_code']);
        $this->assertSame('MBTI-CN-v0.3', $resolved['dir_version']);
        $this->assertSame(144, $resolved['question_count']);
    }

    public function test_it_resolves_mbti_93_form_truth_and_aliases(): void
    {
        $resolved = app(MbtiFormCatalog::class)->resolve('standard_93');

        $this->assertSame('mbti_93', $resolved['form_code']);
        $this->assertSame('MBTI-CN-v0.3-form-93', $resolved['dir_version']);
        $this->assertSame('v0.3-form-93', $resolved['content_package_version']);
        $this->assertSame('mbti.cn-mainland.zh-CN.2026.form93.provisional', $resolved['norm_version']);
        $this->assertSame('2026.01.mbti_93', $resolved['scoring_spec_version']);
        $this->assertSame('2026.01.mbti_93', $resolved['quality_version']);
        $this->assertSame(93, $resolved['question_count']);
    }

    public function test_it_rejects_unknown_form_code(): void
    {
        $this->expectException(ApiProblemException::class);
        $this->expectExceptionMessage('unsupported MBTI form_code');

        app(MbtiFormCatalog::class)->resolve('mbti_999');
    }

    public function test_mbti_93_source_keeps_runtime_fields_and_reindexed_order(): void
    {
        $path = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3-form-93/questions.json');
        $this->assertTrue(File::exists($path));

        $decoded = json_decode((string) File::get($path), true);
        $this->assertIsArray($decoded);

        $items = $decoded['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertCount(93, $items);

        $requiredFields = [
            'question_id',
            'order',
            'dimension',
            'text',
            'key_pole',
            'direction',
            'code',
            'ver',
            'version',
            'irt',
            'is_active',
            'options',
        ];

        foreach ($items as $index => $item) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $item, "missing {$field} at index {$index}");
            }
            $this->assertSame($index + 1, (int) ($item['order'] ?? 0));
        }
    }

    public function test_mbti_93_scoring_and_quality_truth_are_versioned(): void
    {
        $base = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3-form-93');
        $scoring = json_decode((string) File::get($base.'/scoring_spec.json'), true);
        $quality = json_decode((string) File::get($base.'/quality_checks.json'), true);

        $this->assertIsArray($scoring);
        $this->assertIsArray($quality);
        $this->assertSame('2026.01.mbti_93', $scoring['version'] ?? null);
        $this->assertSame('2026.01.mbti_93', $quality['version'] ?? null);
        $this->assertSame(2, count($scoring['reverse_pairs'] ?? []));
        $this->assertSame(
            [
                ['a' => 'MBTI-010', 'b' => 'MBTI-011'],
                ['a' => 'MBTI-090', 'b' => 'MBTI-091'],
            ],
            $scoring['reverse_pairs'] ?? []
        );
        $this->assertSame(93, data_get($quality, 'checks.0.params.min'));
    }
}
