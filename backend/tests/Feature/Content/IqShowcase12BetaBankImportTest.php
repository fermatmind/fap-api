<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IqShowcase12BetaBankImportTest extends TestCase
{
    public function test_build_script_reproduces_committed_showcase12_bank_artifacts(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/iq/build_showcase12_beta_bank.php'),
            '--check',
        ], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput().$process->getOutput()
        );
    }

    public function test_verify_script_accepts_showcase12_bank_contract(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/iq/verify_showcase12_beta_bank.php'),
        ], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput().$process->getOutput()
        );

        $payload = json_decode($process->getOutput(), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('IQ_SHOWCASE_12_BETA', $payload['bank_id'] ?? null);
        $this->assertSame(12, $payload['item_count'] ?? null);
        $this->assertSame(4, data_get($payload, 'dimensions.VSPR'));
        $this->assertSame(4, data_get($payload, 'dimensions.VSI'));
        $this->assertSame(4, data_get($payload, 'dimensions.NPR'));
        $this->assertFalse((bool) ($payload['beta_50_imported'] ?? true));
    }
}
