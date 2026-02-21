<?php

declare(strict_types=1);

namespace Tests\Unit\Template;

use App\Services\Template\TemplateContext;
use App\Services\Template\TemplateEngine;
use App\Services\Template\TemplateVariableRegistry;
use Tests\TestCase;

final class BigFiveTemplateWhitelistTest extends TestCase
{
    public function test_unknown_variable_fails_lint(): void
    {
        $engine = new TemplateEngine(new TemplateVariableRegistry());
        $lint = $engine->lintString('Hello {{unknown.field}}', TemplateContext::fromArray([]));

        $this->assertContains('unknown.field', (array) ($lint['unknown'] ?? []));
    }

    public function test_big5_allowlist_variable_can_render(): void
    {
        $engine = new TemplateEngine(new TemplateVariableRegistry());
        $context = TemplateContext::fromArray([
            'quality' => [
                'level' => 'A',
            ],
            'norms' => [
                'status' => 'PROVISIONAL',
            ],
        ]);

        $rendered = $engine->renderString('Q={{quality.level}},N={{norms.status}}', $context);
        $this->assertSame('Q=A,N=PROVISIONAL', $rendered);
    }
}
