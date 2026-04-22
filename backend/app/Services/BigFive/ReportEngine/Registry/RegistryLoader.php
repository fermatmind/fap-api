<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Registry;

use RuntimeException;

final class RegistryLoader
{
    public function __construct(
        private readonly ?string $registryPath = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function load(): array
    {
        $root = $this->registryPath ?? base_path('content_packs/BIG5_OCEAN/v2/registry');

        return [
            'root' => $root,
            'manifest' => $this->readJson($root.'/manifest.json'),
            'fixtures' => [
                'canonical_n_slice_sensitive_independent' => $this->readJson($root.'/fixtures/canonical_n_slice_sensitive_independent.context.json'),
            ],
            'atomic' => [
                'N' => $this->readJson($root.'/atomic/N.json'),
            ],
            'modifiers' => [
                'N' => $this->readJson($root.'/modifiers/N.json'),
            ],
            'synergies' => [
                'n_high_x_e_low' => $this->readJson($root.'/synergies/n_high_x_e_low.json'),
            ],
            'facet_precision' => [
                'N' => $this->readJson($root.'/facet_precision/N.json'),
            ],
            'action_rules' => [
                'workplace' => $this->readJson($root.'/action_rules/workplace.json'),
                'stress_recovery' => $this->readJson($root.'/action_rules/stress_recovery.json'),
                'personal_growth' => $this->readJson($root.'/action_rules/personal_growth.json'),
            ],
            'shared' => [
                'section_headlines' => $this->readJson($root.'/shared/section_headlines.json'),
                'compare_phrases' => $this->readJson($root.'/shared/compare_phrases.json'),
                'methodology' => $this->readJson($root.'/shared/methodology.json'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Big Five report engine registry file missing: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Big Five report engine registry file is invalid JSON: {$path}");
        }

        return $decoded;
    }
}
