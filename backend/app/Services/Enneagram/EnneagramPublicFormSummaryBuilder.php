<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Models\Attempt;
use App\Models\Result;

final class EnneagramPublicFormSummaryBuilder
{
    public function __construct(
        private readonly EnneagramFormCatalog $catalog,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function summarizeForAttempt(?Attempt $attempt, ?Result $result = null, ?string $locale = null): ?array
    {
        if (strtoupper(trim((string) ($attempt?->scale_code ?? $result?->scale_code ?? ''))) !== 'ENNEAGRAM') {
            return null;
        }

        $formCode = $this->resolveStoredFormCode($attempt, $result);
        if ($formCode === null) {
            return null;
        }

        try {
            $resolved = $this->catalog->resolve($formCode, $this->preferredPackId($attempt, $result));
        } catch (\Throwable) {
            return null;
        }

        $forms = config('content_packs.enneagram_forms.forms', []);
        $formConfig = is_array($forms) ? ($forms[$resolved['form_code']] ?? null) : null;
        if (! is_array($formConfig)) {
            return null;
        }

        $public = is_array($formConfig['public'] ?? null) ? $formConfig['public'] : [];
        $language = $this->normalizeLanguage($locale);
        $labels = is_array($public['label'] ?? null) ? $public['label'] : [];
        $shortLabels = is_array($public['short_label'] ?? null) ? $public['short_label'] : [];

        $label = trim((string) ($labels[$language] ?? $labels['zh'] ?? ''));
        $shortLabel = trim((string) ($shortLabels[$language] ?? $shortLabels['zh'] ?? ''));
        if ($label === '' || $shortLabel === '') {
            return null;
        }

        return [
            'form_code' => (string) $resolved['form_code'],
            'form_kind' => (string) ($resolved['form_kind'] ?? ''),
            'label' => $label,
            'short_label' => $shortLabel,
            'question_count' => (int) ($resolved['question_count'] ?? 0),
            'estimated_minutes' => (int) ($public['estimated_minutes'] ?? 0),
            'scale_code' => 'ENNEAGRAM',
        ];
    }

    private function resolveStoredFormCode(?Attempt $attempt, ?Result $result): ?string
    {
        $candidates = [
            $this->extractFormCode($attempt?->answers_summary_json ?? null),
            $this->extractFormCode($result?->result_json ?? null),
            $this->matchFormCodeByDirVersion((string) ($attempt?->dir_version ?? '')),
            $this->matchFormCodeByDirVersion((string) ($result?->dir_version ?? '')),
            $this->matchFormCodeByQuestionCount((int) ($attempt?->question_count ?? 0)),
        ];

        foreach ($candidates as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractFormCode(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['meta.form_code', 'form_code', 'normed_json.form_code', 'breakdown_json.score_result.form_code'] as $path) {
            $formCode = trim((string) data_get($payload, $path, ''));
            if ($formCode === '') {
                continue;
            }

            try {
                return (string) $this->catalog->resolve($formCode)['form_code'];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function matchFormCodeByDirVersion(string $dirVersion): ?string
    {
        $normalizedDirVersion = trim($dirVersion);
        if ($normalizedDirVersion === '') {
            return null;
        }

        foreach ($this->formsConfig() as $formCode => $config) {
            if (is_array($config) && $normalizedDirVersion === trim((string) ($config['dir_version'] ?? ''))) {
                return (string) $formCode;
            }
        }

        return null;
    }

    private function matchFormCodeByQuestionCount(int $questionCount): ?string
    {
        if ($questionCount <= 0) {
            return null;
        }

        $matches = [];
        foreach ($this->formsConfig() as $formCode => $config) {
            if (is_array($config) && (int) ($config['question_count'] ?? 0) === $questionCount) {
                $matches[] = (string) $formCode;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function formsConfig(): array
    {
        $forms = config('content_packs.enneagram_forms.forms', []);

        return is_array($forms) ? $forms : [];
    }

    private function preferredPackId(?Attempt $attempt, ?Result $result): ?string
    {
        $packId = trim((string) ($attempt?->pack_id ?? $result?->pack_id ?? ''));

        return $packId !== '' ? $packId : null;
    }

    private function normalizeLanguage(?string $locale): string
    {
        return str_starts_with(strtolower(trim((string) $locale)), 'en') ? 'en' : 'zh';
    }
}
