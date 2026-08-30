<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

final class DenyOnlyMeasurementRuntimeGate implements MeasurementRuntimeGate
{
    public function allows(array $capabilitySnapshot): bool
    {
        return ($capabilitySnapshot['production_execution_enabled'] ?? null) === true
            && ($capabilitySnapshot['production_model_enabled'] ?? null) === false
            && ($capabilitySnapshot['production_tool_enabled'] ?? null) === false
            && ($capabilitySnapshot['production_write_enabled'] ?? null) === false
            && ($capabilitySnapshot['execution_allowed'] ?? null) === false;
    }
}
