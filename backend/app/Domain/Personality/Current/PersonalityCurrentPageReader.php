<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

final class PersonalityCurrentPageReader
{
    /** @var array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>}|null */
    private static ?array $runtimeIndex = null;

    public function __construct(private readonly PersonalityCurrentAuthorityPackage $package) {}

    /** @return array<string,mixed> */
    public function payload(string $framework, string $pageKind, string $entityKey, string $locale): array
    {
        $page = $this->package->pageFromIndex(
            self::$runtimeIndex ??= $this->package->runtimeIndex(base_path()),
            $framework,
            $pageKind,
            $entityKey,
            $locale,
        );

        return $page['payload'];
    }

    public function aggregateSha256(): string
    {
        $index = self::$runtimeIndex ??= $this->package->runtimeIndex(base_path());

        return (string) $index['manifest']['aggregate_sha256'];
    }
}
