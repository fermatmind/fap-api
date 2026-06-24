<?php

declare(strict_types=1);

namespace App\Services\Iq;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class IqOwnerOriginal30BankService
{
    public const SCALE_CODE = 'IQ_INTELLIGENCE_QUOTIENT';

    public const LEGACY_SCALE_CODE = 'IQ_RAVEN';

    public const BANK_ID = 'IQ_OWNER_ORIGINAL_30';

    public const FORM_CODE = 'IQ_OWNER_ORIGINAL_30';

    public const DIR_VERSION = 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO';

    public const DELIVERY_MODE = 'current_question';

    private const PUBLIC_ASSET_SOURCE_PREFIX = 'assets/iq_owner_original_30/';

    private const PUBLIC_ASSET_ROUTE_PREFIX = 'iq_owner_original_30/';

    /**
     * @return array<string,mixed>
     */
    public function startMetadata(string $packId, string $dirVersion): array
    {
        return [
            'form_code' => self::FORM_CODE,
            'bank_id' => self::BANK_ID,
            'question_count' => $this->questionCount(),
            'question_delivery_mode' => self::DELIVERY_MODE,
            'question_delivery_contract' => 'attempt_current_question_v1',
            'question_delivery_endpoint' => '/api/v0.3/attempts/{attempt_id}/questions?index={index}',
            'owner_original_bank' => true,
            'owner_original_bank_pack_id' => $packId,
            'owner_original_bank_dir_version' => $dirVersion,
        ];
    }

    public function isOwnerOriginalRequest(?string $formCode, ?string $bankId = null): bool
    {
        $candidates = [
            $formCode,
            $bankId,
        ];

        foreach ($candidates as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            if ($normalized === self::FORM_CODE || $normalized === self::BANK_ID) {
                return true;
            }
        }

        return false;
    }

    public function isOwnerOriginalAttempt(Attempt $attempt): bool
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if (! in_array($scaleCode, [self::SCALE_CODE, self::LEGACY_SCALE_CODE], true)) {
            return false;
        }

        $formCode = data_get($attempt->answers_summary_json, 'meta.form_code');
        $bankId = data_get($attempt->answers_summary_json, 'meta.bank_id');

        return $this->isOwnerOriginalRequest(
            is_string($formCode) || is_numeric($formCode) ? (string) $formCode : null,
            is_string($bankId) || is_numeric($bankId) ? (string) $bankId : null
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function publicQuestionPayload(Attempt $attempt, int $index, ?Request $request = null): array
    {
        $items = $this->items();
        $count = count($items);
        if ($index < 0 || $index >= $count) {
            throw new ApiProblemException(422, 'IQ_QUESTION_INDEX_INVALID', 'question index is out of range.');
        }

        $item = $items[$index] ?? null;
        if (! is_array($item)) {
            throw new ApiProblemException(500, 'IQ_OWNER_BANK_INVALID', 'owner IQ bank item is invalid.');
        }

        return [
            'ok' => true,
            'schema_version' => 'fm.iq.question_delivery.v1',
            'attempt_id' => (string) $attempt->id,
            'scale_code' => self::SCALE_CODE,
            'scale_code_legacy' => self::LEGACY_SCALE_CODE,
            'bank_id' => self::BANK_ID,
            'form_code' => self::FORM_CODE,
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? self::DIR_VERSION),
            'content_package_version' => (string) ($attempt->content_package_version ?? ''),
            'question_count' => $count,
            'delivery' => [
                'mode' => self::DELIVERY_MODE,
                'index' => $index,
                'window_size' => 1,
                'has_previous' => $index > 0,
                'has_next' => $index < $count - 1,
            ],
            'questions' => [
                'schema_version' => 'fm.iq.owner_image_bank.items.public.v1',
                'items' => [$this->publicItem($item, $this->publicRequestOrigin($request))],
            ],
            'meta' => [
                'source' => 'attempt_bound_owner_bank',
                'public_payload' => true,
            ],
        ];
    }

    public function questionCount(): int
    {
        return count($this->items());
    }

    public function validateSubmit(Attempt $attempt, SubmitAttemptDTO $dto): void
    {
        if (! $this->isOwnerOriginalAttempt($attempt)) {
            return;
        }

        $expected = $this->optionCodesByQuestionId();
        $seen = [];

        foreach ($dto->answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = trim((string) ($answer['question_id'] ?? ''));
            $code = strtoupper(trim((string) ($answer['code'] ?? $answer['option_code'] ?? '')));

            if ($questionId === '' || ! array_key_exists($questionId, $expected)) {
                throw new ApiProblemException(422, 'IQ_OWNER_SUBMIT_UNKNOWN_QUESTION', 'submitted IQ question_id is not in the attempt-bound bank.');
            }

            if (array_key_exists($questionId, $seen)) {
                throw new ApiProblemException(422, 'IQ_OWNER_SUBMIT_DUPLICATE_QUESTION', 'submitted IQ answers contain duplicate question_id values.');
            }
            $seen[$questionId] = true;

            if ($code === '' || ! in_array($code, $expected[$questionId], true)) {
                throw new ApiProblemException(422, 'IQ_OWNER_SUBMIT_INVALID_OPTION', 'submitted IQ option code is invalid for the question.');
            }
        }

        $missing = array_diff(array_keys($expected), array_keys($seen));
        if ($missing !== []) {
            throw new ApiProblemException(422, 'IQ_OWNER_SUBMIT_MISSING_QUESTION', 'submitted IQ answers must include every attempt-bound question.');
        }
    }

    public function publicAssetResponse(string $path): BinaryFileResponse
    {
        $absolutePath = $this->publicAssetAbsolutePath($path);

        return response()->file($absolutePath, [
            'Content-Type' => $this->contentTypeForAsset($absolutePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function items(): array
    {
        $doc = $this->readJson('items.json');
        $items = $doc['items'] ?? null;
        if (! is_array($items)) {
            throw new ApiProblemException(500, 'IQ_OWNER_BANK_INVALID', 'owner IQ bank items are invalid.');
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function optionCodesByQuestionId(): array
    {
        $map = [];
        foreach ($this->items() as $item) {
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $codes = [];
            foreach ($options as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($option['code'] ?? '')));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
            if ($questionId !== '') {
                $map[$questionId] = array_values(array_unique($codes));
            }
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function publicItem(array $item, string $publicAssetOrigin): array
    {
        $options = [];
        foreach (($item['options'] ?? []) as $option) {
            if (is_array($option)) {
                $options[] = $this->onlyPublicOptionFields($option, $publicAssetOrigin);
            }
        }

        return [
            'schema_version' => (string) ($item['schema_version'] ?? 'fm.iq.owner_image_bank.item.v1'),
            'scale_code' => self::SCALE_CODE,
            'bank_id' => self::BANK_ID,
            'question_id' => (string) ($item['question_id'] ?? ''),
            'item_id' => (string) ($item['item_id'] ?? ''),
            'sequence' => (int) ($item['sequence'] ?? 0),
            'order' => (int) ($item['sequence'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'stem' => $this->onlyPublicMediaFields(
                is_array($item['stem'] ?? null) ? $item['stem'] : [],
                $publicAssetOrigin
            ),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string,mixed>  $option
     * @return array<string,mixed>
     */
    private function onlyPublicOptionFields(array $option, string $publicAssetOrigin): array
    {
        return [
            'code' => strtoupper(trim((string) ($option['code'] ?? ''))),
            'label' => (string) ($option['label'] ?? $option['code'] ?? ''),
            ...$this->onlyPublicMediaFields($option, $publicAssetOrigin),
        ];
    }

    /**
     * @param  array<string,mixed>  $media
     * @return array<string,mixed>
     */
    private function onlyPublicMediaFields(array $media, string $publicAssetOrigin): array
    {
        $assets = is_array($media['assets'] ?? null) ? $media['assets'] : [];
        $publicUrl = $this->publicAssetUrl(
            is_string($assets['image'] ?? null) ? (string) $assets['image'] : '',
            $publicAssetOrigin
        );

        return [
            'type' => (string) ($media['type'] ?? 'image'),
            'media_type' => (string) ($media['media_type'] ?? 'image/webp'),
            'assets' => $assets,
            ...($publicUrl !== null ? [
                'src' => $publicUrl,
                'public_url' => $publicUrl,
            ] : []),
            'width' => (int) ($media['width'] ?? 0),
            'height' => (int) ($media['height'] ?? 0),
            'sha256' => (string) ($media['sha256'] ?? ''),
            'accessibility_label' => (string) ($media['accessibility_label'] ?? ''),
        ];
    }

    private function publicAssetUrl(string $assetPath, string $publicAssetOrigin): ?string
    {
        $routePath = $this->publicRoutePathForAsset($assetPath);
        if ($routePath === null) {
            return null;
        }

        return $publicAssetOrigin.'/api/v0.3/iq-owner-original-30/assets/'.$this->encodePublicPath($routePath);
    }

    private function publicRequestOrigin(?Request $request): string
    {
        if ($request !== null) {
            $forwardedProto = trim((string) $request->headers->get('x-forwarded-proto', ''));
            $forwardedHost = trim((string) $request->headers->get('x-forwarded-host', ''));

            $scheme = $forwardedProto !== ''
                ? strtolower(trim(Str::before($forwardedProto, ',')))
                : $request->getScheme();
            $host = $forwardedHost !== ''
                ? trim(Str::before($forwardedHost, ','))
                : $request->getHost();

            if ($host !== '') {
                return rtrim($scheme.'://'.$host, '/');
            }
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function publicRoutePathForAsset(string $assetPath): ?string
    {
        $normalized = $this->normalizeAssetPath($assetPath);
        if ($normalized === null || ! str_starts_with($normalized, self::PUBLIC_ASSET_SOURCE_PREFIX)) {
            return null;
        }

        return substr($normalized, strlen('assets/'));
    }

    private function publicAssetAbsolutePath(string $routePath): string
    {
        $normalized = $this->normalizeAssetPath($routePath);
        if ($normalized === null || ! str_starts_with($normalized, self::PUBLIC_ASSET_ROUTE_PREFIX)) {
            throw new ApiProblemException(404, 'IQ_OWNER_ASSET_NOT_FOUND', 'owner IQ asset not found.');
        }

        $assetRoot = realpath($this->assetDir());
        $assetPath = realpath($this->assetDir().'/'.$normalized);

        if ($assetRoot === false || $assetPath === false || ! str_starts_with($assetPath, $assetRoot.DIRECTORY_SEPARATOR)) {
            throw new ApiProblemException(404, 'IQ_OWNER_ASSET_NOT_FOUND', 'owner IQ asset not found.');
        }

        if (! is_file($assetPath)) {
            throw new ApiProblemException(404, 'IQ_OWNER_ASSET_NOT_FOUND', 'owner IQ asset not found.');
        }

        return $assetPath;
    }

    private function normalizeAssetPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = ltrim($normalized, '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    private function encodePublicPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function contentTypeForAsset(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $file): array
    {
        $path = $this->bankDir().'/'.$file;
        if (! File::exists($path)) {
            throw new ApiProblemException(500, 'IQ_OWNER_BANK_MISSING', 'owner IQ bank file is missing.');
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new ApiProblemException(500, 'IQ_OWNER_BANK_INVALID', 'owner IQ bank file is invalid.');
        }

        return $decoded;
    }

    private function bankDir(): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_VERSION.'/banks/'.self::BANK_ID);
    }

    private function assetDir(): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_VERSION.'/assets');
    }
}
