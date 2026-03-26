<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\AI\EvidenceBuilder;
use Tests\TestCase;

final class EvidenceBuilderTest extends TestCase
{
    public function test_evidence_builder_no_longer_reads_snapshot_version_metadata(): void
    {
        $attempt = new Attempt([
            'id' => 'attempt-evidence-1',
            'type_code' => 'INTJ-A',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'norm_version' => 'mbti_norm_2026_active',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => [
                'version' => 'legacy.mbti.psychometrics.v1',
            ],
        ]);

        $result = new Result([
            'type_code' => 'INTJ-A',
            'scores_pct' => ['EI' => 50],
            'axis_states' => ['EI' => 'clear'],
        ]);

        /** @var EvidenceBuilder $builder */
        $builder = app(EvidenceBuilder::class);
        $items = $builder->build($attempt, $result);

        $pointers = array_values(array_map(
            static fn (array $item): string => (string) ($item['pointer'] ?? ''),
            $items
        ));

        $this->assertContains('attempts.pack_id', $pointers);
        $this->assertContains('attempts.dir_version', $pointers);
        $this->assertContains('attempts.scoring_spec_version', $pointers);
        $this->assertNotContains('attempts.calculation_snapshot_json.version', $pointers);
    }
}
