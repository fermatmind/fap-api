<?php

declare(strict_types=1);

namespace Tests\Unit\Report\Composer;

use App\Services\Report\Composer\ReportOverridesMerger;
use Tests\TestCase;

final class ReportOverridesMergerTest extends TestCase
{
    public function test_merge_respects_bucket_order_and_keeps_src_chain(): void
    {
        $merger = new ReportOverridesMerger();

        $docs = [
            [
                '__bucket' => 'highlights_legacy',
                '__src' => ['idx' => 1, 'file' => 'report_highlights_overrides.json'],
                'rules' => [
                    ['id' => 'legacy.rule.1'],
                ],
            ],
            [
                '__bucket' => 'unified',
                '__src' => ['idx' => 0, 'file' => 'report_overrides.json'],
                'rules' => [
                    ['id' => 'unified.rule.1'],
                    ['id' => 'unified.rule.2'],
                ],
            ],
        ];

        $merged = $merger->merge($docs, ['unified', 'highlights_legacy']);

        $this->assertIsArray($merged);
        $rules = is_array($merged['rules'] ?? null) ? $merged['rules'] : [];
        $this->assertSame('unified.rule.1', (string) ($rules[0]['id'] ?? ''));
        $this->assertSame('unified.rule.2', (string) ($rules[1]['id'] ?? ''));
        $this->assertSame('legacy.rule.1', (string) ($rules[2]['id'] ?? ''));

        $srcChain = is_array($merged['__src_chain'] ?? null) ? $merged['__src_chain'] : [];
        $this->assertCount(2, $srcChain);
    }

    public function test_apply_keeps_base_report_and_attaches_override_meta(): void
    {
        $merger = new ReportOverridesMerger();

        $base = ['ok' => true, 'report' => ['foo' => 'bar']];
        $doc = [
            '__src_chain' => [
                ['file' => 'report_overrides.json'],
            ],
        ];

        $out = $merger->apply($base, $doc, ['attempt_id' => 'a1']);

        $this->assertSame(true, $out['ok']);
        $this->assertSame('bar', $out['report']['foo']);
        $this->assertIsArray($out['_meta']['overrides'] ?? null);
    }
}
