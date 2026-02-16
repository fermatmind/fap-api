<?php

declare(strict_types=1);

namespace Tests\Unit\Template;

use App\Services\Template\TemplateContext;
use App\Services\Template\TemplateEngine;
use App\Services\Template\TemplateVariableRegistry;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    private function engine(): TemplateEngine
    {
        return new TemplateEngine(new TemplateVariableRegistry());
    }

    public function test_unknown_variable_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown template variable');

        $this->engine()->renderString(
            'Hello {{unknown_variable}}',
            TemplateContext::fromArray(['type_code' => 'INTJ'])
        );
    }

    public function test_missing_variable_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing template variables');

        $this->engine()->renderString(
            'Hello {{type_code}} {{score_axis_ei}}',
            TemplateContext::fromArray(['type_code' => 'INTJ'])
        );
    }

    public function test_default_text_mode_escapes_output(): void
    {
        $rendered = $this->engine()->renderString(
            'Type: {{type_name}}',
            TemplateContext::fromArray([
                'type_name' => '<b>INTJ</b>',
            ])
        );

        $this->assertSame('Type: &lt;b&gt;INTJ&lt;/b&gt;', $rendered);
    }
}
