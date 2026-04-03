<?php

declare(strict_types=1);

namespace App\Services\BigFive;

use App\Models\Attempt;
use App\Models\Result;

final class BigFivePublicFormSummaryBuilder
{
    public function __construct(
        private readonly BigFiveFormCatalog $catalog,
    ) {}

    /**
     * @return array{
     *   form_code:string,
     *   label:string,
     *   short_label:string,
     *   question_count:int,
     *   estimated_minutes:int,
     *   scale_code:string
     * }|null
     */
    public function summarizeForAttempt(?Attempt $attempt, ?Result $result = null, ?string $locale = null): ?array
    {
        if (! $this->isBigFiveRecord($attempt, $result)) {
            return null;
        }

        $formCode = $this->resolveStoredFormCode($attempt, $result);
        if ($formCode === null) {
            return null;
        }

        return $this->buildSummary($formCode, $locale, $this->preferredPackId($attempt, $result));
    }

    /**
     * @return array{
     *   form_code:string,
     *   label:string,
     *   short_label:string,
     *   question_count:int,
     *   estimated_minutes:int,
     *   scale_code:string
     * }|null
     */
    public function summarizeForAttemptId(?string $attemptId, int $orgId, ?string $locale = null): ?array
    {
        $normalizedAttemptId = trim((string) $attemptId);
        if ($normalizedAttemptId === '') {
            return null;
        }

        $attempt = Attempt::query()
            ->where('org_id', $orgId)
            ->where('id', $normalizedAttemptId)
            ->first();

        return $attempt instanceof Attempt
            ? $this->summarizeForAttempt($attempt, null, $locale)
            : null;
    }

    private function isBigFiveRecord(?Attempt $attempt, ?Result $result): bool
    {
        return strtoupper(trim((string) ($attempt?->scale_code ?? $result?->scale_code ?? ''))) === 'BIG5_OCEAN';
    }

    private function preferredPackId(?Attempt $attempt, ?Result $result): ?string
    {
        $packId = trim((string) ($attempt?->pack_id ?? $result?->pack_id ?? ''));

        return $packId !== '' ? $packId : null;
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

        $metaFormCode = trim((string) data_get($payload, 'meta.form_code', ''));
        if ($metaFormCode !== '') {
            try {
                return (string) $this->catalog->resolve($metaFormCode)['form_code'];
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

        $forms = config('content_packs.big5_forms.forms', []);
        if (! is_array($forms)) {
            return null;
        }

        foreach ($forms as $formCode => $config) {
            if (! is_array($config)) {
                continue;
            }

            if ($normalizedDirVersion === trim((string) ($config['dir_version'] ?? ''))) {
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

        $forms = config('content_packs.big5_forms.forms', []);
        if (! is_array($forms)) {
            return null;
        }

        $matchedFormCodes = [];
        foreach ($forms as $formCode => $config) {
            if (! is_array($config)) {
                continue;
            }

            $configuredCount = (int) ($config['question_count'] ?? 0);
            if ($configuredCount > 0 && $configuredCount === $questionCount) {
                $matchedFormCodes[] = (string) $formCode;
            }
        }

        if (count($matchedFormCodes) !== 1) {
            return null;
        }

        return $matchedFormCodes[0];
    }

    /**
     * @return array{
     *   form_code:string,
     *   label:string,
     *   short_label:string,
     *   question_count:int,
     *   estimated_minutes:int,
     *   scale_code:string
     * }|null
     */
    private function buildSummary(string $formCode, ?string $locale, ?string $packId): ?array
    {
        try {
            $resolved = $this->catalog->resolve($formCode, $packId);
        } catch (\Throwable) {
            return null;
        }

        $forms = config('content_packs.big5_forms.forms', []);
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
            'label' => $label,
            'short_label' => $shortLabel,
            'question_count' => (int) ($resolved['question_count'] ?? 0),
            'estimated_minutes' => (int) ($public['estimated_minutes'] ?? 0),
            'scale_code' => 'BIG5_OCEAN',
        ];
    }

    private function normalizeLanguage(?string $locale): string
    {
        $normalized = strtolower(trim((string) $locale));

        return str_starts_with($normalized, 'en') ? 'en' : 'zh';
    }
}
