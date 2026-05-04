<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Directory\OccupationDirectoryDisplayNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OccupationDirectoryDisplayNormalizerTest extends TestCase
{
    #[Test]
    public function english_title_translation_trims_connectors_without_corrupting_utf8(): void
    {
        $normalizer = new OccupationDirectoryDisplayNormalizer;

        $title = $normalizer->titleZh([
            'market' => 'US',
            'identity' => [
                'source_title_en' => 'Security Managers',
                'canonical_title_zh' => '经理',
            ],
        ]);

        $this->assertSame('安全经理', $title);
        $this->assertTrue(mb_check_encoding($title, 'UTF-8'));
    }
}
