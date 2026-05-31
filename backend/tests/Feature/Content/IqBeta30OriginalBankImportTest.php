<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IqBeta30OriginalBankImportTest extends TestCase
{
    private function runCommand(array $command): void
    {
        $process = new Process($command, base_path('..'));
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function generated_beta30_bank_artifacts_are_current_and_verified(): void
    {
        $this->runCommand(['php', 'backend/scripts/iq/build_iq_beta30_original_bank.php', '--check']);
        $this->runCommand(['php', 'backend/scripts/iq/verify_iq_beta30_original_bank.php']);
    }
}
