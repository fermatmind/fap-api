<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\BigFive\AuthorityV2\EditorialGate\BigFiveEditorialGate;
use Tests\TestCase;

final class BigFiveAuthorityV206Test extends TestCase
{
    private const DIR = '../generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06';

    public function test_raw_failures_are_preserved_and_match_the_skeptical_review(): void
    {
        $result = $this->gate()->validate($this->readJson(self::DIR.'/raw-draft.json'), $this->sourceLedger());
        $review = $this->readJson(self::DIR.'/skeptical-review.json');

        $this->assertFalse($result['ok']);
        $failedGates = collect($result['gates'])->filter(fn (array $gate): bool => $gate['status'] === 'fail')->keys()->all();
        foreach ($review['expected_failed_gates'] as $gate) {
            $this->assertContains($gate, $failedGates);
        }
        $this->assertTrue($review['raw_failures_preserved']);
        $this->assertFalse($review['automatic_repair_hides_raw_failures']);
        $this->assertFalse($review['ai_detector_used']);
    }

    public function test_final_candidate_passes_automated_gates_but_not_human_or_release_gates(): void
    {
        $result = $this->gate()->validate($this->readJson(self::DIR.'/final-package.json'), $this->sourceLedger());

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $result['issues']);
        $this->assertTrue($result['automated_gate_passed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_allowed']);
        $this->assertFalse($result['schema_eligible']);
        $this->assertFalse($result['ai_detector_used']);
        $this->assertSame(['pass'], array_values(array_unique(array_column($result['gates'], 'status'))));
    }

    public function test_private_route_identifier_and_cross_framework_leakage_fail_closed(): void
    {
        $candidate = $this->readJson(self::DIR.'/final-package.json');
        $candidate['pages'][0]['sections'][0]['body'] .= ' /'.'orders/example?'.'session_id=demo';
        $candidate['pages'][1]['sections'][0]['body'] .= ' MB'.'TI';

        $result = $this->gate()->validate($candidate, $this->sourceLedger());
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('private_route_detected', $codes);
        $this->assertContains('private_identifier_detected', $codes);
        $this->assertContains('cross_framework_leakage', $codes);
    }

    public function test_unknown_or_unbacked_claims_fail_source_coverage(): void
    {
        $candidate = $this->readJson(self::DIR.'/final-package.json');
        $candidate['pages'][0]['claims'][0] = [
            'claim_id' => 'claim.unknown',
            'source_ids' => ['competitor.big-five-public-structure-benchmark-2026-07-13'],
        ];

        $result = $this->gate()->validate($candidate, $this->sourceLedger());

        $this->assertFalse($result['ok']);
        $this->assertContains('claim_unknown', collect($result['issues'])->pluck('code')->all());
    }

    public function test_command_exposes_pass_and_fail_without_writes(): void
    {
        $this->artisan('personality-big-five:authority-v2-editorial-gate', [
            '--source' => self::DIR.'/final-package.json',
            '--json' => true,
        ])->assertSuccessful()->expectsOutputToContain('"writes_committed": false');

        $this->artisan('personality-big-five:authority-v2-editorial-gate', [
            '--source' => self::DIR.'/raw-draft.json',
            '--json' => true,
        ])->assertFailed()->expectsOutputToContain('"status": "fail"');
    }

    private function gate(): BigFiveEditorialGate
    {
        return app(BigFiveEditorialGate::class);
    }

    /** @return array<string,mixed> */
    private function sourceLedger(): array
    {
        return $this->readJson('../generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json');
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(base_path($path)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
