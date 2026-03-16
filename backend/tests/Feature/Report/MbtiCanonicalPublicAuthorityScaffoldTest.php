<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Mbti\Adapters\MbtiReportAuthoritySourceAdapter;
use App\Services\Mbti\MbtiCanonicalPublicResultPayloadBuilder;
use App\Support\Mbti\MbtiPublicTypeIdentity;
use Tests\TestCase;

final class MbtiCanonicalPublicAuthorityScaffoldTest extends TestCase
{
    public function test_pilot_builder_can_read_current_report_authority_assets_without_switching_public_routes(): void
    {
        $identityLayers = json_decode((string) file_get_contents(
            base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/identity_layers.json')
        ), true);

        $layer = $identityLayers['items']['ENFJ-T'] ?? null;
        $this->assertIsArray($layer);

        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $payload = $builder->build(
            MbtiPublicTypeIdentity::fromTypeCode('ENFJ-T'),
            new MbtiReportAuthoritySourceAdapter([
                'profile' => [
                    'type_code' => 'ENFJ-T',
                    'short_summary' => 'Pilot report authority summary',
                ],
                'layers' => [
                    'identity' => $layer,
                ],
                'scores_pct' => [
                    'EI' => 64,
                    'NS' => 71,
                    'FT' => 41,
                    'JP' => 66,
                    'AT' => 57,
                ],
                'sections' => [],
            ]),
        );

        $this->assertSame('ENFJ-T', $payload['runtime_type_code']);
        $this->assertSame('ENFJ', $payload['canonical_type_code']);
        $this->assertSame('ENFJ-T', $payload['display_type']);
        $this->assertSame('T', $payload['variant_code']);
        $this->assertSame('Pilot report authority summary', $payload['profile']['hero_summary']);
        $this->assertSame((string) $layer['one_liner'], $payload['sections'][0]['body_md']);
        $this->assertSame('report.v0_3.pilot', $payload['_meta']['authority_source']);
    }
}
