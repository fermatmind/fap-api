<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Cms\MediaVariantGenerator;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class MediaLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $query = MediaAsset::query()
            ->withoutGlobalScopes()
            ->with('variants')
            ->where('org_id', $validated['org_id'])
            ->publishedPublic()
            ->orderBy('asset_key');

        $prefix = trim((string) $request->query('prefix', ''));
        if ($prefix !== '') {
            $query->where('asset_key', 'like', $prefix.'%');
        }

        return response()->json([
            'ok' => true,
            'items' => $query
                ->get()
                ->map(fn (MediaAsset $asset): array => $this->assetPayload($asset))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, string $assetKey): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->with('variants')
            ->where('org_id', $validated['org_id'])
            ->where('asset_key', $this->normalizeKey($assetKey))
            ->publishedPublic()
            ->first();

        if (! $asset instanceof MediaAsset) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'media asset not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'asset' => $this->assetPayload($asset),
        ]);
    }

    public function internalIndex(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        return response()->json([
            'ok' => true,
            'items' => MediaAsset::query()
                ->withoutGlobalScopes()
                ->with('variants')
                ->where('org_id', $validated['org_id'])
                ->orderBy('asset_key')
                ->get()
                ->map(fn (MediaAsset $asset): array => $this->assetPayload($asset))
                ->values()
                ->all(),
        ]);
    }

    public function internalUpdate(Request $request, string $assetKey): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'disk' => ['nullable', 'string', 'max:64'],
            'path' => ['nullable', 'string', 'max:512'],
            'url' => ['nullable', 'string', 'max:1024'],
            'mime_type' => ['nullable', 'string', 'max:128'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'bytes' => ['nullable', 'integer', 'min:0'],
            'alt' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:4000'],
            'credit' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([MediaAsset::STATUS_DRAFT, MediaAsset::STATUS_PUBLISHED])],
            'is_public' => ['required', 'boolean'],
            'payload_json' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'variants.*.variant_key' => ['required_with:variants', 'string', 'max:64'],
            'variants.*.path' => ['nullable', 'string', 'max:512'],
            'variants.*.url' => ['nullable', 'string', 'max:1024'],
            'variants.*.mime_type' => ['nullable', 'string', 'max:128'],
            'variants.*.width' => ['nullable', 'integer', 'min:1'],
            'variants.*.height' => ['nullable', 'integer', 'min:1'],
            'variants.*.bytes' => ['nullable', 'integer', 'min:0'],
            'variants.*.payload_json' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $orgId = (int) ($validated['org_id'] ?? 0);
        $normalizedKey = $this->normalizeKey($assetKey);

        $asset = DB::transaction(function () use ($orgId, $normalizedKey, $validated): MediaAsset {
            $asset = MediaAsset::query()
                ->withoutGlobalScopes()
                ->firstOrNew([
                    'org_id' => $orgId,
                    'asset_key' => $normalizedKey,
                ]);

            $asset->fill([
                'disk' => $this->nullableString($validated['disk'] ?? null) ?? 'public_static',
                'path' => $this->nullableString($validated['path'] ?? null),
                'url' => PublicMediaUrlGuard::canonicalMediaUrl(
                    $this->nullableString($validated['disk'] ?? null) ?? 'public_static',
                    $this->nullableString($validated['path'] ?? null),
                    $this->nullableString($validated['url'] ?? null)
                ),
                'mime_type' => $this->nullableString($validated['mime_type'] ?? null),
                'width' => $validated['width'] ?? null,
                'height' => $validated['height'] ?? null,
                'bytes' => $validated['bytes'] ?? null,
                'alt' => $this->nullableString($validated['alt'] ?? null),
                'caption' => $this->nullableString($validated['caption'] ?? null),
                'credit' => $this->nullableString($validated['credit'] ?? null),
                'status' => (string) $validated['status'],
                'is_public' => (bool) $validated['is_public'],
                'payload_json' => $validated['payload_json'] ?? [],
            ]);
            $asset->save();

            if (array_key_exists('variants', $validated)) {
                $asset->variants()->delete();
                foreach ($validated['variants'] ?? [] as $variant) {
                    $asset->variants()->create([
                        'variant_key' => $this->normalizeKey((string) $variant['variant_key']),
                        'path' => $this->nullableString($variant['path'] ?? null),
                        'url' => PublicMediaUrlGuard::canonicalMediaUrl(
                            $this->nullableString($validated['disk'] ?? null) ?? 'public_static',
                            $this->nullableString($variant['path'] ?? null),
                            $this->nullableString($variant['url'] ?? null)
                        ),
                        'mime_type' => $this->nullableString($variant['mime_type'] ?? null),
                        'width' => $variant['width'] ?? null,
                        'height' => $variant['height'] ?? null,
                        'bytes' => $variant['bytes'] ?? null,
                        'payload_json' => $variant['payload_json'] ?? [],
                    ]);
                }
            }

            return $asset->load('variants');
        });

        return response()->json([
            'ok' => true,
            'asset' => $this->assetPayload($asset),
        ]);
    }

    public function internalUpload(Request $request, string $assetKey, MediaVariantGenerator $generator): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'alt' => ['required', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:4000'],
            'credit' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([MediaAsset::STATUS_DRAFT, MediaAsset::STATUS_PUBLISHED])],
            'is_public' => ['nullable', 'boolean'],
            'payload_json' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => ['file' => ['An image file is required.']],
            ], 422);
        }

        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'org_id' => (int) ($validated['org_id'] ?? 0),
                'asset_key' => $this->normalizeKey($assetKey),
            ]);

        $asset->fill([
            'alt' => $this->nullableString($validated['alt'] ?? null),
            'caption' => $this->nullableString($validated['caption'] ?? null),
            'credit' => $this->nullableString($validated['credit'] ?? null),
            'status' => (string) ($validated['status'] ?? MediaAsset::STATUS_PUBLISHED),
            'is_public' => (bool) ($validated['is_public'] ?? true),
            'payload_json' => $validated['payload_json'] ?? ($asset->payload_json ?? []),
        ]);
        $asset->save();

        $asset = $generator->storeUploadAndGenerate($asset, $file);

        return response()->json([
            'ok' => true,
            'asset' => $this->assetPayload($asset),
        ]);
    }

    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assetPayload(MediaAsset $asset): array
    {
        return [
            'asset_key' => (string) $asset->asset_key,
            'disk' => (string) $asset->disk,
            'path' => $asset->path,
            'url' => PublicMediaUrlGuard::canonicalMediaUrl(
                (string) $asset->disk,
                $asset->path,
                $asset->url
            ),
            'mime_type' => $asset->mime_type,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => $asset->bytes,
            'alt' => $asset->alt,
            'caption' => $asset->caption,
            'credit' => $asset->credit,
            'status' => (string) $asset->status,
            'is_public' => (bool) $asset->is_public,
            'payload_json' => is_array($asset->payload_json) ? $asset->payload_json : [],
            'variants' => $asset->variants
                ->map(fn (MediaVariant $variant): array => $this->variantPayload($variant, (string) $asset->disk))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function variantPayload(MediaVariant $variant, ?string $disk): array
    {
        return [
            'variant_key' => (string) $variant->variant_key,
            'path' => $variant->path,
            'url' => PublicMediaUrlGuard::canonicalMediaUrl(
                $disk,
                $variant->path,
                $variant->url
            ),
            'mime_type' => $variant->mime_type,
            'width' => $variant->width,
            'height' => $variant->height,
            'bytes' => $variant->bytes,
            'payload_json' => is_array($variant->payload_json) ? $variant->payload_json : [],
        ];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
