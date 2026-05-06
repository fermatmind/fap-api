<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\Cms;

use App\Filament\Ops\Support\BigFiveV2EditorialAssetIndexPresenter;
use App\Services\BigFive\Cms\BigFiveV2EditorialAssetIndex;
use Tests\TestCase;

final class BigFiveV2EditorialAssetIndexTest extends TestCase
{
    public function test_index_lists_big_five_v2_assets_as_read_only(): void
    {
        $index = app(BigFiveV2EditorialAssetIndex::class);
        $rows = $index->rows();

        $this->assertNotEmpty($rows);
        $this->assertContains(
            'content_assets/big5/result_page_v2/governance/production_policy_v0_1/manifest.json',
            array_column($rows, 'relative_path')
        );

        foreach ($rows as $row) {
            $this->assertTrue((bool) $row['read_only'], (string) $row['relative_path']);
            $this->assertFalse((bool) $row['runtime_mutation_allowed'], (string) $row['relative_path']);
            $this->assertFalse((bool) $row['publish_action_allowed'], (string) $row['relative_path']);
            $this->assertFalse((bool) $row['production_rollout_enabled'], (string) $row['relative_path']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $row['sha256']);
        }
    }

    public function test_index_exposes_release_snapshot_linkage_without_mutating_release_files(): void
    {
        $index = app(BigFiveV2EditorialAssetIndex::class);
        $rowsByPath = [];
        foreach ($index->rows() as $row) {
            $rowsByPath[(string) $row['relative_path']] = $row;
        }

        $snapshot = $rowsByPath['content_assets/big5/result_page_v2/releases/v0_1/big5_v2_release_snapshot_rc_0_1.json'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertTrue((bool) $snapshot['immutable']);
        $this->assertFalse((bool) $snapshot['production_use_allowed']);
        $this->assertFalse((bool) $snapshot['ready_for_production']);

        $linkedPolicy = $rowsByPath['content_assets/big5/result_page_v2/governance/production_policy_v0_1/manifest.json'] ?? null;
        $this->assertIsArray($linkedPolicy);
        $this->assertSame(['big5_result_page_v2_rc_0_1'], $linkedPolicy['linked_release_snapshot_ids']);
    }

    public function test_filament_presenter_has_no_mutating_actions(): void
    {
        $presenter = app(BigFiveV2EditorialAssetIndexPresenter::class);

        $this->assertSame([], $presenter->availableActions());
        $this->assertTrue((bool) $presenter->summary()['read_only']);
        $this->assertFalse((bool) $presenter->summary()['runtime_mutation_allowed']);
        $this->assertFalse((bool) $presenter->summary()['publish_action_allowed']);
        $this->assertNotEmpty($presenter->tableRows());
    }
}
