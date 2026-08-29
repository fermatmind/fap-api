<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

use Normalizer;

final class SeoQueryHmac
{
    public const NORMALIZATION_VERSION = 'nfkc-casefold-v1';

    /** @return array<string, mixed> */
    public function identify(string $query): array
    {
        $key = config('seo_agent_evidence.query_hmac_key');
        $version = config('seo_agent_evidence.query_hmac_key_version');
        if (! is_string($key) || strlen($key) < 32 || ! is_string($version)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $version) !== 1) {
            return [
                'status' => 'SOURCE_CAPABILITY_UNAVAILABLE',
                'query_hmac' => null,
                'query_hmac_key_version' => null,
                'normalization_version' => self::NORMALIZATION_VERSION,
                'private_data_present' => true,
            ];
        }

        $normalized = $this->normalize($query);
        if ($normalized === null) {
            return [
                'status' => 'SOURCE_CAPABILITY_UNAVAILABLE',
                'query_hmac' => null,
                'query_hmac_key_version' => $version,
                'normalization_version' => self::NORMALIZATION_VERSION,
                'private_data_present' => true,
            ];
        }

        return [
            'status' => 'available',
            'query_hmac' => hash_hmac('sha256', "fermatmind:seo-evidence:query:v1\0".$normalized, $key),
            'query_hmac_key_version' => $version,
            'normalization_version' => self::NORMALIZATION_VERSION,
            'private_data_present' => false,
        ];
    }

    private function normalize(string $query): ?string
    {
        if (! mb_check_encoding($query, 'UTF-8')) {
            return null;
        }
        if (! class_exists(Normalizer::class)) {
            return null;
        }
        $normalized = Normalizer::normalize($query, Normalizer::FORM_KC);
        if (! is_string($normalized)) {
            return null;
        }
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        $normalized = mb_strtolower((string) $normalized, 'UTF-8');

        return $normalized === '' || mb_strlen($normalized, 'UTF-8') > 512 ? null : $normalized;
    }
}
