<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

trait EnneagramAssetTestPaths
{
    private function batchAPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_A_Assets_v6_final.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_A_Assets_v6_final.json',
        );
    }

    private function batchBPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_Legacy_Core_Rewrite_v3_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_v3/FermatMind_Enneagram_Content_Expansion_Batch_1R_B_Legacy_Core_Rewrite_v3_Assets.json',
        );
    }

    private function batchCPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling/FermatMind_Enneagram_Content_Expansion_Batch_1R_C_Low_Resonance_Objection_Handling_Assets.json',
        );
    }

    private function batchDPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch/FermatMind_Enneagram_Content_Expansion_Batch_1R_D_Partial_Resonance_Deep_Branch_Assets.json',
        );
    }

    private function batchEPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence/FermatMind_Enneagram_Content_Expansion_Batch_1R_E_Diffuse_Top3_Convergence_Assets.json',
        );
    }

    private function batchFPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion/FermatMind_Enneagram_Content_Expansion_Batch_1R_F_Close_Call_36_Pair_Completion_Assets.json',
        );
    }

    private function batchGPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization/FermatMind_Enneagram_Content_Expansion_Batch_1R_G_Scene_Localization_Assets.json',
        );
    }

    private function batchHPath(): string
    {
        return $this->resolveExternalAssetPath(
            '/Users/rainie/Desktop/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
            '/Users/rainie/Desktop/九型/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
            '/mnt/data/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack/FermatMind_Enneagram_Content_Expansion_Batch_1R_H_FC144_Recommendation_Pack_Assets.json',
        );
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

    private function skipWhenBatchDMissing(): void
    {
        if (! is_file($this->batchDPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-D asset file is not present on this workstation.');
        }
    }

    private function skipWhenBatchEMissing(): void
    {
        if (! is_file($this->batchEPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-E asset file is not present on this workstation.');
        }
    }

    private function skipWhenBatchFMissing(): void
    {
        if (! is_file($this->batchFPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-F asset file is not present on this workstation.');
        }
    }

    private function skipWhenBatchGMissing(): void
    {
        if (! is_file($this->batchGPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-G asset file is not present on this workstation.');
        }
    }

    private function skipWhenBatchHMissing(): void
    {
        if (! is_file($this->batchHPath())) {
            $this->markTestSkipped('External ENNEAGRAM 1R-H asset file is not present on this workstation.');
        }
    }

    private function resolveExternalAssetPath(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }
}
