<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use Tests\TestCase;

final class MbtiPublicSummaryV1BuilderTest extends TestCase
{
    public function test_scaffold_keeps_stable_shape_without_inventing_missing_identity_or_summary(): void
    {
        $builder = new MbtiPublicSummaryV1Builder;
        $payload = $builder->scaffold();

        $this->assertSame([
            'runtime_type_code' => null,
            'canonical_type_16' => null,
            'display_type' => null,
            'variant' => null,
        ], [
            'runtime_type_code' => $payload['runtime_type_code'],
            'canonical_type_16' => $payload['canonical_type_16'],
            'display_type' => $payload['display_type'],
            'variant' => $payload['variant'],
        ]);
        $this->assertSame([], $payload['dimensions']);
        $this->assertSame([], $payload['sections']);
        $this->assertSame([], data_get($payload, 'profile.keywords'));
        $this->assertSame([], data_get($payload, 'summary_card.tags'));
        $this->assertSame([], data_get($payload, 'offer_set.modules_allowed'));
        $this->assertSame([], data_get($payload, 'offer_set.modules_preview'));
        $this->assertNull(data_get($payload, 'profile.summary'));
        $this->assertNull(data_get($payload, 'summary_card.share_text'));
    }

    public function test_share_payload_builder_preserves_base_type_without_fabricating_variant_and_normalizes_axis_aliases(): void
    {
        $builder = new MbtiPublicSummaryV1Builder;
        $payload = $builder->buildFromSharePayload([
            'type_code' => ' enfj ',
            'title' => 'ENFJ - Mentor',
            'summary' => null,
            'dimensions' => [
                ['code' => 'NS', 'side' => 'N', 'pct' => 74],
                ['code' => 'FT', 'side' => 'F', 'pct' => 65],
            ],
        ], null, 'en');

        $this->assertSame('ENFJ', $payload['runtime_type_code']);
        $this->assertSame('ENFJ', $payload['canonical_type_16']);
        $this->assertSame('ENFJ', $payload['display_type']);
        $this->assertNull($payload['variant']);
        $this->assertSame(['SN', 'TF'], array_map(
            static fn (array $item): string => (string) ($item['id'] ?? ''),
            $payload['dimensions']
        ));
        $this->assertSame(26, data_get($payload, 'dimensions.0.value_pct'));
        $this->assertSame(35, data_get($payload, 'dimensions.1.value_pct'));
        $this->assertSame('overview', data_get($payload, 'sections.0.key'));
        $this->assertSame('ENFJ - Mentor', data_get($payload, 'sections.0.title'));
        $this->assertNull(data_get($payload, 'sections.0.payload'));
        $this->assertNull(data_get($payload, 'summary_card.share_text'));
    }
}
