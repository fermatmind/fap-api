<?php
// file: backend/app/DTO/ResolvedPack.php

namespace App\DTO;

final class ResolvedPack
{
    public function __construct(
        public string $packId,
        public string $baseDir,
        public array  $manifest,
        public array  $fallbackChain, // array<array{pack_id:string, base_dir:string, manifest:array}>
        public array  $trace,         // resolve trace for debugging/auditing
        public array  $loaders        // callables / helpers
    ) {}
}