<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\SeoContentPackage\SeoContentPackageJsonNormalizer;
use PHPUnit\Framework\TestCase;

final class SeoContentPackageJsonNormalizerTest extends TestCase
{
    public function test_normal_chinese_english_and_smart_punctuation_are_preserved(): void
    {
        $normalizer = new SeoContentPackageJsonNormalizer;

        $result = $normalizer->normalizeField('heading_sequence_json', [
            '2:职业兴趣—性格“边界”：全角，标点',
            '2:Career interests — personality “boundaries”',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([
            '2:职业兴趣—性格“边界”：全角，标点',
            '2:Career interests — personality “boundaries”',
        ], $result['value']);
    }

    public function test_malformed_utf8_is_substituted_with_sanitized_warning(): void
    {
        $normalizer = new SeoContentPackageJsonNormalizer;
        $malformed = '2:Broken '.((string) hex2bin('c328')).' heading';

        $result = $normalizer->normalizeField('heading_sequence_json', [$malformed]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('heading_sequence_json[0]', $result['warnings'][0]['field']);
        $this->assertSame('json_string_utf8_normalized', $result['warnings'][0]['code']);
        $this->assertSame(['2:Broken �( heading'], $result['value']);
    }

    public function test_non_serializable_value_fails_with_field_path(): void
    {
        $normalizer = new SeoContentPackageJsonNormalizer;
        $handle = fopen('php://memory', 'rb');
        $this->assertIsResource($handle);

        try {
            $result = $normalizer->normalizeField('heading_sequence_json', [
                ['text' => $handle],
            ]);
        } finally {
            fclose($handle);
        }

        $this->assertSame('heading_sequence_json[0].text', $result['errors'][0]['field']);
        $this->assertSame('json_non_serializable_value', $result['errors'][0]['code']);
    }

    public function test_binary_string_fails_with_field_path(): void
    {
        $normalizer = new SeoContentPackageJsonNormalizer;

        $result = $normalizer->normalizeField('metadata_json', [
            'payload' => "not-json\0binary",
        ]);

        $this->assertSame('metadata_json.payload', $result['errors'][0]['field']);
        $this->assertSame('json_binary_string_found', $result['errors'][0]['code']);
    }
}
