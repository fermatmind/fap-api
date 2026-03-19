<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Contracts\MbtiPublicResultAuthoritySource;
use App\Services\Mbti\Adapters\MbtiReportAuthoritySourceAdapter;
use App\Services\Mbti\MbtiCanonicalPublicResultPayloadBuilder;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use App\Support\Mbti\MbtiPublicTypeIdentity;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class MbtiCanonicalPublicResultPayloadBuilderTest extends TestCase
{
    public function test_builder_creates_canonical_payload_from_report_adapter_pilot_input(): void
    {
        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $identity = MbtiPublicTypeIdentity::fromTypeCode('ENFJ-T');
        $payload = $builder->build($identity, new MbtiReportAuthoritySourceAdapter([
            'profile' => [
                'type_code' => 'ENFJ-T',
                'short_summary' => 'Pilot hero summary',
            ],
            'layers' => [
                'identity' => [
                    'title' => 'Warm guide',
                    'subtitle' => 'Empathic and future-aware',
                    'one_liner' => 'Leads with warmth, structure, and anticipation.',
                ],
            ],
            'scores_pct' => [
                'EI' => 62,
                'NS' => 74,
                'FT' => 35,
                'JP' => 68,
                'AT' => 49,
            ],
            'sections' => [
                'career' => [
                    'cards' => [
                        ['body' => 'Career pilot summary.'],
                    ],
                ],
                'growth' => [
                    'cards' => [
                        ['summary' => 'Growth pilot summary.'],
                    ],
                ],
                'relationships' => [
                    'cards' => [
                        ['title' => 'Relationship pilot summary.'],
                    ],
                ],
            ],
        ]));

        $this->assertSame('ENFJ-T', $payload['runtime_type_code']);
        $this->assertSame('ENFJ', $payload['canonical_type_code']);
        $this->assertSame('ENFJ-T', $payload['display_type']);
        $this->assertSame('T', $payload['variant_code']);
        $this->assertSame('Pilot hero summary', $payload['profile']['hero_summary']);
        $this->assertSame(
            'Leads with warmth, structure, and anticipation.',
            $this->findSection($payload['sections'], 'overview')['body_md'] ?? null
        );
        $this->assertSame(
            'Career pilot summary.',
            $this->findSection($payload['sections'], 'career.summary')['body_md'] ?? null
        );
        $this->assertSame(
            'Collaboration fit',
            $this->findSection($payload['sections'], 'career.collaboration_fit')['title'] ?? null
        );
        $this->assertSame(
            'Work environment fit',
            $this->findSection($payload['sections'], 'career.work_environment')['title'] ?? null
        );
        $this->assertSame(
            'Career next step',
            $this->findSection($payload['sections'], 'career.next_step')['title'] ?? null
        );
        $this->assertSame(
            MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER,
            $this->findSection($payload['sections'], 'growth.motivators')['render'] ?? null
        );
        $this->assertSame('SN', $payload['dimensions'][1]['id']);
        $this->assertSame('TF', $payload['dimensions'][2]['id']);
    }

    public function test_builder_rejects_base_type_only_resolution_without_silent_fallback(): void
    {
        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $identity = MbtiPublicTypeIdentity::fromTypeCode('ENFJ-T');

        $this->expectException(InvalidArgumentException::class);
        $builder->build($identity, new class implements MbtiPublicResultAuthoritySource
        {
            public function sourceKey(): string
            {
                return 'unit.base-type-only';
            }

            public function read(MbtiPublicTypeIdentity $identity): array
            {
                return [
                    'resolved_type_code' => 'ENFJ',
                    'profile' => [],
                    'sections' => [],
                    'premium_teaser' => [],
                    'seo_meta' => [],
                    'meta' => [],
                ];
            }
        });
    }

    public function test_builder_rejects_blank_resolution_without_defaulting_to_enfj_t(): void
    {
        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $identity = MbtiPublicTypeIdentity::fromTypeCode('INFP-T');

        try {
            $builder->build($identity, new class implements MbtiPublicResultAuthoritySource
            {
                public function sourceKey(): string
                {
                    return 'unit.blank-type';
                }

                public function read(MbtiPublicTypeIdentity $identity): array
                {
                    return [
                        'resolved_type_code' => '',
                        'profile' => [],
                        'sections' => [],
                        'premium_teaser' => [],
                        'seo_meta' => [],
                        'meta' => [],
                    ];
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('ENFJ-T', $e->getMessage());

            return;
        }

        $this->fail('Expected blank resolved_type_code to throw without defaulting.');
    }

    public function test_builder_rejects_premium_teaser_blocks_masquerading_as_full_sections(): void
    {
        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $identity = MbtiPublicTypeIdentity::fromTypeCode('INTJ-T');

        $this->expectException(RuntimeException::class);
        $builder->build($identity, new class implements MbtiPublicResultAuthoritySource
        {
            public function sourceKey(): string
            {
                return 'unit.identity-rewrite';
            }

            public function read(MbtiPublicTypeIdentity $identity): array
            {
                return [
                    'resolved_type_code' => 'INTJ-A',
                    'profile' => [],
                    'sections' => [],
                    'premium_teaser' => [
                        'growth.motivators' => [
                            'render_variant' => 'rich_text',
                            'title' => 'Wrong variant',
                            'teaser' => 'Should not pass.',
                        ],
                    ],
                    'seo_meta' => [],
                    'meta' => [],
                ];
            }
        });
    }

    public function test_builder_rejects_wrong_render_variant_for_premium_teaser_even_when_identity_matches(): void
    {
        $builder = new MbtiCanonicalPublicResultPayloadBuilder;
        $identity = MbtiPublicTypeIdentity::fromTypeCode('INTJ-T');

        $this->expectException(InvalidArgumentException::class);
        $builder->build($identity, new class implements MbtiPublicResultAuthoritySource
        {
            public function sourceKey(): string
            {
                return 'unit.premium-variant';
            }

            public function read(MbtiPublicTypeIdentity $identity): array
            {
                return [
                    'resolved_type_code' => 'INTJ-T',
                    'profile' => [],
                    'sections' => [],
                    'premium_teaser' => [
                        'growth.motivators' => [
                            'render_variant' => 'rich_text',
                            'title' => 'Wrong variant',
                            'teaser' => 'Should not pass.',
                        ],
                    ],
                    'seo_meta' => [],
                    'meta' => [],
                ];
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function findSection(array $sections, string $sectionKey): array
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) === $sectionKey) {
                return $section;
            }
        }

        return [];
    }
}
