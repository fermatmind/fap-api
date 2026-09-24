<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use App\Models\ContentMaterialDecision;

final class ArticleIndexNowChangeGate
{
    public function reason(ContentMaterialDecision $current, ?ContentMaterialDecision $previous): string
    {
        if ((string) $current->family !== 'article'
            || (string) $current->publication_state !== 'published'
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $current->search_surface_fingerprint) !== 1) {
            return 'current_search_surface_unknown';
        }

        if ($previous === null) {
            return 'initial_publish';
        }

        if ((string) $previous->authority_subject_key !== (string) $current->authority_subject_key
            || (int) $previous->org_id !== (int) $current->org_id
            || (string) $previous->locale !== (string) $current->locale) {
            return 'lineage_mismatch';
        }

        if ((string) $previous->publication_state === 'unpublished') {
            return 'republish_after_unpublish';
        }

        if ((string) $previous->publication_state !== 'published') {
            return 'previous_publication_state_unknown';
        }

        if ((string) $previous->public_identity !== (string) $current->public_identity) {
            return 'public_identity_changed';
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', (string) $previous->search_surface_fingerprint) !== 1) {
            return 'previous_search_surface_unknown';
        }

        return hash_equals((string) $previous->search_surface_fingerprint, (string) $current->search_surface_fingerprint)
            ? 'search_surface_unchanged'
            : 'search_surface_changed';
    }

    public function shouldNotify(string $reason): bool
    {
        return in_array($reason, [
            'initial_publish',
            'republish_after_unpublish',
            'public_identity_changed',
            'search_surface_changed',
        ], true);
    }
}
