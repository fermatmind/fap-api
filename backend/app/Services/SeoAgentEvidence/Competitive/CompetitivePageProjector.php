<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class CompetitivePageProjector
{
    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoPrivateDataScanner $privacy,
        private readonly ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string, mixed> $input @param array<string, mixed> $semantic @return array<string, mixed> */
    public function project(array $input, array $semantic): array
    {
        $body = (string) ($input['body'] ?? '');
        if (($this->injection->scan($body)['result'] ?? null) !== 'pass') {
            throw new InvalidArgumentException('COMPETITIVE_INJECTION_BLOCKED');
        }
        $dom = new DOMDocument;
        if (! @$dom->loadHTML($body, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            throw new InvalidArgumentException('COMPETITIVE_HTML_INVALID');
        }
        $xpath = new DOMXPath($dom);
        if ($this->captcha($xpath)) {
            throw new InvalidArgumentException('COMPETITIVE_CAPTCHA_BLOCKED');
        }
        if ($this->loginOrPaywall($xpath)) {
            throw new InvalidArgumentException('COMPETITIVE_LOGIN_OR_PAYWALL');
        }

        $structure = [
            'headings' => $this->headings($xpath),
            'modules' => $this->modules($xpath, $semantic),
            'schema_types' => $this->schemaTypes($xpath),
            'entity_ids' => [],
            'entity_relations' => [],
            'claim_signals' => [],
            'canonical_hash' => $this->canonicalHash($xpath),
            'hreflang' => $this->hreflang($xpath),
            'internal_link_patterns' => $this->internalLinks($xpath, (string) $input['public_url'], $semantic),
            'structure_fingerprint' => '',
        ];
        [$structure['entity_ids'], $structure['entity_relations'], $structure['claim_signals']] = $this->semanticSignals($xpath, $semantic);
        $structure['structure_fingerprint'] = $this->hasher->hash($structure);

        $projection = [
            'version' => 'seo.competitive_page_projection.v2',
            'source_id' => (string) $input['source_id'],
            'cohort_id' => (string) $input['cohort_id'],
            'source_class' => (string) $input['source_class'],
            'page_family' => (string) $input['page_family'],
            'locale' => (string) $input['locale'],
            'public_url_hash' => $this->hasher->hash((string) $input['public_url']),
            'source_policy_ref' => (array) $input['source_policy_ref'],
            'capture' => [
                'captured_at' => (string) $input['captured_at'],
                'response_hash' => $this->hasher->hash($body),
                'content_type' => 'text/html',
                'response_bytes' => strlen($body),
                'http_status' => 200,
                'robots_decision' => 'allowed',
                'terms_decision' => 'approved',
                'license_decision' => 'public_structure_permitted',
            ],
            'structure' => $structure,
            'redaction' => [
                'raw_html_retained' => false,
                'competitor_snippets_retained' => false,
                'private_data_present' => false,
                'login_or_paywall_detected' => false,
                'injection_scan_result' => 'pass',
            ],
        ];
        if (($this->privacy->scan($this->privacyPayload($projection))['decision'] ?? null) !== 'pass') {
            throw new InvalidArgumentException('COMPETITIVE_PRIVATE_DATA_BLOCKED');
        }
        $projection['projection_hash'] = $this->hasher->hash($projection);

        return $projection;
    }

    /** @return list<array{level:int,ordinal:int,label_hash:string}> */
    private function headings(DOMXPath $xpath): array
    {
        $result = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $ordinal => $node) {
            if (count($result) >= 64) {
                break;
            }
            $label = $this->normalize((string) $node->textContent);
            if ($label !== '') {
                $result[] = ['level' => (int) substr(strtolower($node->nodeName), 1), 'ordinal' => $ordinal, 'label_hash' => $this->hasher->hash($label)];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $semantic @return list<array{module_type:string,ordinal:int,module_hash:string}> */
    private function modules(DOMXPath $xpath, array $semantic): array
    {
        $mapping = (array) ($semantic['module_signals'] ?? []);
        $result = [];
        foreach ($xpath->query('//main/*|//article/*|//body/section') ?: [] as $node) {
            if (! $node instanceof DOMElement || count($result) >= 64) {
                continue;
            }
            $signal = $this->normalize(
                $node->tagName.' '.$node->getAttribute('id').' '.$node->getAttribute('class').' '
                .$node->getAttribute('data-module').' '.mb_substr((string) $node->textContent, 0, 8192),
            );
            $moduleType = 'other_registered';
            foreach ($mapping as $token => $registeredType) {
                if (str_contains($signal, strtolower((string) $token))) {
                    $moduleType = (string) $registeredType;
                    break;
                }
            }
            $ordinal = count($result);
            $result[] = ['module_type' => $moduleType, 'ordinal' => $ordinal, 'module_hash' => $this->hasher->hash([$moduleType, $ordinal])];
        }
        $pageText = $this->semanticPageText($xpath);
        $present = array_column($result, 'module_type');
        foreach ($mapping as $token => $registeredType) {
            $registeredType = (string) $registeredType;
            if (count($result) >= 64 || in_array($registeredType, $present, true)
                || ! str_contains($pageText, strtolower((string) $token))) {
                continue;
            }
            $ordinal = count($result);
            $result[] = ['module_type' => $registeredType, 'ordinal' => $ordinal, 'module_hash' => $this->hasher->hash([$registeredType, $ordinal])];
            $present[] = $registeredType;
        }
        if (str_contains($pageText, 'test') && in_array('assessment_entry', $mapping, true)
            && ! in_array('assessment_entry', $present, true) && count($result) < 64) {
            $ordinal = count($result);
            $result[] = ['module_type' => 'assessment_entry', 'ordinal' => $ordinal, 'module_hash' => $this->hasher->hash(['assessment_entry', $ordinal])];
        }

        return $result;
    }

    /** @return list<string> */
    private function schemaTypes(DOMXPath $xpath): array
    {
        $types = [];
        foreach ($this->jsonLd($xpath) as $node) {
            foreach ((array) ($node['@type'] ?? []) as $type) {
                if (is_string($type) && preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $type) === 1) {
                    $types[] = $type;
                }
            }
        }
        $types = array_values(array_unique($types));
        sort($types, SORT_STRING);

        return array_slice($types, 0, 32);
    }

    /** @param array<string, mixed> $semantic @return array{0:list<string>,1:list<array{entity_id:string,relation:string,target_id:string,relation_hash:string}>,2:list<array{claim_id:string,claim_hash:string}>} */
    private function semanticSignals(DOMXPath $xpath, array $semantic): array
    {
        $entities = [];
        $relations = [];
        $claims = [];
        $entitySignals = (array) ($semantic['entity_signals'] ?? []);
        $relationSignals = (array) ($semantic['relation_signals'] ?? []);
        $claimSignals = (array) ($semantic['claim_signals'] ?? []);
        $pageText = $this->semanticPageText($xpath);
        foreach ($entitySignals as $signal => $entityId) {
            if (str_contains($pageText, strtolower((string) $signal))) {
                $entities[] = (string) $entityId;
            }
        }
        foreach ($this->jsonLd($xpath) as $node) {
            $encoded = strtolower(json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            foreach ($entitySignals as $signal => $entityId) {
                if (str_contains($encoded, strtolower((string) $signal))) {
                    $entities[] = (string) $entityId;
                }
            }
            foreach ($relationSignals as $property => $relation) {
                if (array_key_exists((string) $property, $node) && $entities !== []) {
                    $entityId = end($entities);
                    $targetId = 'schema.'.strtolower((string) ((array) ($node['@type'] ?? ['thing']))[0]);
                    $value = ['entity_id' => $entityId, 'relation' => (string) $relation, 'target_id' => $targetId];
                    $value['relation_hash'] = $this->hasher->hash($value);
                    $relations[] = $value;
                }
            }
            foreach ($claimSignals as $property => $claimId) {
                if (array_key_exists((string) $property, $node)) {
                    $claims[] = ['claim_id' => (string) $claimId, 'claim_hash' => $this->hasher->hash([(string) $claimId, (string) $property])];
                }
            }
        }
        $entities = array_values(array_unique($entities));
        sort($entities, SORT_STRING);
        if ($entities !== [] && (str_contains($pageText, 'test') || str_contains($pageText, 'assessment'))) {
            foreach ($entities as $entityId) {
                $value = ['entity_id' => $entityId, 'relation' => 'measures', 'target_id' => 'schema.quiz'];
                $value['relation_hash'] = $this->hasher->hash($value);
                $relations[] = $value;
            }
        }
        $relations = $this->uniqueSorted($relations, 'relation_hash');
        $claims = $this->uniqueSorted($claims, 'claim_hash');

        return [array_slice($entities, 0, 128), array_slice($relations, 0, 128), array_slice($claims, 0, 64)];
    }

    private function canonicalHash(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]')->item(0);
        if (! $node instanceof DOMElement || ! str_starts_with($node->getAttribute('href'), 'https://')) {
            return null;
        }

        return $this->hasher->hash($node->getAttribute('href'));
    }

    /** @return list<array{locale:string,url_hash:string}> */
    private function hreflang(DOMXPath $xpath): array
    {
        $result = [];
        foreach ($xpath->query('//link[@rel="alternate"][@hreflang]') ?: [] as $node) {
            if (! $node instanceof DOMElement || ! str_starts_with($node->getAttribute('href'), 'https://')) {
                continue;
            }
            $locale = strtolower($node->getAttribute('hreflang'));
            $locale = $locale === 'zh' || str_starts_with($locale, 'zh-') ? 'zh-CN' : ($locale === 'en' || str_starts_with($locale, 'en-') ? 'en' : $locale);
            if (in_array($locale, ['en', 'zh-CN', 'x-default'], true)) {
                $result[] = ['locale' => $locale, 'url_hash' => $this->hasher->hash($node->getAttribute('href'))];
            }
        }

        return array_slice($this->uniqueSorted($result, 'url_hash'), 0, 16);
    }

    /** @param array<string, mixed> $semantic @return list<array{from_family:string,relation:string,to_family:string,count_bucket:string,pattern_hash:string}> */
    private function internalLinks(DOMXPath $xpath, string $publicUrl, array $semantic): array
    {
        $host = strtolower((string) parse_url($publicUrl, PHP_URL_HOST));
        $families = (array) ($semantic['path_families'] ?? []);
        $counts = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $href = $node->getAttribute('href');
            $parts = parse_url($href);
            if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
                continue;
            }
            $linkHost = strtolower((string) ($parts['host'] ?? $host));
            if ($linkHost !== $host) {
                continue;
            }
            $path = (string) ($parts['path'] ?? '/');
            $toFamily = 'other_public';
            foreach ($families as $prefix => $family) {
                if (str_starts_with($path, (string) $prefix)) {
                    $toFamily = (string) $family;
                    break;
                }
            }
            $key = 'related|'.$toFamily;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        $result = [];
        foreach ($counts as $key => $count) {
            [, $toFamily] = explode('|', $key, 2);
            $value = ['from_family' => 'tests', 'relation' => 'related', 'to_family' => $toFamily, 'count_bucket' => $this->bucket($count)];
            $value['pattern_hash'] = $this->hasher->hash($value);
            $result[] = $value;
        }

        return array_slice($result, 0, 64);
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(DOMXPath $xpath): array
    {
        $result = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode((string) $node->textContent, true);
            if (! is_array($decoded)) {
                continue;
            }
            $nodes = array_is_list($decoded) ? $decoded : (isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded]);
            foreach ($nodes as $value) {
                if (is_array($value)) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    private function loginOrPaywall(DOMXPath $xpath): bool
    {
        if (($xpath->query('//input[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="password"]')->length ?? 0) > 0
            || ($xpath->query('//*[@data-paywall or @data-metered]')->length ?? 0) > 0) {
            return true;
        }
        foreach ($this->jsonLd($xpath) as $node) {
            if (($node['isAccessibleForFree'] ?? null) === false || ($node['isAccessibleForFree'] ?? null) === 'false') {
                return true;
            }
        }

        return false;
    }

    private function captcha(DOMXPath $xpath): bool
    {
        return ($xpath->query('//*[contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"captcha") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"captcha") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"turnstile") or @data-sitekey]')->length ?? 0) > 0;
    }

    private function semanticPageText(DOMXPath $xpath): string
    {
        $parts = [];
        foreach ($xpath->query('//title|//meta[@name="description"]|//h1|//h2|//h3|//main|//article') ?: [] as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'meta') {
                $parts[] = $node->getAttribute('content');
            } else {
                $parts[] = (string) $node->textContent;
            }
            if (mb_strlen(implode(' ', $parts)) >= 32768) {
                break;
            }
        }

        return $this->normalize(mb_substr(implode(' ', $parts), 0, 32768));
    }

    /** @param list<array<string, mixed>> $values @return list<array<string, mixed>> */
    private function uniqueSorted(array $values, string $hashField): array
    {
        $indexed = [];
        foreach ($values as $value) {
            $indexed[(string) $value[$hashField]] = $value;
        }
        ksort($indexed, SORT_STRING);

        return array_values($indexed);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?: '', 'UTF-8');
    }

    private function bucket(int $count): string
    {
        return match (true) {
            $count <= 0 => '0', $count === 1 => '1', $count <= 3 => '2-3', $count <= 7 => '4-7', default => '8+',
        };
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function privacyPayload(array $value): array
    {
        foreach ($value as $key => $child) {
            if (str_ends_with((string) $key, '_hash')
                || in_array($key, ['captured_at', 'expires_at', 'private_data_present', 'injection_scan_result'], true)) {
                unset($value[$key]);

                continue;
            }
            if (is_array($child)) {
                $value[$key] = $this->privacyPayload($child);
            }
        }

        return $value;
    }
}
