<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

final class CareerJobDetailReaderSafeReviewProjector
{
    private ?string $contractSha256 = null;

    private const INTERNAL_READER_PAYLOAD_KEYS = [
        'source_id',
        'source_ids',
        'source_trace_id',
        'evidence_id',
        'row_hash',
        'search_projection',
        'audit_fields',
        'compile_refs',
        'crosswalk_ids',
        'import_run_id',
        'compile_run_id',
        'index_state_id',
    ];

    private const RAW_READER_PAYLOAD_VALUE_REPLACEMENTS = [
        'industry_proxy_or_recruitment_sample_only' => 'recruitment-market reference only',
        'industry_proxy / recruitment_sample' => 'recruitment-market reference',
        'candidate_only_not_runtime_seo' => 'candidate reference only',
        'source_bounded_reference_only' => 'source-bounded reference only',
        'backend projection review' => 'reader projection review',
        'search candidate only' => 'search reference candidate only',
        'salary_and_outlook' => 'salary and outlook context',
        'staging_preview_only' => 'preview only',
        'industry_proxy' => 'recruitment-market reference',
        'raw enum' => 'reader label',
    ];

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function project(array $payload): array
    {
        foreach (self::INTERNAL_READER_PAYLOAD_KEYS as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->project($value);
            } elseif (is_string($value)) {
                foreach (self::RAW_READER_PAYLOAD_VALUE_REPLACEMENTS as $rawValue => $readerSafeValue) {
                    $value = str_replace($rawValue, $readerSafeValue, $value);
                }
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    public function contractSha256(): string
    {
        if ($this->contractSha256 !== null) {
            return $this->contractSha256;
        }

        $path = app_path('Http/Controllers/API/V0_5/Career/CareerJobDetailController.php');
        $sha = is_file($path) ? hash_file('sha256', $path) : false;
        if (! is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new \RuntimeException('Career reader-safe projection contract is unavailable.');
        }

        return $this->contractSha256 = $sha;
    }
}
