<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class QuestionsService
{
    public function __construct(
        private ContentPacksIndex $index,
        private AssetsMapper $assetsMapper
    ) {
    }

    public function loadByPack(
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrlOverride = null
    ): array {
        $found = $this->index->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'pack not found',
            ];
        }

        $item = $found['item'] ?? [];
        $questionsPath = (string) ($item['questions_path'] ?? '');
        $manifestPath = (string) ($item['manifest_path'] ?? '');

        $questions = $this->readJsonFile($questionsPath);
        if (!($questions['ok'] ?? false)) {
            return $questions;
        }

        $manifest = $this->readJsonFile($manifestPath);
        $manifestData = ($manifest['ok'] ?? false) ? $manifest['data'] : [];

        $contentPackageVersion = (string) ($manifestData['content_package_version'] ?? ($item['content_package_version'] ?? ''));

        $assetsBaseUrl = $this->pickAssetsBaseUrl($assetsBaseUrlOverride, $manifestPath);

        $mapped = $this->assetsMapper->mapQuestionsDoc(
            $questions['data'],
            $packId,
            $dirVersion,
            $assetsBaseUrl
        );

        return [
            'ok' => true,
            'questions' => $mapped,
            'content_package_version' => $contentPackageVersion,
            'manifest' => $manifestData,
        ];
    }

    private function pickAssetsBaseUrl(?string $override, string $manifestPath): ?string
    {
        $override = is_string($override) ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

        if ($manifestPath === '') {
            return null;
        }

        $baseDir = dirname($manifestPath);
        $versionPath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'version.json';
        if (!File::isFile($versionPath)) {
            return null;
        }

        try {
            $raw = File::get($versionPath);
        } catch (\Throwable $e) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $assetsBaseUrl = trim((string) ($decoded['assets_base_url'] ?? ''));
        return $assetsBaseUrl !== '' ? $assetsBaseUrl : null;
    }

    private function readJsonFile(string $path): array
    {
        if ($path === '' || !File::exists($path) || !File::isFile($path)) {
            return [
                'ok' => false,
                'error' => 'READ_FAILED',
                'message' => $path === '' ? 'missing file path' : "file not found: {$path}",
            ];
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'READ_FAILED',
                'message' => "failed to read: {$path}",
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'INVALID_JSON',
                'message' => "invalid json: {$path}",
            ];
        }

        return [
            'ok' => true,
            'data' => $decoded,
        ];
    }
}
