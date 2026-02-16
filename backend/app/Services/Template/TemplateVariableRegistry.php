<?php

declare(strict_types=1);

namespace App\Services\Template;

final class TemplateVariableRegistry
{
    /**
     * @var array<string,string>
     */
    private const ALLOWED = [
        'attempt_id' => 'Attempt UUID',
        'scale_code' => 'Scale code',
        'type_code' => 'Result type code',
        'type_name' => 'Localized type name',
        'variant' => 'Report variant',
        'access_level' => 'Report access level',
        'modules_allowed' => 'Unlocked modules list',
        'report_date' => 'Report render date',
        'score_axis_ei' => 'EI axis score',
        'score_axis_sn' => 'SN axis score',
        'score_axis_tf' => 'TF axis score',
        'score_axis_jp' => 'JP axis score',
        'score_axis_at' => 'AT axis score',
        'percentile_extraversion' => 'Percentile/extraversion proxy',

        // Overrides context variables.
        'section_key' => 'Section key in overrides pipeline',
        'content_package_dir' => 'Resolved package directory',
        'target' => 'Overrides target',
    ];

    /**
     * @return array<string,string>
     */
    public function allAllowedVariables(): array
    {
        return self::ALLOWED;
    }

    public function isAllowed(string $varName): bool
    {
        $varName = trim($varName);
        if ($varName === '') {
            return false;
        }

        if (str_starts_with($varName, 'ctx.')) {
            return true;
        }

        return array_key_exists($varName, self::ALLOWED);
    }

    public function assertAllowed(string $varName): void
    {
        if ($this->isAllowed($varName)) {
            return;
        }

        throw new \InvalidArgumentException("Unknown template variable: {$varName}");
    }

    /**
     * @param list<string> $requiredList
     * @return list<string>
     */
    public function missingRequired(array $requiredList, TemplateContext $context): array
    {
        $missing = [];
        foreach ($requiredList as $varName) {
            if (!$this->isAllowed($varName)) {
                $missing[] = $varName;
                continue;
            }

            if (str_starts_with($varName, 'ctx.')) {
                $dot = substr($varName, 4);
                if ($dot === '' || $context->getCtx($dot) === null) {
                    $missing[] = $varName;
                }
                continue;
            }

            if (!$context->has($varName) || $context->get($varName) === null) {
                $missing[] = $varName;
            }
        }

        return array_values(array_unique($missing));
    }
}
