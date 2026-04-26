<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergePolicyValidator;
use Tests\TestCase;

final class EnneagramAssetMergePolicyValidatorTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_accepts_1r_a_partial_override_and_1r_b_legacy_core_rewrite_only(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $validator = app(EnneagramAssetMergePolicyValidator::class);

        $batchA = $loader->load($this->batchAPath());
        $batchB = $loader->load($this->batchBPath());

        $this->assertSame([], $validator->validatePair($batchA, $batchB));

        $batchA['metadata']['replacement_policy']['mode'] = 'legacy_core_rewrite';
        $this->assertContains('batch_1r_a_requires_partial_override_mode', $validator->validateSingle($batchA));

        $batchB['metadata']['replacement_policy']['mode'] = 'partial_override';
        $this->assertContains('batch_1r_b_requires_legacy_core_rewrite_mode', $validator->validateSingle($batchB));
    }
}
