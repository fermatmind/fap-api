<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoIssuePriorityScorer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class SeoIssuePriorityScorerTest extends TestCase
{
    public function test_confidence_and_effort_contract_is_deterministic_and_explainable(): void
    {
        $scorer = new SeoIssuePriorityScorer;
        $cluster = ['severity' => 'high', 'affected_url_count' => 2, 'template' => 'article_detail'];

        $deterministic = $scorer->score($cluster, $this->members([
            ['source_system' => 'cms_rule', 'metadata_json' => ['root_cause' => 'missing_title', 'template' => 'article_detail', 'field' => 'title', 'autofixable' => true]],
        ]), [], false);
        $inferred = $scorer->score($cluster, $this->members([
            ['source_system' => 'technical_rule', 'metadata_json' => []],
        ]), [], false);
        $gsc = $scorer->score($cluster, $this->members([
            ['source_system' => 'gsc', 'canonical_url_hash' => 'hash', 'metadata_json' => []],
        ]), ['hash' => ['clicks' => 5, 'impressions' => 50]], true);
        $blockedGsc = $scorer->score($cluster, $this->members([
            ['source_system' => 'gsc', 'canonical_url_hash' => 'hash', 'metadata_json' => []],
        ]), [], false);

        $this->assertSame(1.0, data_get($deterministic, 'confidence.value'));
        $this->assertSame('deterministic_cms_or_technical_rule', data_get($deterministic, 'confidence.basis'));
        $this->assertSame(1, data_get($deterministic, 'effort.value'));
        $this->assertSame('batch_automatic_fix', data_get($deterministic, 'effort.basis'));
        $this->assertSame(0.75, data_get($inferred, 'confidence.value'));
        $this->assertSame('template_inference', data_get($inferred, 'confidence.basis'));
        $this->assertSame(0.9, data_get($gsc, 'confidence.value'));
        $this->assertSame('gsc_quality_gate_passed', data_get($gsc, 'confidence.basis'));
        $this->assertSame('impact_x_confidence_div_effort', data_get($gsc, 'formula'));
        $this->assertSame(0.0, data_get($blockedGsc, 'confidence.value'));
        $this->assertFalse(data_get($blockedGsc, 'ranking_eligible'));
        $this->assertSame(0.0, data_get($blockedGsc, 'score'));
    }

    public function test_effort_scale_covers_manual_template_engineering_and_external_work(): void
    {
        $scorer = new SeoIssuePriorityScorer;
        $base = ['severity' => 'medium', 'affected_url_count' => 1, 'template' => 'unknown'];

        $manual = $scorer->score($base, $this->members([['source_system' => 'cms_rule', 'metadata_json' => []]]), [], false);
        $template = $scorer->score([...$base, 'template' => 'career_detail'], $this->members([['source_system' => 'cms_rule', 'metadata_json' => []]]), [], false);
        $engineering = $scorer->score($base, $this->members([['source_system' => 'technical_rule', 'metadata_json' => ['engineering_fix' => true]]]), [], false);
        $external = $scorer->score($base, $this->members([['source_system' => 'provider', 'metadata_json' => ['external_dependency' => true]]]), [], false);

        $this->assertSame([2, 3, 4, 5], [
            data_get($manual, 'effort.value'),
            data_get($template, 'effort.value'),
            data_get($engineering, 'effort.value'),
            data_get($external, 'effort.value'),
        ]);
    }

    /** @param list<array<string,mixed>> $rows @return Collection<int,object> */
    private function members(array $rows): Collection
    {
        return collect($rows)->map(static function (array $row): object {
            $row['metadata_json'] = json_encode($row['metadata_json'] ?? [], JSON_THROW_ON_ERROR);

            return (object) $row;
        });
    }
}
