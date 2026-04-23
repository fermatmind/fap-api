<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3\Concerns;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;

trait BuildsBigFiveReportEngineBridgeFixture
{
    /**
     * @return array{attempt:Attempt,result:Result,attempt_id:string,anon_id:string,token:string,legacy_sections:list<array<string,mixed>>}
     */
    private function createCanonicalBigFiveBridgeFixture(string $anonId = 'anon_big5_bridge'): array
    {
        $fixture = $this->canonicalContextFixture();
        $attemptId = (string) Str::uuid();
        $token = $this->issueBridgeAnonToken($anonId);

        $attempt = Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 90,
            'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
        ]);

        $scoreResult = $this->scoreResultFromContextFixture($fixture);
        $result = Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL',
            'scale_version' => 'v0.3',
            'type_code' => 'BIG5',
            'scores_json' => ['domains_mean' => []],
            'scores_pct' => data_get($scoreResult, 'scores_0_100.domains_percentile', []),
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => $scoreResult,
                'breakdown_json' => ['score_result' => $scoreResult],
                'axis_scores_json' => ['score_result' => $scoreResult],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $legacySections = $this->legacySections();
        $this->fakeReportGate($legacySections);

        return [
            'attempt' => $attempt,
            'result' => $result,
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'token' => $token,
            'legacy_sections' => $legacySections,
        ];
    }

    /**
     * @return array{attempt_id:string,anon_id:string,token:string,legacy_sections:list<array<string,mixed>>}
     */
    private function createNonBigFiveBridgeFixture(string $scaleCode = 'SDS_20', string $anonId = 'anon_non_big5_bridge'): array
    {
        $attemptId = (string) Str::uuid();
        $token = $this->issueBridgeAnonToken($anonId);

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 20,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(4),
            'submitted_at' => now()->subMinute(),
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'test_spec_v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['score' => 42],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => ['normed_json' => ['score' => 42]],
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'scoring_spec_version' => 'test_spec_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $legacySections = $this->legacySections();
        $this->fakeReportGate($legacySections, $scaleCode);

        return [
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'token' => $token,
            'legacy_sections' => $legacySections,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalContextFixture(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('content_packs/BIG5_OCEAN/v2/registry/fixtures/canonical_n_slice_sensitive_independent.context.json')), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function scoreResultFromContextFixture(array $context): array
    {
        $domains = [];
        foreach ((array) data_get($context, 'score_vector.domains', []) as $traitCode => $domain) {
            if (is_array($domain)) {
                $domains[(string) $traitCode] = (int) ($domain['percentile'] ?? 0);
            }
        }

        $facets = [];
        foreach ((array) data_get($context, 'score_vector.facets', []) as $facetCode => $facet) {
            if (is_array($facet)) {
                $facets[(string) $facetCode] = (int) ($facet['percentile'] ?? 0);
            }
        }

        return [
            'scores_0_100' => [
                'domains_percentile' => $domains,
                'facets_percentile' => $facets,
            ],
            'facts' => [
                'domain_buckets' => array_map(fn (int $percentile): string => $this->bucketFor($percentile), $domains),
                'facet_buckets' => array_map(fn (int $percentile): string => $this->bucketFor($percentile), $facets),
            ],
            'quality' => [
                'level' => (string) data_get($context, 'quality.level', 'D'),
            ],
            'norms' => [
                'status' => (string) data_get($context, 'quality.norms_status', 'CALIBRATED'),
                'group_id' => 'test.big5.bridge',
            ],
            'engine_version' => 'bridge_fixture_v1',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $legacySections
     */
    private function fakeReportGate(array $legacySections, string $scaleCode = 'BIG5_OCEAN'): void
    {
        $this->mock(ReportGatekeeper::class, function (MockInterface $mock) use ($legacySections, $scaleCode): void {
            $mock->shouldReceive('resolve')
                ->andReturn([
                    'ok' => true,
                    'locked' => false,
                    'access_level' => 'full',
                    'variant' => 'full',
                    'offers' => [],
                    'report' => [
                        'schema_version' => strtolower($scaleCode).'.report.v1',
                        'scale_code' => $scaleCode,
                        'sections' => $legacySections,
                        '_meta' => [],
                    ],
                ]);
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function legacySections(): array
    {
        return [
            ['key' => 'traits.overview', 'title' => '五维总览', 'blocks' => [['kind' => 'paragraph', 'body' => 'legacy overview']]],
            ['key' => 'traits.why_this_profile', 'title' => '为什么是这个画像', 'blocks' => [['kind' => 'paragraph', 'body' => 'legacy portrait']]],
            ['key' => 'growth.next_actions', 'title' => '下一步行动', 'blocks' => [['kind' => 'paragraph', 'body' => 'legacy action']]],
        ];
    }

    private function issueBridgeAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function bucketFor(int $percentile): string
    {
        return match (true) {
            $percentile <= 25 => 'low',
            $percentile <= 39 => 'low_mid',
            $percentile <= 59 => 'mid',
            $percentile <= 79 => 'high_mid',
            default => 'high',
        };
    }
}
