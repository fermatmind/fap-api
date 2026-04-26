<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

trait EnneagramAssetTestPaths
{
    private function batchAPath(): string
    {
        return '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_A_Assets_v6_final.json';
    }

    private function batchBPath(): string
    {
        return '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_Legacy_Core_Rewrite_v3_Assets.json';
    }

    private function batchCPath(): string
    {
        return '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling_Assets.json';
    }

    private function skipWhenAssetsMissing(): void
    {
        if (! is_file($this->batchAPath()) || ! is_file($this->batchBPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R asset files are not present on this workstation.');
        }
    }

    private function skipWhenBatchCMissing(): void
    {
        if (! is_file($this->batchCPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-C asset file is not present on this workstation.');
        }
    }
}
