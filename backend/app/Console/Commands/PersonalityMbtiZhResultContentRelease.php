<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiZhResultContentReleaseService;
use Illuminate\Console\Command;
use RuntimeException;

final class PersonalityMbtiZhResultContentRelease extends Command
{
    protected $signature = 'personality:mbti-zh-result-content-release
        {--stage=dry-run : dry-run, draft, promotion-dry-run, promote, readback, or rollback}
        {--package-hash=}
        {--pre-state-hash=}
        {--revision-set-hash=}
        {--admin-user-id=}
        {--production-content-write-authorized : Required for draft, promote, and rollback}
        {--no-publication-change : Confirms public SEO publication state remains unchanged}
        {--no-indexability-change : Confirms indexability remains unchanged}
        {--no-sitemap : Confirms sitemap state remains unchanged}
        {--no-llms : Confirms llms state remains unchanged}
        {--no-search-release : Confirms Search Channel remains unchanged}';

    protected $description = 'Freeze, revision, atomically promote, read back, or roll back the exact 32-row zh-CN MBTI result package.';

    public function handle(MbtiZhResultContentReleaseService $service): int
    {
        try {
            $stage = strtolower(trim((string) $this->option('stage')));
            if (in_array($stage, ['draft', 'promote', 'rollback'], true)) {
                $this->assertControlledWriteBoundary();
            }
            $result = match ($stage) {
                'dry-run' => $service->dryRun(),
                'draft' => $service->writeDraft($this->required('package-hash'), $this->required('pre-state-hash'), (int) $this->required('admin-user-id')),
                'promotion-dry-run' => $service->promotionDryRun(),
                'promote' => $service->promote($this->required('package-hash'), $this->required('pre-state-hash'), $this->required('revision-set-hash')),
                'readback' => $service->readback($this->required('package-hash')),
                'rollback' => $service->rollback($this->required('package-hash'), $this->required('pre-state-hash'), $this->required('revision-set-hash')),
                default => throw new RuntimeException('Unsupported --stage value.'),
            };

            $this->line((string) json_encode([
                'ok' => true,
                ...$result,
                'publication_changed' => false,
                'indexability_changed' => false,
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_release_mutated' => false,
                'deploy_mutated' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function required(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required for this stage.');
        }

        return $value;
    }

    private function assertControlledWriteBoundary(): void
    {
        foreach ([
            'production-content-write-authorized',
            'no-publication-change',
            'no-indexability-change',
            'no-sitemap',
            'no-llms',
            'no-search-release',
        ] as $option) {
            if (! (bool) $this->option($option)) {
                throw new RuntimeException('--'.$option.' is required for a controlled write stage.');
            }
        }
    }
}
