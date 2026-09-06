<?php

declare(strict_types=1);

namespace App\Services\Ops;

final class PublicContentPublicationReadbackService
{
    /**
     * @return array{ok: bool, profile: string, fields: array<string, bool|int|string|null>, version_fingerprint: string|null}
     */
    public function extract(string $profile, string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return $this->failed($profile);
        }

        $fields = match ($profile) {
            'mbti_detail' => $this->mbtiFields($payload),
            'personality_asset_detail' => $this->personalityAssetFields($payload),
            'career_industries' => $this->careerIndustryFields($payload),
            default => [],
        };

        if ($fields === [] || in_array(null, $fields, true)) {
            return $this->failed($profile);
        }

        return [
            'ok' => true,
            'profile' => $profile,
            'fields' => $fields,
            'version_fingerprint' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, bool|int|string|null> */
    private function mbtiFields(array $payload): array
    {
        return [
            'schema_version' => $this->boundedString(data_get($payload, 'profile.schema_version')),
            'slug' => $this->boundedString(data_get($payload, 'profile.slug')),
            'locale' => $this->boundedString(data_get($payload, 'profile.locale')),
            'display_type' => $this->boundedString(data_get($payload, 'mbti_public_projection_v1.display_type')),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, bool|int|string|null> */
    private function personalityAssetFields(array $payload): array
    {
        return [
            'contract_version' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.contract_version',
            )),
            'launch_state' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.launch_state',
            )),
            'source_hash' => $this->boundedSha256(data_get(
                $payload,
                'personality_public_content_asset_v1.source_hash',
            )),
            'locale' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.locale',
            )),
            'canonical_path' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.canonical_path',
            )),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, bool|int|string|null> */
    private function careerIndustryFields(array $payload): array
    {
        return [
            'authority_version' => $this->boundedString($payload['authority_version'] ?? null),
            'bundle_version' => $this->boundedString($payload['bundle_version'] ?? null),
            'locale' => $this->boundedString($payload['locale'] ?? null),
            'public_detail_indexable_count' => $this->boundedInteger(
                $payload['public_detail_indexable_count'] ?? null,
            ),
            'industry_count' => $this->boundedInteger($payload['industry_count'] ?? null),
        ];
    }

    private function boundedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 160 ? $value : null;
    }

    private function boundedInteger(mixed $value): ?int
    {
        if (! is_int($value) || $value < 0 || $value > 1000000) {
            return null;
        }

        return $value;
    }

    private function boundedSha256(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    /** @return array{ok: false, profile: string, fields: array{}, version_fingerprint: null} */
    private function failed(string $profile): array
    {
        return [
            'ok' => false,
            'profile' => $profile,
            'fields' => [],
            'version_fingerprint' => null,
        ];
    }
}
