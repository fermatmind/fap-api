<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Artisan;

final class BigFiveOpsCommandRunner
{
    /**
     * @param  array<string,scalar>  $args
     * @return array{exit_code:int,output:string}
     */
    public function run(string $command, array $args): array
    {
        $exitCode = Artisan::call($command, $args);

        return [
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ];
    }
}
