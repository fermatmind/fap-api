<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\Checks;

use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use App\Services\SelfCheck\SelfCheckResult;

final class QuestionsCheck extends BaseSelfCheck
{
    public function name(): string
    {
        return 'questions';
    }

    public function run(SelfCheckContext $ctx, SelfCheckIo $io): SelfCheckResult
    {
        $result = new SelfCheckResult($this->name());

        $manifest = $ctx->getManifest();
        $manifestPath = (string) ($ctx->manifestPath ?? '');
        $packId = (string) ($manifest['pack_id'] ?? 'UNKNOWN_PACK');

        if ($manifestPath === '') {
            return $result->addError('manifest path missing');
        }

        $baseDir = dirname($manifestPath);
        $declaredBasenames = $io->declaredAssetBasenames($manifest);

        $this->runIfDeclared(
            $result,
            $declaredBasenames,
            'questions.json',
            'questions.json',
            fn () => $io->checkQuestions(
                $io->pathOf($baseDir, 'questions.json'),
                $packId,
                $io->expectedSchemaFor($manifest, 'questions', 'questions.json'),
                (string) ($manifest['scale_code'] ?? '')
            )
        );

        return $result;
    }
}
