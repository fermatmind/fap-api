<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\FirstWaveAliasCatalogReader;
use Tests\TestCase;

final class FirstWaveAliasCatalogReaderTest extends TestCase
{
    public function test_it_reads_an_alias_catalog_aligned_to_the_first_wave_manifest(): void
    {
        $catalog = app(FirstWaveAliasCatalogReader::class)->read();

        $this->assertSame('first_wave_aliases_v1', $catalog['version']);
        $this->assertSame(10, $catalog['count_expected']);
        $this->assertSame(10, $catalog['count_actual']);
        $this->assertCount(10, $catalog['items']);
    }

    public function test_it_keeps_missing_first_wave_alias_entries_explicitly_empty(): void
    {
        $catalog = app(FirstWaveAliasCatalogReader::class)->bySlug();

        $this->assertSame([], $catalog['human-resources-specialists']['approved_alias_rows']);
        $this->assertSame([], $catalog['marketing-managers']['approved_alias_rows']);
        $this->assertSame([], $catalog['registered-nurses']['approved_alias_rows']);
        $this->assertSame([], $catalog['elementary-school-teachers-except-special-education']['approved_alias_rows']);
    }
}
