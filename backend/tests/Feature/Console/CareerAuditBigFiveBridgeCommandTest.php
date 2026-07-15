<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Bridge\BigFiveCareerBridgeAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Career\BigFiveCareerBridgeAuditFixture;
use Tests\TestCase;

final class CareerAuditBigFiveBridgeCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('app/testing/big-five-career-bridge-audit-'.strtolower(str()->random(8)));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_command_emits_deterministic_json_and_markdown_without_database_queries(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $paths = $this->writeArtifacts(
            BigFiveCareerBridgeAuditFixture::bigFiveAuthority(),
            $career,
            BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career)),
        );
        $jsonOutput = $this->root.'/report.json';
        $markdownOutput = $this->root.'/report.md';
        DB::enableQueryLog();

        $this->artisan('career:audit-big-five-bridge', [
            '--big-five-projection' => $paths['big_five'],
            '--career-projection' => $paths['career'],
            '--candidates' => $paths['candidates'],
            '--format' => 'json',
            '--output' => $jsonOutput,
        ])->assertExitCode(0);
        $this->artisan('career:audit-big-five-bridge', [
            '--big-five-projection' => $paths['big_five'],
            '--career-projection' => $paths['career'],
            '--candidates' => $paths['candidates'],
            '--format' => 'markdown',
            '--output' => $markdownOutput,
        ])->assertExitCode(0);

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame('pass', data_get(json_decode((string) File::get($jsonOutput), true), 'status'));
        $this->assertStringContainsString('# Big Five → Career bridge audit', (string) File::get($markdownOutput));
        $this->assertStringContainsString('No ranking, hiring, outcome prediction', (string) File::get($markdownOutput));
    }

    public function test_command_returns_failure_for_blocked_candidate(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $candidates = BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career));
        $candidates['rows'][0]['output']['status'] = 'generated_candidate';
        $candidates['rows'][0]['output']['public_reader_allowed'] = false;
        $paths = $this->writeArtifacts(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);

        $this->artisan('career:audit-big-five-bridge', [
            '--big-five-projection' => $paths['big_five'],
            '--career-projection' => $paths['career'],
            '--candidates' => $paths['candidates'],
        ])->assertExitCode(1);
    }

    /** @return array{big_five: string, career: string, candidates: string} */
    private function writeArtifacts(array $bigFive, array $career, array $candidates): array
    {
        $paths = [
            'big_five' => $this->root.'/big-five.json',
            'career' => $this->root.'/career.json',
            'candidates' => $this->root.'/candidates.json',
        ];
        File::put($paths['big_five'], json_encode($bigFive, JSON_THROW_ON_ERROR));
        File::put($paths['career'], json_encode($career, JSON_THROW_ON_ERROR));
        File::put($paths['candidates'], json_encode($candidates, JSON_THROW_ON_ERROR));

        return $paths;
    }
}
