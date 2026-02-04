<?php

namespace Tests\Feature;

use Tests\TestCase;

class ViewCompiledPathTest extends TestCase
{
    public function test_view_compiled_path_ready(): void
    {
        $path = (string) config('view.compiled');
        $this->assertNotSame('', $path);
        $this->assertTrue(is_dir($path), 'view.compiled directory missing: ' . $path);
    }
}
