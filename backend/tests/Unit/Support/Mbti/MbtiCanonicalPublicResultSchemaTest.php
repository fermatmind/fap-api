<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\Support\Mbti\MbtiCanonicalPublicResultSchema;
use App\Support\Mbti\MbtiPublicTypeIdentity;
use Tests\TestCase;

final class MbtiCanonicalPublicResultSchemaTest extends TestCase
{
    public function test_schema_scaffold_keeps_top_level_identity_and_profile_hero_summary_slot(): void
    {
        $payload = MbtiCanonicalPublicResultSchema::scaffoldPayload(
            MbtiPublicTypeIdentity::fromTypeCode('INFP-T'),
            'unit.test',
        );

        $this->assertSame('INFP-T', $payload['type_code']);
        $this->assertSame('INFP', $payload['base_type_code']);
        $this->assertSame('T', $payload['variant']);
        $this->assertSame(MbtiCanonicalPublicResultSchema::SCHEMA_VERSION, $payload['_meta']['schema_version']);
        $this->assertArrayHasKey('hero_summary', $payload['profile']);
        $this->assertArrayHasKey('letters_intro', $payload['sections']);
        $this->assertArrayHasKey('growth.motivators', $payload['premium_teaser']);
    }
}
