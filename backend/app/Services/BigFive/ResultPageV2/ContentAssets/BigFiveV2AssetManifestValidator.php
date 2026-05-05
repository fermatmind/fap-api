<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

final class BigFiveV2AssetManifestValidator
{
    private const FORBIDDEN_RUNTIME_USE = [
        'runtime',
        'production',
        'production_runtime',
    ];

    /**
     * @return list<string>
     */
    public function validateDocument(array $document, string $relativePath): array
    {
        $errors = [];
        $this->collectFlagErrors($document, $relativePath, $errors);

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function validateChecksumFile(string $checksumPath, string $packagePath, string $packageRelativePath): array
    {
        $lines = file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return ["{$packageRelativePath}: checksum file is unreadable"];
        }

        $errors = [];
        foreach ($lines as $lineNumber => $line) {
            if (! preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/', trim($line), $matches)) {
                $errors[] = "{$packageRelativePath}: checksum line ".($lineNumber + 1).' is malformed';
                continue;
            }

            $expectedHash = $matches[1];
            $fileName = trim($matches[2]);
            if ($fileName === '' || str_contains($fileName, '..')) {
                $errors[] = "{$packageRelativePath}: checksum line ".($lineNumber + 1).' has unsafe file path';
                continue;
            }

            $targetPath = $packagePath.DIRECTORY_SEPARATOR.$fileName;
            if (! is_file($targetPath)) {
                $errors[] = "{$packageRelativePath}: checksum target missing: {$fileName}";
                continue;
            }

            $actualHash = hash_file('sha256', $targetPath);
            if ($actualHash !== $expectedHash) {
                $errors[] = "{$packageRelativePath}: checksum mismatch for {$fileName}";
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private function collectFlagErrors(mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $fieldPath = is_string($key) ? "{$path}.{$key}" : "{$path}[{$key}]";

            if ($key === 'production_use_allowed' && $child === true) {
                $errors[] = "{$fieldPath} must not be true";
            }

            if ($key === 'ready_for_runtime' && $child === true) {
                $errors[] = "{$fieldPath} must not be true";
            }

            if ($key === 'ready_for_production' && $child === true) {
                $errors[] = "{$fieldPath} must not be true";
            }

            if ($key === 'runtime_use' && is_string($child) && in_array(strtolower($child), self::FORBIDDEN_RUNTIME_USE, true)) {
                $errors[] = "{$fieldPath} must not be runtime or production";
            }

            $this->collectFlagErrors($child, $fieldPath, $errors);
        }
    }
}
