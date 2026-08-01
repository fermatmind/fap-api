<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class ExactPackagePathGuard
{
    /** @return array{path:string,relative_path:string} */
    public function resolve(string $path): array
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new DomainException('package_path_required');
        }

        $candidate = str_starts_with($path, '/') ? $path : base_path($path);
        $real = realpath($candidate);
        if ($real === false || ! is_dir($real) || is_link($candidate)) {
            throw new DomainException('package_directory_invalid');
        }

        foreach ((array) config('content_promotion.authority_roots', []) as $root) {
            $rootReal = realpath(base_path((string) $root));
            if ($rootReal === false) {
                continue;
            }
            $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if (str_starts_with($real.DIRECTORY_SEPARATOR, $prefix)) {
                return [
                    'path' => $real,
                    'relative_path' => ltrim(substr($real, strlen(base_path())), DIRECTORY_SEPARATOR),
                ];
            }
        }

        throw new DomainException('package_path_not_allowlisted');
    }
}
