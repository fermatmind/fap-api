<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BigFiveV2AssetPackageLoader
{
    public const ROOT_RELATIVE_PATH = 'content_assets/big5/result_page_v2';

    private const SUPPORTED_EXTENSIONS = [
        'csv',
        'json',
        'jsonl',
        'md',
        'txt',
    ];

    public function __construct(
        private readonly BigFiveV2AssetManifestValidator $validator = new BigFiveV2AssetManifestValidator(),
    ) {}

    public function inventory(?string $rootPath = null): BigFiveV2AssetInventory
    {
        $rootPath = $rootPath ?? base_path(self::ROOT_RELATIVE_PATH);
        if (! is_dir($rootPath)) {
            return new BigFiveV2AssetInventory($rootPath, self::ROOT_RELATIVE_PATH, [], ["asset root missing: {$rootPath}"]);
        }

        $packages = [];
        foreach ($this->discoverPackagePaths($rootPath) as $packagePath) {
            $packages[] = $this->loadPackage($packagePath);
        }

        usort(
            $packages,
            static fn (BigFiveV2AssetPackage $left, BigFiveV2AssetPackage $right): int => $left->relativePath <=> $right->relativePath
        );

        return new BigFiveV2AssetInventory($rootPath, self::ROOT_RELATIVE_PATH, $packages, []);
    }

    private function loadPackage(string $packagePath): BigFiveV2AssetPackage
    {
        $relativePath = $this->relativePath($packagePath, base_path());
        $files = $this->directFilesUnder($packagePath);
        $manifestFiles = [];
        $checksumFiles = [];
        $supportedFiles = [];
        $versions = [];
        $runtimeUses = [];
        $errors = [];
        $productionUseAllowed = false;
        $readyForRuntime = false;
        $readyForProduction = false;

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());
            $filePath = $file->getPathname();
            $fileRelativePath = $this->relativePath($filePath, base_path());

            if (in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                $supportedFiles[] = $fileRelativePath;
            }

            if ($this->isChecksumFile($file)) {
                $checksumFiles[] = $fileRelativePath;
                $errors = array_merge($errors, $this->validator->validateChecksumFile($filePath, $packagePath, $relativePath));
                continue;
            }

            if ($extension === 'json') {
                $document = $this->decodeJsonFile($filePath, $fileRelativePath, $errors);
                if ($document === null) {
                    continue;
                }

                if ($this->isManifestFile($file)) {
                    $manifestFiles[] = $fileRelativePath;
                }

                $errors = array_merge($errors, $this->validator->validateDocument($document, $fileRelativePath));
                $this->collectPackageMetadata($document, $versions, $runtimeUses, $productionUseAllowed, $readyForRuntime, $readyForProduction);
            } elseif ($extension === 'jsonl') {
                $this->validateJsonlFile($filePath, $fileRelativePath, $errors);
            } elseif ($extension === 'csv') {
                $this->validateCsvFile($filePath, $fileRelativePath, $errors);
            } elseif (in_array($extension, ['md', 'txt'], true) && ! is_readable($filePath)) {
                $errors[] = "{$fileRelativePath} is unreadable";
            }
        }

        $versions = array_values(array_unique(array_filter($versions)));
        $runtimeUses = array_values(array_unique(array_filter($runtimeUses)));

        return new BigFiveV2AssetPackage(
            key: str_replace(['/', '.'], '_', $relativePath),
            relativePath: $relativePath,
            fileCount: count($files),
            manifestFiles: $manifestFiles,
            checksumFiles: $checksumFiles,
            supportedFiles: $supportedFiles,
            versions: $versions,
            runtimeUses: $runtimeUses,
            productionUseAllowed: $productionUseAllowed,
            readyForRuntime: $readyForRuntime,
            readyForProduction: $readyForProduction,
            errors: array_values(array_unique($errors)),
        );
    }

    /**
     * @return list<string>
     */
    private function discoverPackagePaths(string $rootPath): array
    {
        $paths = [];
        foreach ($this->filesUnder($rootPath) as $file) {
            if ($this->isManifestFile($file) || $this->isChecksumFile($file)) {
                $paths[$file->getPath()] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function directFilesUnder(string $path): array
    {
        $files = [];
        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getFileInfo();
            }
        }

        return $files;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function filesUnder(string $path): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isManifestFile(SplFileInfo $file): bool
    {
        return strtolower($file->getExtension()) === 'json'
            && str_contains(strtolower($file->getBasename()), 'manifest');
    }

    private function isChecksumFile(SplFileInfo $file): bool
    {
        return in_array($file->getBasename(), ['SHA256SUMS', 'SHA256SUMS.txt'], true);
    }

    /**
     * @param  list<string>  $errors
     * @return array<string,mixed>|list<mixed>|null
     */
    private function decodeJsonFile(string $path, string $relativePath, array &$errors): ?array
    {
        $json = file_get_contents($path);
        if (! is_string($json)) {
            $errors[] = "{$relativePath} is unreadable";

            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $errors[] = "{$relativePath} is not valid JSON";

            return null;
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateJsonlFile(string $path, string $relativePath, array &$errors): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            $errors[] = "{$relativePath} is unreadable";

            return;
        }

        foreach ($lines as $lineNumber => $line) {
            if (! is_array(json_decode($line, true))) {
                $errors[] = "{$relativePath}: line ".($lineNumber + 1).' is not valid JSON';
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateCsvFile(string $path, string $relativePath, array &$errors): void
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $errors[] = "{$relativePath} is unreadable";

            return;
        }

        try {
            if (fgetcsv($handle) === false) {
                $errors[] = "{$relativePath} has no readable CSV rows";
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $versions
     * @param  list<string>  $runtimeUses
     */
    private function collectPackageMetadata(
        array $document,
        array &$versions,
        array &$runtimeUses,
        bool &$productionUseAllowed,
        bool &$readyForRuntime,
        bool &$readyForProduction,
    ): void {
        foreach (['package_version', 'version', 'matrix_version', 'schema'] as $key) {
            if (is_string($document[$key] ?? null) && trim($document[$key]) !== '') {
                $versions[] = trim((string) $document[$key]);
            }
        }

        $this->collectFlags($document, $runtimeUses, $productionUseAllowed, $readyForRuntime, $readyForProduction);
    }

    /**
     * @param  list<string>  $runtimeUses
     */
    private function collectFlags(
        mixed $value,
        array &$runtimeUses,
        bool &$productionUseAllowed,
        bool &$readyForRuntime,
        bool &$readyForProduction,
    ): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if ($key === 'runtime_use' && is_string($child)) {
                $runtimeUses[] = $child;
            } elseif ($key === 'production_use_allowed' && $child === true) {
                $productionUseAllowed = true;
            } elseif ($key === 'ready_for_runtime' && $child === true) {
                $readyForRuntime = true;
            } elseif ($key === 'ready_for_production' && $child === true) {
                $readyForProduction = true;
            }

            $this->collectFlags($child, $runtimeUses, $productionUseAllowed, $readyForRuntime, $readyForProduction);
        }
    }

    private function relativePath(string $path, string $basePath): string
    {
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $normalizedBase = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $basePath), '/').'/';

        return str_starts_with($normalizedPath, $normalizedBase)
            ? substr($normalizedPath, strlen($normalizedBase))
            : $normalizedPath;
    }
}
