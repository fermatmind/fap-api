<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;

final class MeasurementPrivacyScanner
{
    /** @var list<string> */
    private const REQUEST_HASH_PATHS = [
        'request_hash', 'run_id', 'authority_revision', 'evidence_bundle_refs.*.bundle_hash',
    ];

    /** @var list<string> */
    private const CONTEXT_HASH_PATHS = [
        'request_hash', 'context_hash', 'bundle_refs.*.bundle_hash',
        'source_capability.authority_revision', 'source_capability.source_ref',
        'source_capability.canonical_hash', 'measurement_state.authority_revision',
        'measurement_state.source_ref', 'measurement_state.canonical_hash',
        'metrics.revision_hash',
    ];

    /** @var list<string> */
    private const OUTPUT_HASH_PATHS = [
        'output_hash', 'findings.*.finding_hash', 'findings.*.evidence_refs.*',
        'findings.*.source_capability.authority_revision', 'findings.*.source_capability.source_ref',
        'findings.*.source_capability.canonical_hash', 'findings.*.measurement_state.authority_revision',
        'findings.*.measurement_state.source_ref', 'findings.*.measurement_state.canonical_hash',
        'findings.*.aggregate_metrics.revision_hash', 'candidates.*.candidate_hash',
    ];

    public function __construct(private readonly SeoPrivateDataScanner $scanner) {}

    /** @param array<string, mixed> $request */
    public function request(array $request): bool
    {
        return $this->containsPrivateData($request, self::REQUEST_HASH_PATHS);
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): bool
    {
        return $this->containsPrivateData($context, self::CONTEXT_HASH_PATHS);
    }

    /** @param array<string, mixed> $output */
    public function output(array $output): bool
    {
        return $this->containsPrivateData($output, self::OUTPUT_HASH_PATHS);
    }

    /** @param array<string, mixed> $value @param list<string> $hashPaths */
    private function containsPrivateData(array $value, array $hashPaths): bool
    {
        $hashValues = [];
        foreach ($hashPaths as $path) {
            $segments = explode('.', $path);
            $this->extractHashValues($value, $segments, $hashValues);
        }
        if ($this->scanner->scan($value)['private_data_present']) {
            return true;
        }
        foreach ($hashValues as $hashValue) {
            if ($this->scanner->scan(
                ['context_hash' => $hashValue],
                SeoPrivateDataScanner::MINIMIZED_PAYLOAD_HASH_PATHS,
            )['private_data_present']) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $segments @param list<mixed> $hashValues */
    private function extractHashValues(mixed &$value, array $segments, array &$hashValues): void
    {
        if (! is_array($value) || $segments === []) {
            return;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            foreach ($value as &$child) {
                if ($segments === []) {
                    $hashValues[] = $child;
                    $child = 'hash_removed';
                } else {
                    $this->extractHashValues($child, $segments, $hashValues);
                }
            }
            unset($child);

            return;
        }
        if (! array_key_exists($segment, $value)) {
            return;
        }
        if ($segments === []) {
            $hashValues[] = $value[$segment];
            $value[$segment] = 'hash_removed';

            return;
        }
        $this->extractHashValues($value[$segment], $segments, $hashValues);
    }
}
