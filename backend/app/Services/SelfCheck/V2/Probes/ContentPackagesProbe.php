<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;

final class ContentPackagesProbe implements ProbeInterface
{
    public function __construct(private readonly string $region, private readonly string $locale)
    {
    }

    public function name(): string
    {
        return 'content_source';
    }

    public function probe(bool $verbose = false): array
    {
        $base = realpath(base_path('..' . DIRECTORY_SEPARATOR . 'content_packages'))
            ?: base_path('..' . DIRECTORY_SEPARATOR . 'content_packages');
        $defaultDir = rtrim($base, '/') . '/default/' . $this->region . '/' . $this->locale;

        $existsBase = is_dir($base);
        $existsDefault = is_dir($defaultDir);
        $readableBase = $existsBase ? is_readable($base) : false;
        $readableDefault = $existsDefault ? is_readable($defaultDir) : false;

        $ok = $existsBase && $readableBase && $existsDefault && $readableDefault;

        return (new ProbeResult(
            $ok,
            $ok ? '' : 'CONTENT_SOURCE_NOT_READY',
            $ok ? '' : 'content_packages default path not readable',
            [
                'base_path' => $base,
                'default_path' => $defaultDir,
                'region' => $this->region,
                'locale' => $this->locale,
            ],
        ))->toArray($verbose);
    }
}
