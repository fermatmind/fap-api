<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

interface DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult;
}
