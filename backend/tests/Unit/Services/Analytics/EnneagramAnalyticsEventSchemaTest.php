<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\EnneagramEventSchema;
use InvalidArgumentException;
use Tests\TestCase;

final class EnneagramAnalyticsEventSchemaTest extends TestCase
{
    public function test_event_schema_catalog_includes_required_enneagram_events(): void
    {
        $schema = new EnneagramEventSchema;

        $events = collect($schema->catalog())->keyBy('event_code');

        $this->assertTrue($events->has('enneagram_result_viewed'));
        $this->assertTrue($events->has('enneagram_report_viewed'));
        $this->assertTrue($events->has('enneagram_observation_assigned'));
        $this->assertTrue($events->has('enneagram_day3_feedback_submitted'));
        $this->assertTrue($events->has('enneagram_day7_feedback_submitted'));
        $this->assertTrue($events->has('enneagram_pdf_downloaded'));
        $this->assertContains('registry_release_hash', (array) data_get($events->get('enneagram_result_viewed'), 'dimensions', []));
        $this->assertContains('observation_status', (array) data_get($events->get('enneagram_day7_feedback_submitted'), 'dimensions', []));
    }

    public function test_valid_enneagram_event_meta_passes_validation(): void
    {
        $schema = new EnneagramEventSchema;

        $validated = $schema->validate('enneagram_day7_feedback_submitted', $this->validMeta([
            'observation_status' => 'user_confirmed',
            'suggested_next_action' => 'no_action',
        ]));

        $this->assertSame('ENNEAGRAM', $validated['scale_code']);
        $this->assertSame('user_confirmed', $validated['observation_status']);
    }

    public function test_missing_enneagram_dimension_throws_exception(): void
    {
        $schema = new EnneagramEventSchema;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing ENNEAGRAM event meta key: registry_release_hash');

        $meta = $this->validMeta();
        unset($meta['registry_release_hash']);

        $schema->validate('enneagram_result_viewed', $meta);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validMeta(array $overrides = []): array
    {
        return array_merge([
            'scale_code' => 'ENNEAGRAM',
            'form_code' => 'enneagram_likert_105',
            'form_kind' => 'likert',
            'score_space_version' => 'e105_likert_space.v1',
            'interpretation_scope' => 'close_call',
            'confidence_level' => 'medium',
            'close_call_pair' => ['pair_key' => '4_5'],
            'primary_candidate' => '4',
            'second_candidate' => '5',
            'third_candidate' => '9',
            'compare_compatibility_group' => 'enneagram.e105',
            'cross_form_comparable' => false,
            'interpretation_context_id' => 'ctx_demo',
            'content_release_hash' => 'sha256:content_demo',
            'registry_release_hash' => 'sha256:registry_demo',
            'projection_version' => 'enneagram_projection.v2',
            'report_schema_version' => 'enneagram.report.v2',
            'close_call_rule_version' => 'close_call_rule.v1',
            'confidence_policy_version' => 'enneagram_confidence_policy.v1',
            'quality_policy_version' => 'enneagram_quality_policy.v1',
            'observation_status' => null,
            'suggested_next_action' => null,
        ], $overrides);
    }
}
