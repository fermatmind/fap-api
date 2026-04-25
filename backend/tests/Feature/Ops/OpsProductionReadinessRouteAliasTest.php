<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Tests\TestCase;

final class OpsProductionReadinessRouteAliasTest extends TestCase
{
    public function test_legacy_ops_audit_paths_redirect_to_canonical_resources_and_preserve_locale(): void
    {
        $this->get('/ops/categories?locale=zh-CN')
            ->assertRedirect('/ops/article-categories?locale=zh-CN');

        $this->get('/ops/tags?locale=en')
            ->assertRedirect('/ops/article-tags?locale=en');

        $this->get('/ops/approvals?locale=zh-CN')
            ->assertRedirect('/ops/admin-approvals?locale=zh-CN');
    }
}
