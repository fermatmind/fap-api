<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineJson;
use App\Domain\GreenfieldBaseline\GreenfieldBaselinePackageBuilder;
use Illuminate\Console\Command;
use Throwable;

final class GreenfieldBaselinePackageStream extends Command
{
    protected $signature = 'greenfield:baseline:package
        {--stream= : Absolute path to the SELECT-only source JSONL stream}
        {--output= : New output directory for the deterministic package}
        {--expected-projection-sha256= : Exact current Career runtime projection SHA256}
        {--download-media : Download every selected public media object}
        {--expected-media-host-set-sha256= : Preflight-bound public media host-set SHA256}
        {--json : Emit JSON output}';

    protected $description = 'Build a deterministic Greenfield current-published package from a source stream.';

    public function __construct(
        private readonly GreenfieldBaselinePackageBuilder $builder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifest = $this->builder->build(
                $this->absolutePathOption('stream'),
                $this->absolutePathOption('output'),
                trim((string) $this->option('expected-projection-sha256')),
                (bool) $this->option('download-media'),
                $this->nullableOption('expected-media-host-set-sha256'),
            );
            $payload = [
                'status' => 'packaged',
                'package_sha256' => $manifest['package_sha256'],
                'dataset_counts' => $manifest['dataset_counts'],
                'career_projection' => $manifest['career_projection'],
                'media' => $manifest['media'],
                'writes_committed' => false,
            ];
            $this->line(GreenfieldBaselineJson::encode($payload, (bool) $this->option('json')));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function absolutePathOption(string $name): string
    {
        $path = trim((string) $this->option($name));
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new \RuntimeException("--{$name} must be an absolute path.");
        }

        return $path;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value !== '' ? $value : null;
    }
}
