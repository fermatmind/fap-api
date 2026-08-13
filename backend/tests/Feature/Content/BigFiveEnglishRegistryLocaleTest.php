<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use RuntimeException;
use Tests\TestCase;

final class BigFiveEnglishRegistryLocaleTest extends TestCase
{
    public function test_english_registry_is_structurally_identical_and_keeps_stable_rule_ids(): void
    {
        $loader = app(RegistryLoader::class);
        $zh = $loader->load('zh-CN');
        $en = $loader->load('en-US');

        $this->assertSame($this->shape($zh, ['root']), $this->shape($en, ['root']));
        $this->assertSame($this->identityMap($zh), $this->identityMap($en));
        $this->assertSame('zh-CN', data_get($zh, 'manifest.locale'));
        $this->assertSame('en', data_get($en, 'manifest.locale'));
    }

    public function test_en_and_en_us_render_all_sections_without_chinese_for_low_mid_high_and_facet_anomaly_fixtures(): void
    {
        $fixture = $this->fixture();

        foreach (['en', 'en-US'] as $locale) {
            foreach (['low' => 18, 'mid' => 50, 'high' => 84] as $band => $percentile) {
                $input = $fixture;
                $input['locale'] = $locale;
                $input['fixture_id'] = "english_{$locale}_{$band}";
                foreach (['O', 'C', 'E', 'A', 'N'] as $traitCode) {
                    $input['score_vector']['domains'][$traitCode] = [
                        'percentile' => $percentile,
                        'band' => $band,
                        'gradient_id' => strtolower($traitCode).'_'.match ($band) {
                            'low' => 'g1',
                            'mid' => 'g3',
                            default => 'g5',
                        },
                    ];
                }

                $payload = app(BigFiveReportEngine::class)->generate($input);

                $this->assertSame($locale, $payload['locale']);
                $this->assertCount(8, $payload['sections']);
                $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}]/u', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            $anomalyInput = $fixture;
            $anomalyInput['locale'] = $locale;
            $anomalyPayload = app(BigFiveReportEngine::class)->generate($anomalyInput);
            $this->assertNotEmpty(data_get($anomalyPayload, 'engine_decisions.facet_anomalies'));
            $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}]/u', json_encode($anomalyPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    public function test_empty_and_chinese_locales_keep_the_same_existing_projection(): void
    {
        $input = $this->fixture();
        unset($input['locale']);
        $defaultPayload = app(BigFiveReportEngine::class)->generate($input);

        $input['locale'] = 'zh-CN';
        $explicitPayload = app(BigFiveReportEngine::class)->generate($input);

        $this->assertSame($defaultPayload, $explicitPayload);
    }

    public function test_unsupported_locale_fails_closed_with_an_explicit_unavailable_reason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Big Five report engine registry unavailable for locale: fr-FR');

        app(RegistryLoader::class)->load('fr-FR');
    }

    public function test_english_reader_copy_uses_w2_terminology_and_rejects_translation_artifacts(): void
    {
        $registry = app(RegistryLoader::class)->load('en');
        $this->assertSame([
            'O' => 'Openness to Experience',
            'C' => 'Conscientiousness',
            'E' => 'Extraversion',
            'A' => 'Agreeableness',
            'N' => 'Emotional Sensitivity',
        ], array_map(static fn (array $row): string => (string) ($row['canonical'] ?? ''), data_get($registry, 'shared.trait_labels.labels')));

        $expectedFacetLabels = [
            'O1' => 'Imagination', 'O2' => 'Aesthetic Sensitivity', 'O3' => 'Emotional Receptivity', 'O4' => 'Openness to Action', 'O5' => 'Intellectual Exploration', 'O6' => 'Value Flexibility',
            'C1' => 'Self-Efficacy', 'C2' => 'Order', 'C3' => 'Dutifulness', 'C4' => 'Achievement Striving', 'C5' => 'Delayed-Feedback Persistence', 'C6' => 'Deliberation',
            'E1' => 'Warmth', 'E2' => 'Group Participation', 'E3' => 'Assertiveness', 'E4' => 'Activity Level', 'E5' => 'Excitement Seeking', 'E6' => 'Positive Emotion',
            'A1' => 'Trust', 'A2' => 'Straightforwardness', 'A3' => 'Altruism', 'A4' => 'Conflict Mediation', 'A5' => 'Modesty', 'A6' => 'Compassion',
            'N1' => 'Anxiety Sensitivity', 'N2' => 'Anger Activation', 'N3' => 'Sustained-Stress Depletion', 'N4' => 'Social Self-Consciousness', 'N5' => 'Overload Exit Urge', 'N6' => 'Coping-Limit Vulnerability',
        ];
        $actualFacetLabels = [];
        foreach ($registry['facet_glossary'] as $pack) {
            foreach ($pack['facets'] as $facet) {
                $actualFacetLabels[(string) $facet['facet_code']] = (string) $facet['label_zh'];
            }
        }
        $this->assertSame($expectedFacetLabels, $actualFacetLabels);

        $encoded = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}]/u', $encoded);
        $this->assertDoesNotMatchRegularExpression('/battery life|propulsion|intimacy temperature|cause trouble|recovery of stocks|superimposed|on-site expression|subdivided dimension|cannot establish order|may not be able to hold on quickly/i', $encoded);
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $path = base_path('content_packs/BIG5_OCEAN/v2/registry/en/fixtures/canonical_n_slice_sensitive_independent.context.json');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function identityMap(array $registry): array
    {
        return [
            'sections' => data_get($registry, 'manifest.section_skeleton'),
            'atomic' => array_map(static fn (array $pack): array => array_keys((array) ($pack['bands'] ?? [])), $registry['atomic']),
            'modifiers' => array_map(static fn (array $pack): array => array_keys((array) ($pack['gradients'] ?? [])), $registry['modifiers']),
            'synergies' => array_keys($registry['synergies']),
            'facets' => array_map(static fn (array $pack): array => array_column((array) ($pack['facets'] ?? []), 'facet_code'), $registry['facet_glossary']),
            'precision_rules' => array_map(static fn (array $pack): array => array_column((array) ($pack['rules'] ?? []), 'rule_id'), $registry['facet_precision']),
            'action_rules' => array_map(static fn (array $pack): array => array_column((array) ($pack['rules'] ?? []), 'rule_id'), $registry['action_rules']),
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  list<string>  $ignoredKeys
     * @return array<string,mixed>
     */
    private function shape(array $value, array $ignoredKeys = []): array
    {
        $shape = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, $ignoredKeys, true)) {
                continue;
            }
            $shape[$key] = is_array($item) ? $this->shape($item, $ignoredKeys) : get_debug_type($item);
        }

        return $shape;
    }
}
