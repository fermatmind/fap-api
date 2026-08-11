<?php

declare(strict_types=1);

namespace Tests\Sre;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class Career1046CurrentStateFreezeContractTest extends TestCase
{
    public function test_frozen_contract_matches_exact_zero_write_evidence(): void
    {
        $contract = $this->contract();
        $before = hash_file('sha256', $this->contractPath());

        $process = $this->verify($this->contractPath());

        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('PASS_FROZEN_ZERO_WRITE_CONTRACT', $result['status']);
        self::assertSame(1016, $result['receipt_coverage']);
        self::assertSame(1016, $result['database_latest_index_missing']);
        self::assertSame(0, $result['writes']);
        self::assertFalse($result['production_apply_allowed']);
        self::assertSame($before, hash_file('sha256', $this->contractPath()));

        self::assertSame(30, $contract['payload']['target_authority']['baseline_count']);
        self::assertSame(1046, $contract['payload']['target_authority']['target_count']);
        self::assertSame(2092, $contract['payload']['target_authority']['target_locale_row_count']);
        self::assertFalse($contract['payload']['receipt_and_database_state']['receipts_prove_database_state_present']);
        self::assertFalse($contract['payload']['regenerated_output']['direct_activation_allowed']);
    }

    public function test_validator_fails_closed_on_frozen_value_even_with_recomputed_payload_hash(): void
    {
        $contract = $this->contract();
        $contract['payload']['target_authority']['target_count'] = 1048;
        $contract['payload_sha256'] = hash('sha256', $this->canonicalJson($contract['payload']));

        $path = tempnam(sys_get_temp_dir(), 'career-1046-freeze-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($contract, JSON_THROW_ON_ERROR));

        try {
            $process = $this->verify($path);
            $process->run();

            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString(
                'frozen_value_mismatch:target_authority.target_count',
                $process->getErrorOutput(),
            );
            self::assertStringContainsString('"writes":0', $process->getErrorOutput());
            self::assertStringContainsString('"production_apply_allowed":false', $process->getErrorOutput());
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return json_decode(
            (string) file_get_contents($this->contractPath()),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function verify(string $contractPath): Process
    {
        return new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/operations/verify_career_1046_current_state_freeze.php',
            $contractPath,
        ]);
    }

    private function contractPath(): string
    {
        return dirname(__DIR__, 2).'/docs/career/contracts/career-1046-current-state-freeze.v1.json';
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return json_encode(
            $sort($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
