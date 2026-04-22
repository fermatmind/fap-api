<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\Publisher\ContentProbeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ContentProbeServiceTest extends TestCase
{
    public function test_probe_uses_scale_form_and_slug_from_target(): void
    {
        Http::fake([
            'https://probe.test/api/healthz' => Http::response(['ok' => true], 200),
            'https://probe.test/api/v0.3/scales/RIASEC/questions*' => Http::response(['ok' => true], 200),
            'https://probe.test/api/v0.3/scales/lookup*' => Http::response([
                'ok' => true,
                'pack_id' => 'RIASEC',
            ], 200),
        ]);

        $result = (new ContentProbeService)->probe(
            'https://probe.test',
            'CN_MAINLAND',
            'zh-CN',
            'RIASEC',
            'RIASEC',
            'riasec_140',
            'holland-career-interest-test-riasec'
        );

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'health' => true,
            'questions' => true,
            'content_packs' => true,
        ], $result['probes']);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/v0.3/scales/RIASEC/questions')
            && (($request->data()['form_code'] ?? null) === 'riasec_140'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/v0.3/scales/lookup')
            && (($request->data()['slug'] ?? null) === 'holland-career-interest-test-riasec'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/scales/MBTI/questions'));
    }

    public function test_probe_resolves_riasec_target_from_pack_id(): void
    {
        $target = (new ContentProbeService)->resolveProbeTarget('RIASEC');

        $this->assertSame('RIASEC', $target['scale_code']);
        $this->assertSame('', $target['form_code']);
        $this->assertSame('holland-career-interest-test-riasec', $target['slug']);
    }
}
