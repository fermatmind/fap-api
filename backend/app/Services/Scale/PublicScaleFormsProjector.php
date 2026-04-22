<?php

declare(strict_types=1);

namespace App\Services\Scale;

final class PublicScaleFormsProjector
{
    /**
     * @param  array<string,mixed>  $registryRow
     * @return list<array<string,mixed>>
     */
    public function projectForRegistryRow(array $registryRow, string $locale = 'zh-CN'): array
    {
        $scaleCode = strtoupper(trim((string) ($registryRow['code'] ?? $registryRow['scale_code'] ?? '')));
        if ($scaleCode === '') {
            return [];
        }

        $formsConfig = $this->formsConfigForScale($scaleCode);
        if ($formsConfig === []) {
            return [];
        }

        $capabilities = $this->toArray($registryRow['capabilities_json'] ?? null);
        $configuredForms = is_array($formsConfig['forms'] ?? null) ? $formsConfig['forms'] : [];
        $configuredDefault = trim((string) ($formsConfig['default_form_code'] ?? ''));
        $defaultFormCode = trim((string) ($capabilities['default_form_code'] ?? $configuredDefault));

        $formCodes = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) ($capabilities['forms'] ?? [])
        )));
        if ($formCodes === []) {
            $formCodes = array_keys($configuredForms);
        }

        $projected = [];
        foreach ($formCodes as $formCode) {
            if (! is_array($configuredForms[$formCode] ?? null)) {
                continue;
            }

            $form = $configuredForms[$formCode];
            $public = $this->toArray($form['public'] ?? null);
            $label = $this->localizedLabel($public['label'] ?? null, $locale);
            $shortLabel = $this->localizedLabel($public['short_label'] ?? null, $locale);
            $questionCount = $this->positiveInt($form['question_count'] ?? null);
            if ($questionCount <= 0) {
                $questionCount = $this->inferQuestionCount($formCode, (string) $label, (string) $shortLabel);
            }

            $projected[] = [
                'form_code' => $formCode,
                'is_default' => $defaultFormCode !== '' && $formCode === $defaultFormCode,
                'question_count' => $questionCount,
                'estimated_minutes' => $this->positiveInt($public['estimated_minutes'] ?? null),
                'form_kind' => trim((string) ($form['form_kind'] ?? '')) ?: null,
                'label' => $label,
                'short_label' => $shortLabel,
                'label_i18n' => $this->labelMap($public['label'] ?? null),
                'short_label_i18n' => $this->labelMap($public['short_label'] ?? null),
                'aliases' => array_values(array_filter(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    (array) ($form['aliases'] ?? [])
                ))),
            ];
        }

        return count($projected) > 1 ? $projected : [];
    }

    /**
     * @param  array<string,mixed>  $registryRow
     */
    public function defaultQuestionCount(array $registryRow, string $locale = 'zh-CN'): int
    {
        foreach ($this->projectForRegistryRow($registryRow, $locale) as $form) {
            if (($form['is_default'] ?? false) === true) {
                return $this->positiveInt($form['question_count'] ?? null);
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function formsConfigForScale(string $scaleCode): array
    {
        $configKey = match ($scaleCode) {
            'MBTI' => 'content_packs.mbti_forms',
            'BIG5_OCEAN' => 'content_packs.big5_forms',
            'ENNEAGRAM' => 'content_packs.enneagram_forms',
            'RIASEC' => 'content_packs.riasec_forms',
            default => null,
        };

        if ($configKey === null) {
            return [];
        }

        $config = config($configKey, []);

        return is_array($config) ? $config : [];
    }

    private function localizedLabel(mixed $labels, string $locale): ?string
    {
        $map = $this->labelMap($labels);
        if ($map === []) {
            return null;
        }

        $language = str_starts_with(strtolower($locale), 'zh') ? 'zh' : 'en';

        return $map[$language] ?? $map['en'] ?? $map['zh'] ?? null;
    }

    /**
     * @return array<string,string>
     */
    private function labelMap(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        $out = [];
        foreach (['en', 'zh'] as $key) {
            $value = trim((string) ($labels[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function inferQuestionCount(string ...$values): int
    {
        foreach ($values as $value) {
            if (preg_match('/(\d{2,3})/', $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function positiveInt(mixed $value): int
    {
        $int = (int) $value;

        return $int > 0 ? $int : 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
