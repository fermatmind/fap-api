<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveAuthorityV206Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06';

    public function test_raw_failures_are_preserved_and_match_the_skeptical_review(): void
    {
        $result = $this->runGate(self::DIR.'/raw-draft.json');
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
        $result = $this->runGate(self::DIR.'/final-package.json', true);

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

        $result = $this->runTemporaryCandidate($candidate);
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

        $result = $this->runTemporaryCandidate($candidate);

        $this->assertFalse($result['ok']);
        $this->assertContains('claim_unknown', collect($result['issues'])->pluck('code')->all());
    }

    public function test_near_duplicate_and_manual_review_release_state_fail_closed_without_writes(): void
    {
        $candidate = $this->readJson(self::DIR.'/final-package.json');
        $candidate['pages'][0]['sections'][1]['body'] = $candidate['pages'][0]['sections'][0]['body'].' minor suffix';
        $candidate['review_state']['reviewer'] = 'fabricated-reviewer';
        $candidate['review_state']['publish_allowed'] = true;

        $result = $this->runTemporaryCandidate($candidate);
        $codes = collect($result['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('near_duplicate_section_body', $codes);
        $this->assertContains('review_state_fail_closed', $codes);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['cms_write_attempted']);
        $this->assertFalse($result['indexability_mutation_attempted']);
        $this->assertFalse($result['search_submission_attempted']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function runTemporaryCandidate(array $candidate): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-editorial-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return $this->runGate($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function runGate(string $source, bool $expectSuccess = false): array
    {
        $repoRoot = dirname(base_path());
        $process = new Process([
            'node',
            self::DIR.'/validate-package.mjs',
            '--source',
            $source,
        ], $repoRoot);
        $process->setTimeout(15);
        $process->run();

        if ($expectSuccess) {
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        } else {
            $this->assertFalse($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(dirname(base_path()).'/'.$path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
