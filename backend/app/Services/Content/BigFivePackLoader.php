<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class BigFivePackLoader
{
    public const PACK_ID = 'BIG5_OCEAN';
    public const PACK_VERSION = 'v1';

    public function packRoot(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);

        return base_path('content_packs/' . self::PACK_ID . '/' . $version);
    }

    public function rawDir(?string $version = null): string
    {
        return $this->packRoot($version) . DIRECTORY_SEPARATOR . 'raw';
    }

    public function compiledDir(?string $version = null): string
    {
        return $this->packRoot($version) . DIRECTORY_SEPARATOR . 'compiled';
    }

    public function rawPath(string $file, ?string $version = null): string
    {
        return $this->rawDir($version) . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
    }

    public function compiledPath(string $file, ?string $version = null): string
    {
        return $this->compiledDir($version) . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<array{line:int,row:array<string,string>}>
     */
    public function readCsvWithLines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return [];
        }

        $rows = [];
        $header = null;
        $lineNo = 0;
        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($lineNo === 1) {
                $header = is_array($row) ? array_map(static fn ($v): string => trim((string) $v), $row) : [];
                continue;
            }

            if (!is_array($row) || $header === [] || $row === [null]) {
                continue;
            }

            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = trim((string) ($row[$idx] ?? ''));
            }

            $rows[] = [
                'line' => $lineNo,
                'row' => $assoc,
            ];
        }

        fclose($fp);

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readCompiledJson(string $file, ?string $version = null): ?array
    {
        return $this->readJson($this->compiledPath($file, $version));
    }

    public function hasCompiledFile(string $file, ?string $version = null): bool
    {
        return is_file($this->compiledPath($file, $version));
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : self::PACK_VERSION;
    }
}
