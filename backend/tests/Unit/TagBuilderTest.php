<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\Report\TagBuilder;

class TagBuilderTest extends TestCase
{
    public function test_build_is_stable(): void
    {
        $b = app(TagBuilder::class);

        $scores = [
            'EI' => ['side' => 'E', 'delta' => 30, 'pct' => 80],
            'SN' => ['side' => 'S', 'delta' => 10, 'pct' => 55], // borderline
            'TF' => ['side' => 'T', 'delta' => 22, 'pct' => 71],
            'JP' => ['side' => 'J', 'delta' => 16, 'pct' => 62],
            'AT' => ['side' => 'A', 'delta' => 8,  'pct' => 52], // borderline
        ];

        $t1 = $b->build($scores, 'ESTJ-A');
        $t2 = $b->build($scores, 'ESTJ-A');

        $this->assertSame($t1, $t2);

        $this->assertContains('type:ESTJ-A', $t1);
        $this->assertContains('role:SJ', $t1);
        $this->assertContains('strategy:EA', $t1);

        $this->assertContains('axis:EI:E', $t1);
        $this->assertContains('axis:SN:S', $t1);
        $this->assertContains('axis:AT:A', $t1);

        $this->assertContains('borderline:SN', $t1);
        $this->assertContains('state:SN:borderline', $t1);
        $this->assertContains('borderline:AT', $t1);
        $this->assertContains('state:AT:borderline', $t1);

        $this->assertContains('state:EI:clear', $t1);
        $this->assertContains('state:TF:clear', $t1);
        $this->assertContains('state:JP:clear', $t1);
    }
}