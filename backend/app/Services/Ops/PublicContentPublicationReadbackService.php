<?php

declare(strict_types=1);

namespace App\Services\Ops;

final class PublicContentPublicationReadbackService
{
    /** Current pages have package provenance, not CMS publication timestamps. */
    public function extractCurrent(string $profile, string $body, string $aggregate, array $expected, string $expectedAggregate): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || ($payload['ok'] ?? null) !== true
            || preg_match('/^[a-f0-9]{64}$/D', $aggregate) !== 1
            || ! hash_equals($expectedAggregate, $aggregate)) {
            return $this->failed($profile);
        }
        $fields = $this->currentFields($profile, $payload);
        if ($fields === [] || in_array(null, $fields, true)
            || $fields !== $this->currentFields($profile, $expected)) {
            return $this->failed($profile);
        }
        $fields = ['authority_contract' => 'personality.page.content.v1', 'aggregate_sha256' => $aggregate, ...$fields];

        return ['ok' => true, 'profile' => $profile, 'fields' => $fields,
            'version_fingerprint' => hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR))];
    }

    private function currentFields(string $profile, array $payload): array
    {
        $paths = match ($profile) {
            'mbti_detail' => ['display_type' => 'mbti_public_projection_v1.display_type',
                'type_code' => 'profile.type_code', 'locale' => 'profile.locale',
                'schema_version' => 'profile.schema_version', 'status' => 'profile.status'],
            'personality_asset_detail' => ['contract_version' => 'personality_public_content_asset_v1.contract_version',
                'framework' => 'personality_public_content_asset_v1.framework',
                'entity_type' => 'personality_public_content_asset_v1.entity_type',
                'code' => 'personality_public_content_asset_v1.code',
                'locale' => 'personality_public_content_asset_v1.locale',
                'launch_state' => 'personality_public_content_asset_v1.launch_state',
                'source_hash' => 'personality_public_content_asset_v1.source_hash'],
            default => [],
        };
        if ($paths === []) {
            return [];
        }
        $fields = [];
        foreach ($paths as $key => $path) {
            $fields[$key] = $this->boundedString(data_get($payload, $path));
        }
        $publicPath = $profile === 'mbti_detail' ? 'profile.is_public' : 'personality_public_content_asset_v1.is_public';
        if (data_get($payload, $publicPath) !== true
            || (isset($fields['source_hash']) && preg_match('/^[a-f0-9]{64}$/D', $fields['source_hash']) !== 1)) {
            return [];
        }

        return $fields;
    }

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
            'display_type' => $this->boundedString(data_get($payload, 'mbti_public_projection_v1.display_type')),
            'published_at' => $this->boundedString(data_get($payload, 'profile.published_at')),
            'updated_at' => $this->boundedString(data_get($payload, 'profile.updated_at')),
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
            'review_state' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.review_state',
            )),
            'published_at' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.published_at',
            )),
            'updated_at' => $this->boundedString(data_get(
                $payload,
                'personality_public_content_asset_v1.updated_at',
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
