<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CareerGoldDiffScriptTest extends TestCase
{
    public function test_assert_frozen_clean_rejects_staged_frozen_file_changes(): void
    {
        if (! $this->hasCommand('jq')) {
            $this->markTestSkipped('jq is required by career_gold_diff.sh');
        }

        $repo = $this->createScriptRepo();
        $this->runProcess(['git', 'init'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Test User'], $repo);
        $this->runProcess(['git', 'add', '.'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'baseline'], $repo);

        File::put($repo.'/docs/career/first_wave_manifest.json', $this->firstWaveManifest(['existing-job', 'changed-job']));
        $this->runProcess(['git', 'add', 'docs/career/first_wave_manifest.json'], $repo);

        $process = $this->runProcess([
            'bash',
            'scripts/career_gold_diff.sh',
            'candidate.json',
            '--assert-frozen-clean',
            '--frozen-base=',
        ], $repo, mustPass: false);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('staged modifications', $process->getOutput().$process->getErrorOutput());
    }

    public function test_assert_frozen_clean_rejects_committed_frozen_file_changes_from_base_ref(): void
    {
        if (! $this->hasCommand('jq')) {
            $this->markTestSkipped('jq is required by career_gold_diff.sh');
        }

        $repo = $this->createScriptRepo();
        $this->runProcess(['git', 'init'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Test User'], $repo);
        $this->runProcess(['git', 'add', '.'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'baseline'], $repo);
        File::put($repo.'/docs/career/first_wave_manifest.json', $this->firstWaveManifest(['existing-job', 'committed-job']));
        $this->runProcess(['git', 'add', 'docs/career/first_wave_manifest.json'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'change frozen manifest'], $repo);

        $process = $this->runProcess([
            'bash',
            'scripts/career_gold_diff.sh',
            'candidate.json',
            '--assert-frozen-clean',
            '--frozen-base=HEAD~1',
        ], $repo, mustPass: false);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('differ from HEAD~1', $process->getOutput().$process->getErrorOutput());
    }

    private function createScriptRepo(): string
    {
        $repo = storage_path('app/private/testing/career-gold-diff-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($repo.'/scripts');
        File::ensureDirectoryExists($repo.'/docs/career');
        File::copy(base_path('scripts/career_gold_diff.sh'), $repo.'/scripts/career_gold_diff.sh');
        File::put($repo.'/docs/career/first_wave_manifest.json', $this->firstWaveManifest(['existing-job']));
        File::put($repo.'/docs/career/first_wave_aliases.json', json_encode(['aliases' => []], JSON_PRETTY_PRINT));
        File::put($repo.'/candidate.json', json_encode($this->candidateManifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $repo;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function firstWaveManifest(array $slugs): string
    {
        return (string) json_encode([
            'occupations' => array_map(
                static fn (string $slug): array => ['canonical_slug' => $slug],
                $slugs,
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateManifest(): array
    {
        return [
            'manifest_version' => 'career.gold_diff.test.v1',
            'manifest_kind' => 'career_batch_draft_template',
            'generated_from' => 'test',
            'generated_at' => '2026-01-01T00:00:00Z',
            'wave_name' => 'test_wave',
            'batch_id' => 'test-batch',
            'scope' => ['first_wave_overlap_allowed' => false],
            'engine_boundary' => ['mode' => 'validation_only'],
            'occupations' => [
                [
                    'draft_id' => 'draft-1',
                    'occupation_uuid' => '10000000-0000-0000-0000-000000000001',
                    'canonical_slug' => 'candidate-job',
                    'canonical_title_en' => 'Candidate Job',
                    'canonical_title_zh' => '候选职业',
                    'family_uuid' => 'family-1',
                    'source_refs' => [],
                    'alias_candidates' => [],
                    'editorial_patch' => [],
                    'human_moat_tags' => [],
                    'task_prototype_signature' => [],
                    'authoring_status' => 'draft',
                    'notes' => '',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd, bool $mustPass = true): Process
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();

        if ($mustPass) {
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        }

        return $process;
    }

    private function hasCommand(string $command): bool
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($command));
        $process->run();

        return $process->isSuccessful();
    }
}
