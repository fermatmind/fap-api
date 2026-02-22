<?php

declare(strict_types=1);

namespace App\Services\Template;

use App\Services\Content\BigFivePackLoader;
use App\Services\Content\ClinicalComboPackLoader;

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
     * @var array{allowed:list<string>,required:list<string>}|null
     */
    private ?array $bigFiveVariableSpec = null;

    /**
     * @var array{allowed:list<string>,required:list<string>}|null
     */
    private ?array $clinicalVariableSpec = null;

    public function __construct(
        private readonly ?BigFivePackLoader $bigFivePackLoader = null,
        private readonly ?ClinicalComboPackLoader $clinicalPackLoader = null,
    )
    {
    }

    /**
     * @return array<string,string>
     */
    public function allAllowedVariables(): array
    {
        $out = self::ALLOWED;
        foreach ($this->bigFiveVariableSpec()['allowed'] as $varName) {
            $out[$varName] = 'BIG5 template variable';
        }
        foreach ($this->clinicalVariableSpec()['allowed'] as $varName) {
            $out[$varName] = 'CLINICAL_COMBO_68 template variable';
        }

        return $out;
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

        if (array_key_exists($varName, self::ALLOWED)) {
            return true;
        }

        if (in_array($varName, $this->bigFiveVariableSpec()['allowed'], true)) {
            return true;
        }

        return in_array($varName, $this->clinicalVariableSpec()['allowed'], true);
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
        $requiredList = array_values(array_unique($requiredList));

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

    /**
     * @return list<string>
     */
    public function requiredVariables(): array
    {
        return array_values(array_unique(array_merge(
            $this->bigFiveVariableSpec()['required'],
            $this->clinicalVariableSpec()['required']
        )));
    }

    /**
     * @return array{allowed:list<string>,required:list<string>}
     */
    private function bigFiveVariableSpec(): array
    {
        if ($this->bigFiveVariableSpec !== null) {
            return $this->bigFiveVariableSpec;
        }

        $default = [
            'allowed' => [],
            'required' => [],
        ];

        try {
            $loader = $this->bigFivePackLoader ?? new BigFivePackLoader();
            $paths = [
                $loader->compiledPath('policy.compiled.json', BigFivePackLoader::PACK_VERSION),
                $loader->rawPath('variables_allowlist.json', BigFivePackLoader::PACK_VERSION),
            ];
        } catch (\Throwable) {
            // Plain unit tests may run without a bootstrapped Laravel application.
            // In that case, BIG5 allowlist loading is skipped and falls back to defaults.
            $paths = [];
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $node = $decoded;
            if (isset($decoded['variables_allowlist']) && is_array($decoded['variables_allowlist'])) {
                $node = $decoded['variables_allowlist'];
            }

            $allowed = is_array($node['allowed'] ?? null) ? $node['allowed'] : [];
            $required = is_array($node['required'] ?? null) ? $node['required'] : [];

            $default['allowed'] = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $allowed
            ))));
            $default['required'] = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $required
            ))));
            break;
        }

        $this->bigFiveVariableSpec = $default;

        return $this->bigFiveVariableSpec;
    }

    /**
     * @return array{allowed:list<string>,required:list<string>}
     */
    private function clinicalVariableSpec(): array
    {
        if ($this->clinicalVariableSpec !== null) {
            return $this->clinicalVariableSpec;
        }

        $default = [
            'allowed' => [],
            'required' => [],
        ];

        try {
            $loader = $this->clinicalPackLoader ?? new ClinicalComboPackLoader();
            $paths = [
                $loader->compiledPath('policy.compiled.json', ClinicalComboPackLoader::PACK_VERSION),
                $loader->rawPath('variables_allowlist.json', ClinicalComboPackLoader::PACK_VERSION),
            ];
        } catch (\Throwable) {
            $paths = [];
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $node = $decoded;
            if (isset($decoded['variables_allowlist']) && is_array($decoded['variables_allowlist'])) {
                $node = $decoded['variables_allowlist'];
            }

            $allowed = is_array($node['allowed'] ?? null) ? $node['allowed'] : [];
            $required = is_array($node['required'] ?? null) ? $node['required'] : [];

            $default['allowed'] = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $allowed
            ))));
            $default['required'] = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $required
            ))));
            break;
        }

        $this->clinicalVariableSpec = $default;

        return $this->clinicalVariableSpec;
    }
}
