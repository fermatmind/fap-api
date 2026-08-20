<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use DateTimeImmutable;
use JsonException;

final class CareerEvidenceAuthorityLoader
{
    private const AUTHORITIES = ['occupation_fact', 'official_labor_market', 'market_sample', 'fermatmind_interpretation'];

    private const TRUST_CERTIFICATIONS = ['trusted_public_source', 'bounded_market_sample', 'bounded_interpretation'];

    private const CLAIM_KINDS = [
        'identity', 'duty', 'salary', 'growth', 'ai_exposure', 'ai_trend', 'tool',
        'qualification', 'licensing', 'market_signal', 'interpretation', 'work_context',
    ];

    private const CLAIM_MODES = ['fact', 'bounded_estimate', 'market_sample', 'interpretation_only'];

    /** @return array<string,mixed> */
    public function load(string $root, string $slug, array $blocks): array
    {
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved) || is_link($root)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        $manifest = $this->json($resolved.'/manifest.json');
        if (($manifest['contract_version'] ?? null) !== 'career.evidence.authority.manifest.v1'
            || ($manifest['evaluation_date'] ?? null) === null) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        $evaluationDate = $this->date((string) $manifest['evaluation_date']);
        $reviewedAt = $this->date((string) ($manifest['reviewed_at'] ?? ''));
        if ($reviewedAt > $evaluationDate) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_REVIEW_DATE_INVALID');
        }
        $files = [
            'source_registry' => 'source-registry.jsonl',
            'claim_bindings' => 'claim-bindings.jsonl',
            'schema_profile_manifest' => 'schema-profile-manifest.json',
        ];
        foreach ($files as $key => $name) {
            if (($manifest['files'][$key]['path'] ?? null) !== $name
                || ! hash_equals((string) ($manifest['files'][$key]['sha256'] ?? ''), hash_file('sha256', $resolved.'/'.$name) ?: '')) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_DIGEST_MISMATCH');
            }
        }

        $sources = $this->sources($resolved.'/'.$files['source_registry']);
        $claims = $this->claims($resolved.'/'.$files['claim_bindings'], $slug, $sources, $blocks, $evaluationDate);
        $profiles = $this->json($resolved.'/'.$files['schema_profile_manifest']);
        $profile = $profiles['profiles'][$slug] ?? null;
        if (($profiles['contract_version'] ?? null) !== 'career.evidence.schema_profile_manifest.v1'
            || ! is_array($profile)
            || ! is_array($profile['required_claim_keys'] ?? null)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_PROFILE_MISSING');
        }
        $claimsByKey = [];
        foreach ($claims as $claim) {
            $claimsByKey[$claim['claim_key']] = $claim;
        }
        $blockers = [];
        foreach ($profile['required_claim_keys'] as $claimKey) {
            if (! is_string($claimKey) || ! isset($claimsByKey[$claimKey])) {
                $blockers[] = ['code' => 'TEN_BLOCK_REQUIRED_CLAIM_MISSING', 'field' => (string) $claimKey];
            }
        }
        if ($blockers !== []) {
            return ['digest' => $this->digest($manifest), 'blockers' => $blockers];
        }

        $activeSources = [];
        foreach ($claims as $claim) {
            foreach ($claim['source_keys'] as $sourceKey) {
                $activeSources[$sourceKey] = $sources[$sourceKey];
            }
        }
        ksort($activeSources, SORT_STRING);
        $nextReviewDue = null;
        foreach ($claims as $claim) {
            $expiry = (string) $claim['expires_at'];
            $nextReviewDue = $nextReviewDue === null || $expiry < $nextReviewDue ? $expiry : $nextReviewDue;
        }

        return [
            'digest' => $this->digest($manifest),
            'blockers' => [],
            'sources' => array_values($activeSources),
            'claims' => $claims,
            'review_validity' => [
                'last_reviewed' => $reviewedAt->format('Y-m-d'),
                'next_review_due' => $nextReviewDue,
                'market_signal_expiry' => null,
            ],
            'claim_permissions' => $this->permissions($claims),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function sources(string $path): array
    {
        $rows = $this->jsonl($path);
        $sources = [];
        foreach ($rows as $row) {
            $key = $row['source_key'] ?? null;
            $authority = $row['authority'] ?? null;
            $certification = $row['trust_certification'] ?? null;
            if (($row['contract_version'] ?? null) !== 'career.source_registry.v1'
                || ! is_string($key) || isset($sources[$key])
                || ! in_array($authority, self::AUTHORITIES, true)
                || ! in_array($certification, self::TRUST_CERTIFICATIONS, true)
                || ! $this->certificationMatchesAuthority((string) $authority, (string) $certification)
                || ! is_string($row['publisher'] ?? null) || ! is_string($row['title'] ?? null)
                || ! is_string($row['url'] ?? null) || ! $this->safeHttps((string) $row['url'])
                || ! is_string($row['market'] ?? null) || ! is_string($row['locale'] ?? null)
                || ! is_array($row['claim_kinds'] ?? null) || ! is_string($row['captured_at'] ?? null)
                || ! is_string($row['effective_period'] ?? null) || ! is_string($row['evidence_digest'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $row['evidence_digest']) !== 1
                || ! is_string($row['confidence_method'] ?? null) || ! is_string($row['usage'] ?? null)
                || (! is_string($row['expires_at'] ?? null) && ! is_string($row['non_expiring_reason'] ?? null))) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_SOURCE_REGISTRY_INVALID');
            }
            foreach ($row['claim_kinds'] as $claimKind) {
                if (! in_array($claimKind, self::CLAIM_KINDS, true)) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_SOURCE_REGISTRY_INVALID');
                }
            }
            $sources[$key] = $row;
        }

        return $sources;
    }

    /** @param array<string,array<string,mixed>> $sources @return list<array<string,mixed>> */
    private function claims(string $path, string $slug, array $sources, array $blocks, DateTimeImmutable $evaluationDate): array
    {
        $rows = $this->jsonl($path);
        $claims = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['claim_key'] ?? null;
            if (($row['contract_version'] ?? null) !== 'career.claim_binding.v1'
                || ! is_string($key) || isset($seen[$key])
                || ($row['canonical_slug'] ?? null) !== $slug
                || ! in_array($row['locale'] ?? null, ['en', 'zh'], true)
                || ! is_string($row['market'] ?? null)
                || ! in_array($row['claim_kind'] ?? null, self::CLAIM_KINDS, true)
                || ! is_string($row['input_jsonpath'] ?? null)
                || ! is_string($row['normalized_value_digest'] ?? null)
                || ! is_string($row['component_id'] ?? null)
                || ! is_string($row['authority_output_jsonpath'] ?? null)
                || ! is_array($row['source_keys'] ?? null) || $row['source_keys'] === []
                || ! is_string($row['evidence_basis'] ?? null) || ! is_string($row['confidence'] ?? null)
                || ! is_string($row['captured_at'] ?? null) || ! is_string($row['effective_period'] ?? null)
                || ! is_string($row['expires_at'] ?? null)
                || ! is_bool($row['proxy'] ?? null) || ! in_array($row['claim_mode'] ?? null, self::CLAIM_MODES, true)
                || ($row['review_status'] ?? null) !== 'approved'
                || ! is_array($row['blocker_codes'] ?? null) || $row['blocker_codes'] !== []) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_CLAIM_BINDING_INVALID');
            }
            if (($row['proxy'] ?? false) === true && ! is_string($row['proxy_boundary'] ?? null)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_PROXY_BOUNDARY_MISSING');
            }
            $value = $this->inputValue($blocks, (string) $row['input_jsonpath']);
            if (! hash_equals((string) $row['normalized_value_digest'], CareerCurrentAuthorityPackage::hashValue($value))) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_CLAIM_VALUE_MISMATCH');
            }
            $claimExpiry = $this->date((string) $row['expires_at']);
            if ($claimExpiry < $evaluationDate) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_CLAIM_EXPIRED');
            }
            foreach ($row['source_keys'] as $sourceKey) {
                $source = is_string($sourceKey) ? ($sources[$sourceKey] ?? null) : null;
                if (! is_array($source)
                    || ($source['market'] ?? null) !== $row['market']
                    || ($source['locale'] ?? null) !== $row['locale']
                    || ! in_array($row['claim_kind'], $source['claim_kinds'], true)
                    || ($source['captured_at'] ?? null) !== $row['captured_at']
                    || ($source['effective_period'] ?? null) !== $row['effective_period']
                    || (isset($source['expires_at']) && $this->date((string) $source['expires_at']) < $claimExpiry)) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_CLAIM_SOURCE_MISMATCH');
                }
            }
            $seen[$key] = true;
            $claims[] = $row;
        }
        usort($claims, static fn (array $a, array $b): int => strcmp($a['claim_key'], $b['claim_key']));

        return $claims;
    }

    /** @param list<array<string,mixed>> $claims @return array<string,mixed> */
    private function permissions(array $claims): array
    {
        $kinds = array_fill_keys(array_column($claims, 'claim_kind'), true);
        $strongKinds = [];
        foreach ($claims as $claim) {
            if (($claim['claim_mode'] ?? null) === 'fact' && ($claim['proxy'] ?? true) === false) {
                $strongKinds[$claim['claim_kind']] = true;
            }
        }
        $strong = isset($strongKinds['identity'], $strongKinds['duty'], $strongKinds['work_context']);
        $ai = isset($kinds['ai_exposure'], $kinds['ai_trend']);
        $salary = isset($kinds['salary']) && ! in_array(true, array_column($claims, 'proxy'), true);
        $market = isset($kinds['market_signal']);

        return [
            'integrity_state' => $strong ? 'provisional' : 'restricted',
            'allow_strong_claim' => $strong,
            'allow_ai_strategy' => $ai,
            'allow_salary_comparison' => $salary,
            'allow_market_signal' => $market,
            'allow_local_proxy_wage' => false,
            'blocked_claims' => array_values(array_filter([
                $ai ? null : 'ai_strategy_missing_claim_evidence',
                $salary ? null : 'salary_comparison_missing_claim_evidence',
                $market ? null : 'market_signal_missing_claim_evidence',
                'local_proxy_wage_not_direct_fact',
            ])),
            'warnings' => ['candidate_claims_without_exact_evidence_are_omitted'],
            'evidence_basis' => [
                'occupation' => $strong ? 'official' : 'missing',
                'ai_exposure' => $ai ? 'claim_binding' : 'missing',
                'salary' => $salary ? 'official' : 'missing',
                'market_signal' => $market ? 'claim_binding' : 'missing',
            ],
        ];
    }

    private function inputValue(array $blocks, string $path): mixed
    {
        if (preg_match('/\A\$\.([a-z-]+)((?:\.[a-z0-9_]+)+)\z/', $path, $matches) !== 1) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_INPUT_JSONPATH_INVALID');
        }
        $value = $blocks[$matches[1].'.json'] ?? null;
        foreach (array_filter(explode('.', $matches[2])) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INPUT_JSONPATH_INVALID');
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function jsonl(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
            }
            if (! is_array($row) || array_is_list($row)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_INVALID');
        }

        return $value;
    }

    private function safeHttps(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && ! in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    private function certificationMatchesAuthority(string $authority, string $certification): bool
    {
        return match ($authority) {
            'occupation_fact', 'official_labor_market' => $certification === 'trusted_public_source',
            'market_sample' => $certification === 'bounded_market_sample',
            'fermatmind_interpretation' => $certification === 'bounded_interpretation',
            default => false,
        };
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_DATE_INVALID');
        }

        return $date;
    }

    private function digest(array $manifest): string
    {
        return CareerCurrentAuthorityPackage::hashValue($manifest['files']);
    }
}
