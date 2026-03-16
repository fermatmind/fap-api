<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\Support\Mbti\MbtiPublicTypeIdentity;
use InvalidArgumentException;
use Tests\TestCase;

final class MbtiPublicTypeIdentityTest extends TestCase
{
    public function test_from_type_code_preserves_five_character_runtime_identity(): void
    {
        $identity = MbtiPublicTypeIdentity::fromTypeCode(' enfj-t ');

        $this->assertSame('ENFJ-T', $identity->typeCode);
        $this->assertSame('ENFJ', $identity->baseTypeCode);
        $this->assertSame('T', $identity->variant);
        $this->assertSame([
            'type_code' => 'ENFJ-T',
            'base_type_code' => 'ENFJ',
            'variant' => 'T',
        ], $identity->toArray());
    }

    public function test_try_from_type_code_returns_null_for_blank_input_without_defaulting(): void
    {
        $this->assertNull(MbtiPublicTypeIdentity::tryFromTypeCode(null));
        $this->assertNull(MbtiPublicTypeIdentity::tryFromTypeCode('  '));
    }

    public function test_from_type_code_rejects_base_type_and_invalid_variant_inputs(): void
    {
        foreach (['ENFJ', 'ENFJ-X', '', 'ENFJT'] as $invalidTypeCode) {
            try {
                MbtiPublicTypeIdentity::fromTypeCode($invalidTypeCode);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('type_code', $e->getMessage());

                continue;
            }

            $this->fail(sprintf('Expected invalid MBTI type_code [%s] to throw.', $invalidTypeCode));
        }
    }
}
