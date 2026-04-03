<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Content\ContentPacksIndex;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class MbtiFormCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly ContentPacksIndex $packsIndex,
    ) {}

    /**
     * @return array{
     *   form_code:string,
     *   pack_id:string,
     *   dir_version:string,
     *   content_package_version:string,
     *   norm_version:string,
     *   scoring_spec_version:string,
     *   quality_version:string,
     *   question_count:int
     * }
     */
    public function resolve(?string $requestedFormCode, ?string $packId = null): array
    {
        $canonical = $this->canonicalize($requestedFormCode);
        $effectivePackId = trim((string) ($packId ?? config('content_packs.default_pack_id', '')));
        if ($effectivePackId === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'MBTI pack_id is not configured.');
        }

        $cacheKey = $effectivePackId.'|'.$canonical;
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $forms = $this->formsConfig();
        $formConfig = $forms[$canonical] ?? null;
        if (! is_array($formConfig)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'MBTI form mapping is not configured.');
        }

        $dirVersion = trim((string) ($formConfig['dir_version'] ?? ''));
        if ($dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'MBTI form dir_version is not configured.');
        }

        $found = $this->packsIndex->find($effectivePackId, $dirVersion);
        if (! ($found['ok'] ?? false)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "MBTI form pack not found: {$canonical}");
        }

        $item = is_array($found['item'] ?? null) ? $found['item'] : [];
        $manifest = $this->readJson(
            (string) ($item['manifest_path'] ?? ''),
            'manifest.json',
            $effectivePackId,
            $dirVersion
        );
        $questions = $this->readJson(
            (string) ($item['questions_path'] ?? ''),
            'questions.json',
            $effectivePackId,
            $dirVersion
        );
        $scoringSpec = $this->readJson(
            $this->packFilePath($item, 'scoring_spec.json'),
            'scoring_spec.json',
            $effectivePackId,
            $dirVersion
        );
        $qualityChecks = $this->readJson(
            $this->packFilePath($item, 'quality_checks.json'),
            'quality_checks.json',
            $effectivePackId,
            $dirVersion
        );
        $norms = $this->readJson(
            $this->packFilePath($item, 'norms.json'),
            'norms.json',
            $effectivePackId,
            $dirVersion
        );

        $questionItems = is_array($questions['items'] ?? null) ? $questions['items'] : null;
        if (! is_array($questionItems)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "MBTI form questions are invalid: {$canonical}");
        }

        $resolved = [
            'form_code' => $canonical,
            'pack_id' => $effectivePackId,
            'dir_version' => $dirVersion,
            'content_package_version' => trim((string) ($manifest['content_package_version'] ?? ($item['content_package_version'] ?? ''))),
            'norm_version' => trim((string) data_get($norms, 'meta.norm_id', $manifest['norms_version'] ?? '')),
            'scoring_spec_version' => trim((string) ($scoringSpec['version'] ?? $scoringSpec['scoring_spec_version'] ?? '')),
            'quality_version' => trim((string) ($qualityChecks['version'] ?? '')),
            'question_count' => count($questionItems),
        ];

        $this->resolved[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formsConfig(): array
    {
        $forms = config('content_packs.mbti_forms.forms', []);

        return is_array($forms) ? $forms : [];
    }

    private function defaultFormCode(): string
    {
        $default = trim((string) config('content_packs.mbti_forms.default_form_code', 'mbti_144'));

        return $default !== '' ? $default : 'mbti_144';
    }

    private function canonicalize(?string $requestedFormCode): string
    {
        $normalized = strtolower(trim((string) $requestedFormCode));
        if ($normalized === '') {
            return $this->defaultFormCode();
        }

        foreach ($this->formsConfig() as $canonical => $config) {
            if ($normalized === strtolower((string) $canonical)) {
                return (string) $canonical;
            }

            $aliases = is_array($config['aliases'] ?? null) ? $config['aliases'] : [];
            foreach ($aliases as $alias) {
                if ($normalized === strtolower(trim((string) $alias))) {
                    return (string) $canonical;
                }
            }
        }

        throw new ApiProblemException(422, 'INVALID_FORM_CODE', "unsupported MBTI form_code: {$requestedFormCode}");
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function packFilePath(array $item, string $filename): string
    {
        $baseDir = trim((string) dirname((string) ($item['manifest_path'] ?? '')));
        if ($baseDir === '') {
            return '';
        }

        return $baseDir.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(
        string $path,
        string $filename,
        string $packId,
        string $dirVersion
    ): array
    {
        if ($path === '' || ! File::exists($path) || ! File::isFile($path)) {
            $this->logAndThrowContentPackError(
                $filename === 'questions.json' ? 'QUESTIONS_FILE_MISSING' : 'CONTENT_PACK_FILE_MISSING',
                $packId,
                $dirVersion,
                $path
            );
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            $this->logAndThrowContentPackError(
                $filename === 'questions.json' ? 'QUESTIONS_JSON_INVALID' : 'CONTENT_PACK_JSON_INVALID',
                $packId,
                $dirVersion,
                $path
            );
        }

        return $decoded;
    }

    private function logAndThrowContentPackError(
        string $reason,
        string $packId,
        string $dirVersion,
        string $path
    ): never {
        Log::error($reason, [
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'questions_path' => $path,
            'exception_message' => $reason,
            'json_error' => json_last_error_msg(),
        ]);

        throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'MBTI form content pack unavailable.');
    }
}
