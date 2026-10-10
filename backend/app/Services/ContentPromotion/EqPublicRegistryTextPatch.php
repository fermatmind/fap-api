<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** Applies only an already review-bound EQ-01 public text delta in memory. */
final class EqPublicRegistryTextPatch
{
    public function apply(array $content, array $candidate): array
    {
        $locale = data_get($candidate, 'identity.locale');
        if (($candidate['page_id'] ?? null) !== 'EQ-01'
            || data_get($candidate, 'identity.org_id') !== 0
            || data_get($candidate, 'identity.slug') !== 'eq-test-emotional-intelligence-assessment'
            || ! in_array($locale, ['en', 'zh-CN'], true)) {
            throw new DomainException('eq_registry_text_identity_invalid');
        }
        $key = $locale === 'en' ? 'en' : 'zh';
        $operations = $candidate['registry_operations'] ?? [];
        if (! is_array($operations) || ! array_is_list($operations) || $operations === []) {
            throw new DomainException('eq_registry_text_operations_invalid');
        }
        foreach ($operations as $operation) {
            $path = $operation['path'] ?? '';
            if (($operation['op'] ?? null) !== 'replace' || ! array_key_exists('value', $operation)
                || ! is_string($path) || ! str_starts_with($path, '/'.$key.'/')
                || preg_match('~^/(?:en|zh)/(?:why_choose/(?:intro|items/[0-3]/(?:body|link))|faq/(?:[0-9]|10)/(?:a|references(?:/[0-9]+)?|related_links(?:/[0-9]+/label)?))$~', $path) !== 1) {
                throw new DomainException('eq_registry_text_scope_invalid');
            }
            $segments = explode('/', substr($path, 1));
            $cursor = &$content;
            foreach ($segments as $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    throw new DomainException('eq_registry_text_prestate_missing');
                }
                $cursor = &$cursor[$segment];
            }
            $cursor = $operation['value'];
            unset($cursor);
        }

        return $content;
    }
}
