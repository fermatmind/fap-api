<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use App\Models\ContentMaterialDecision;
use App\Services\SeoIntel\SearchChannelQueue\ArticleIndexNowChangeGate;
use PHPUnit\Framework\TestCase;

final class ArticleIndexNowChangeGateTest extends TestCase
{
    public function test_initial_publish_and_search_surface_change_require_notification(): void
    {
        $gate = new ArticleIndexNowChangeGate;
        $initial = $this->decision(str_repeat('a', 64));
        self::assertSame('initial_publish', $gate->reason($initial, null));
        self::assertTrue($gate->shouldNotify($gate->reason($initial, null)));

        $changed = $this->decision(str_repeat('b', 64));
        self::assertSame('search_surface_changed', $gate->reason($changed, $initial));
        self::assertTrue($gate->shouldNotify($gate->reason($changed, $initial)));
    }

    public function test_body_only_change_and_unknown_legacy_search_surface_are_held(): void
    {
        $gate = new ArticleIndexNowChangeGate;
        $previous = $this->decision(str_repeat('a', 64));
        $bodyOnly = $this->decision(str_repeat('a', 64));
        self::assertSame('search_surface_unchanged', $gate->reason($bodyOnly, $previous));
        self::assertFalse($gate->shouldNotify($gate->reason($bodyOnly, $previous)));

        $legacy = $this->decision(null);
        self::assertSame('previous_search_surface_unknown', $gate->reason($bodyOnly, $legacy));
        self::assertFalse($gate->shouldNotify($gate->reason($bodyOnly, $legacy)));
    }

    public function test_slug_change_and_republish_after_unpublish_require_notification(): void
    {
        $gate = new ArticleIndexNowChangeGate;
        $previous = $this->decision(str_repeat('a', 64));
        $slugChanged = $this->decision(str_repeat('b', 64), '/zh-CN/articles/changed');
        self::assertSame('public_identity_changed', $gate->reason($slugChanged, $previous));

        $unpublished = $this->decision(null);
        $unpublished->publication_state = 'unpublished';
        self::assertSame('republish_after_unpublish', $gate->reason($slugChanged, $unpublished));
    }

    private function decision(?string $searchFingerprint, string $identity = '/zh-CN/articles/example'): ContentMaterialDecision
    {
        $decision = new ContentMaterialDecision;
        $decision->forceFill([
            'org_id' => 0,
            'family' => 'article',
            'locale' => 'zh-CN',
            'authority_subject_key' => 'article:37',
            'public_identity' => $identity,
            'publication_state' => 'published',
            'search_surface_fingerprint' => $searchFingerprint,
        ]);

        return $decision;
    }
}
