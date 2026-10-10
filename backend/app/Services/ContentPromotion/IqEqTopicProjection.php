<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/** Computes the fixed SH-01 copy delta without changing live publication state. */
final class IqEqTopicProjection
{
    public function candidate(array $row, array $before): array
    {
        $profile = $before['profile'] ?? [];
        foreach ($row['identity'] as $key => $value) {
            if (($profile[$key] ?? null) !== $value) {
                throw new DomainException('iq_eq_topic_profile_identity_invalid');
            }
        }
        if (($profile['topic_code'] ?? null) !== 'iq-eq'
            || ($profile['status'] ?? null) !== 'published' || ($profile['is_public'] ?? null) !== true
            || $this->dateBlocksPublication($profile['published_at'] ?? null)
            || $this->dateBlocksPublication($profile['scheduled_at'] ?? null)) {
            throw new DomainException('iq_eq_topic_publication_hold');
        }
        if (! is_array($before['seo'] ?? null) || ($before['seo']['profile_id'] ?? null) !== $profile['id']) {
            throw new DomainException('iq_eq_topic_seo_identity_invalid');
        }
        $after = $before;
        $after['profile'] = array_replace($profile, $row['snapshot']['profile_patch']);
        foreach ($row['snapshot']['section_candidates'] as $candidate) {
            $matches = array_keys(array_filter($after['sections'], static fn (array $section): bool => ($section['section_key'] ?? null) === $candidate['section_key']));
            if (count($matches) > 1 || ($candidate['section_key'] === 'overview' && count($matches) !== 1)) {
                throw new DomainException('iq_eq_topic_section_identity_invalid');
            }
            if ($matches === []) {
                $after['sections'][] = [
                    'id' => null, 'profile_id' => $profile['id'], 'section_key' => 'faq',
                    'title' => null, 'render_variant' => 'faq', 'body_md' => null, 'body_html' => null,
                    'payload_json' => $candidate['payload_json'], 'is_enabled' => true,
                    'sort_order' => max(array_column($after['sections'], 'sort_order')) + 10,
                ];

                continue;
            }
            $index = $matches[0];
            $section = $after['sections'][$index];
            $ownedPlaceholder = ($before['owned_faq_placeholder_id'] ?? null) === ($section['id'] ?? null)
                && $candidate['section_key'] === 'faq' && ($section['is_enabled'] ?? null) === false
                && empty($section['body_md']) && empty($section['body_html']) && empty($section['payload_json']);
            if (($section['profile_id'] ?? null) !== $profile['id'] || (($section['is_enabled'] ?? null) !== true && ! $ownedPlaceholder)) {
                throw new DomainException('iq_eq_topic_section_hold');
            }
            $after['sections'][$index] = array_replace($section, [
                'render_variant' => $candidate['render_variant'], 'body_md' => $candidate['body_md'] ?? null,
                'body_html' => null, 'payload_json' => $candidate['payload_json'] ?? null,
                ...($ownedPlaceholder ? ['is_enabled' => true] : []),
            ]);
        }
        foreach ($row['snapshot']['entry_excerpt_overrides'] as $candidate) {
            $matches = array_keys(array_filter($after['entries'], static fn (array $entry): bool => ($entry['entry_type'] ?? null) === $candidate['entry_type']
                && ($entry['group_key'] ?? null) === $candidate['group_key'] && ($entry['target_key'] ?? null) === $candidate['target_key']));
            if (count($matches) !== 1) {
                throw new DomainException('iq_eq_topic_entry_identity_invalid');
            }
            $index = $matches[0];
            $entry = $after['entries'][$index];
            if (($entry['profile_id'] ?? null) !== $profile['id'] || ($entry['is_enabled'] ?? null) !== true
                || ! in_array($entry['target_locale'] ?? null, [null, '', $row['identity']['locale']], true)
                || ! in_array($entry['target_url_override'] ?? null, [null, '', $candidate['expected_url']], true)) {
                throw new DomainException('iq_eq_topic_entry_hold_or_target_drift');
            }
            $after['entries'][$index]['excerpt_override'] = $candidate['excerpt_override'];
        }
        $text = $row['snapshot']['seo_text_candidate'];
        foreach (['seo', 'og', 'twitter'] as $transport) {
            $after['seo'][$transport.'_title'] = $text['title'];
            $after['seo'][$transport.'_description'] = $text['description'];
        }
        // Existing root name/description are copy transports. Preserve all URL,
        // image, qualification and unrelated structured-data authority.
        foreach (['name' => 'title', 'description' => 'description'] as $key => $source) {
            if (is_array($after['seo']['jsonld_overrides_json'] ?? null)
                && array_key_exists($key, $after['seo']['jsonld_overrides_json'])) {
                $after['seo']['jsonld_overrides_json'][$key] = $text[$source];
            }
        }

        return $after;
    }

    private function dateBlocksPublication(mixed $value): bool
    {
        // Public Topic readers treat NULL as an immediate published state.
        // A supplied date must still be valid and must not be in the future.
        if ($value === null) {
            return false;
        }
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})?)?\z/', $value) !== 1) {
            return true;
        }
        $parsed = date_parse($value);
        if ($parsed['warning_count'] !== 0 || $parsed['error_count'] !== 0) {
            return true;
        }
        $timestamp = strtotime($value);

        return $timestamp === false || $timestamp > time();
    }
}
