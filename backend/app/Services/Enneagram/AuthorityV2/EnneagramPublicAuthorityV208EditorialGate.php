<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

final class EnneagramPublicAuthorityV208EditorialGate
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-EDITORIAL-GATE-08';

    public const SCHEMA_VERSION = 'enneagram_public_authority_v2_editorial_candidate.v1';

    public const TARGET_COUNT = 116;

    /** @var list<string> */
    private const GATES = [
        'schema_and_target_coverage',
        'bilingual_independence',
        'page_specific_information_gain',
        'duplicate_template_risk',
        'faq_depth',
        'observation_exercise_specificity',
        'geo_answerability',
        'visible_evidence',
        'manual_review_truth',
        'claim_safety',
    ];

    private const UNSUPPORTED_CLAIM_PATTERN = '/(?:scientifically proven|neuroscience proves|clinically validated|absolute(?:ly)? accurate|most accurate personality test|科学(?:已)?证明|神经科学证明|临床验证|绝对准确)/iu';

    private const PREDICTION_PATTERN = '/(?:predict(?:s|ed|ive)?\s+(?:career|job|relationship|partner|income|hiring)|guaranteed?\s+(?:career|job|relationship|income|outcome)|perfect\s+(?:career|job|partner)|预测(?:职业|收入|关系|录用|结果)|保证(?:职业|收入|关系|录用|结果)|最适合的职业|完美伴侣)/iu';

    private const COMPETITOR_PATTERN = '/(?:\btruity\b|enneagram\s+institute)/iu';

    private const GENERIC_EXERCISE_PATTERN = '/(?:for (?:the next )?seven days,? (?:notice|observe|journal)|连续七天(?:观察|记录|注意))/iu';

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $sourceRegistry
     * @param  array<string, mixed>  $pageClaimMaps
     * @return array<string, mixed>
     */
    public function validate(array $candidate, array $sourceRegistry, array $pageClaimMaps): array
    {
        $issues = [];
        $add = static function (string $gate, string $code, ?string $assetKey, string $path, string $message) use (&$issues): void {
            $issues[] = [
                'gate' => $gate,
                'code' => $code,
                'asset_key' => $assetKey,
                'path' => $path,
                'message' => $message,
            ];
        };

        $maps = $this->indexedMaps($pageClaimMaps, $add);
        $claims = $this->indexedClaims($sourceRegistry, $add);
        $assets = is_array($candidate['assets'] ?? null) ? array_values($candidate['assets']) : [];

        if (($candidate['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $add(self::GATES[0], 'schema_version_invalid', null, 'schema_version', 'The frozen editorial candidate schema is required.');
        }
        if (($candidate['framework'] ?? null) !== 'enneagram') {
            $add(self::GATES[0], 'framework_invalid', null, 'framework', 'The package framework must be enneagram.');
        }
        if (count($assets) !== self::TARGET_COUNT) {
            $add(self::GATES[0], 'target_count_invalid', null, 'assets', 'The aggregate editorial gate requires exactly 116 locale assets.');
        }

        $indexedAssets = [];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                $add(self::GATES[0], 'asset_not_object', null, "assets.{$index}", 'Every asset must be an object.');

                continue;
            }
            $key = $this->assetKey($asset);
            if ($key === null) {
                $add(self::GATES[0], 'asset_identity_invalid', null, "assets.{$index}", 'Asset locale and identity_key are required.');

                continue;
            }
            if (array_key_exists($key, $indexedAssets)) {
                $add(self::GATES[0], 'duplicate_asset_identity', $key, "assets.{$index}", 'A locale identity may appear only once.');
            }
            $indexedAssets[$key] = ['asset' => $asset, 'index' => $index];
        }

        foreach ($maps as $key => $map) {
            if (! array_key_exists($key, $indexedAssets)) {
                $add(self::GATES[0], 'target_asset_missing', $key, 'assets', 'The frozen 116-page target is missing.');
            }
        }
        foreach ($indexedAssets as $key => $entry) {
            if (! array_key_exists($key, $maps)) {
                $add(self::GATES[0], 'unknown_target_asset', $key, "assets.{$entry['index']}", 'Asset is not in the frozen 116-page target map.');
            }
        }

        $paragraphs = [];
        $sentences = [];
        $typeTemplates = [];
        $faqAnswers = [];
        foreach ($indexedAssets as $key => $entry) {
            $asset = $entry['asset'];
            $path = "assets.{$entry['index']}";
            $map = $maps[$key] ?? null;
            $this->validateShape($asset, $key, $path, $map, $add);
            $this->validateAuthorship($asset, $key, $path, $indexedAssets, $add);
            $this->validateInformationGain($asset, $key, $path, $add);
            $this->validateFaqs($asset, $key, $path, $faqAnswers, $add);
            $this->validateExercise($asset, $key, $path, $add);
            $this->validateAnswerability($asset, $key, $path, $add);
            $this->validateEvidenceAndClaims($asset, $key, $path, $map, $claims, $add);
            $this->validateReviewTruth($asset, $key, $path, $add);
            $this->validateTextSafety($asset, $key, $path, $paragraphs, $sentences, $typeTemplates, $add);
        }

        $qaRows = [];
        foreach ($maps as $key => $map) {
            $assetIssues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => $issue['asset_key'] === null || $issue['asset_key'] === $key,
            ));
            $gateResults = [];
            foreach (self::GATES as $gate) {
                $count = count(array_filter($assetIssues, static fn (array $issue): bool => $issue['gate'] === $gate));
                $gateResults[$gate] = ['status' => $count === 0 ? 'pass' : 'fail', 'issue_count' => $count];
            }
            $qaRows[] = [
                'identity_key' => $map['identity_key'],
                'locale' => $map['locale'],
                'entity_type' => $map['entity_type'],
                'code' => $map['code'],
                'path' => $map['path'],
                'status' => $assetIssues === [] ? 'pass' : 'fail',
                'gates' => $gateResults,
                'issues' => $assetIssues,
            ];
        }

        return [
            'artifact' => self::ARTIFACT,
            'schema_version' => 'enneagram_public_authority_v2_editorial_gate_report.v1',
            'status' => $issues === [] ? 'ready_for_human_review' : 'fail_closed',
            'ok' => $issues === [],
            'target_count' => self::TARGET_COUNT,
            'qa_row_count' => count($qaRows),
            'qa_rows' => $qaRows,
            'issues' => $issues,
            'automated_gate_passed' => $issues === [],
            'human_review_completed' => false,
            'human_review_passed' => false,
            'publish_eligible' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'database_mutation_attempted' => false,
            'indexability_mutation_attempted' => false,
            'sitemap_mutation_attempted' => false,
            'llms_mutation_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
        ];
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function indexedMaps(array $payload, callable $add): array
    {
        $rows = is_array($payload['page_maps'] ?? null) ? $payload['page_maps'] : [];
        $indexed = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null || isset($indexed[$key])) {
                $add(self::GATES[0], 'claim_map_invalid', null, "page_maps.{$index}", 'Each source-ledger map must have one unique locale identity.');

                continue;
            }
            $indexed[$key] = $row;
        }
        if (count($indexed) !== self::TARGET_COUNT) {
            $add(self::GATES[0], 'claim_map_count_invalid', null, 'page_maps', 'The source ledger must contain exactly 116 unique page maps.');
        }

        return $indexed;
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function indexedClaims(array $payload, callable $add): array
    {
        $claims = [];
        foreach (is_array($payload['claims'] ?? null) ? $payload['claims'] : [] as $index => $claim) {
            if (! is_array($claim) || trim((string) ($claim['id'] ?? '')) === '') {
                $add(self::GATES[9], 'source_claim_invalid', null, "claims.{$index}", 'Every source-ledger claim needs an id.');

                continue;
            }
            $claims[(string) $claim['id']] = $claim;
        }

        return $claims;
    }

    /** @param array<string, mixed> $asset */
    private function assetKey(array $asset): ?string
    {
        $locale = trim((string) ($asset['locale'] ?? ''));
        $identity = trim((string) ($asset['identity_key'] ?? ''));

        return in_array($locale, ['en', 'zh-CN'], true) && $identity !== '' ? $locale.'|'.$identity : null;
    }

    /** @param array<string, mixed>|null $map @param callable(string, string, ?string, string, string): void $add */
    private function validateShape(array $asset, string $key, string $path, ?array $map, callable $add): void
    {
        foreach (['identity_key', 'locale', 'entity_type', 'code', 'path', 'title', 'answer_first', 'authoring', 'sections', 'faqs', 'observation_exercise', 'answerability', 'claim_ids', 'visible_evidence', 'review_truth', 'release_truth'] as $field) {
            if (! array_key_exists($field, $asset)) {
                $add(self::GATES[0], 'asset_field_missing', $key, "{$path}.{$field}", 'Required editorial field is missing.');
            }
        }
        if ($map !== null) {
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                if (($asset[$field] ?? null) !== ($map[$field] ?? null)) {
                    $add(self::GATES[0], 'target_identity_mismatch', $key, "{$path}.{$field}", 'Asset identity must exactly match the frozen target map.');
                }
            }
        }
        if (! is_array($asset['sections'] ?? null) || $asset['sections'] === []) {
            $add(self::GATES[0], 'sections_missing', $key, "{$path}.sections", 'Visible sections must be non-empty.');
        }
    }

    /** @param array<string, array{asset: array<string, mixed>, index: int}> $assets @param callable(string, string, ?string, string, string): void $add */
    private function validateAuthorship(array $asset, string $key, string $path, array $assets, callable $add): void
    {
        $authoring = is_array($asset['authoring'] ?? null) ? $asset['authoring'] : [];
        if (($authoring['mode'] ?? null) !== 'independent_original' || array_key_exists('source_locale', $authoring) === false || $authoring['source_locale'] !== null) {
            $add(self::GATES[1], 'locale_not_independently_authored', $key, "{$path}.authoring", 'Each locale must declare independent original authorship with no source locale.');
        }
        if ($this->length($authoring['independence_note'] ?? null) < 40) {
            $add(self::GATES[1], 'independence_note_insufficient', $key, "{$path}.authoring.independence_note", 'Independent editorial intent must be explicit.');
        }
        $pairLocale = ($asset['locale'] ?? null) === 'en' ? 'zh-CN' : 'en';
        $pairKey = $pairLocale.'|'.($asset['identity_key'] ?? '');
        if (isset($assets[$pairKey])) {
            $outline = array_values(is_array($authoring['outline'] ?? null) ? $authoring['outline'] : []);
            $pairAuthoring = is_array($assets[$pairKey]['asset']['authoring'] ?? null) ? $assets[$pairKey]['asset']['authoring'] : [];
            $pairOutline = array_values(is_array($pairAuthoring['outline'] ?? null) ? $pairAuthoring['outline'] : []);
            if ($outline === [] || $outline === $pairOutline) {
                $add(self::GATES[1], 'identical_locale_outline', $key, "{$path}.authoring.outline", 'EN and zh-CN outlines must express independent editorial structure, not a mechanical translation outline.');
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateInformationGain(array $asset, string $key, string $path, callable $add): void
    {
        $authoring = is_array($asset['authoring'] ?? null) ? $asset['authoring'] : [];
        $signals = is_array($authoring['page_specific_signals'] ?? null) ? $authoring['page_specific_signals'] : [];
        $normalized = array_values(array_unique(array_filter(array_map(fn (mixed $value): string => $this->normalize($value), $signals))));
        if (count($normalized) < 3 || min(array_map('mb_strlen', $normalized ?: [''])) < 18) {
            $add(self::GATES[2], 'page_specific_information_insufficient', $key, "{$path}.authoring.page_specific_signals", 'At least three substantive, distinct page-specific observations are required.');
        }
        $minimumAnswerLength = ($asset['locale'] ?? null) === 'zh-CN' ? 60 : 100;
        if ($this->length($asset['answer_first'] ?? null) < $minimumAnswerLength) {
            $add(self::GATES[2], 'answer_first_too_shallow', $key, "{$path}.answer_first", 'The answer-first definition must provide substantive page-specific information.');
        }
    }

    /** @param array<string, array{key: string, path: string}> $seen @param callable(string, string, ?string, string, string): void $add */
    private function validateFaqs(array $asset, string $key, string $path, array &$seen, callable $add): void
    {
        $faqs = is_array($asset['faqs'] ?? null) ? $asset['faqs'] : [];
        if (count($faqs) < 3) {
            $add(self::GATES[4], 'faq_depth_insufficient', $key, "{$path}.faqs", 'Each page requires at least three page-specific FAQs.');
        }
        foreach ($faqs as $index => $faq) {
            $question = is_array($faq) ? (string) ($faq['question'] ?? '') : '';
            $answer = is_array($faq) ? (string) ($faq['answer'] ?? '') : '';
            if ($this->length($question) < 12 || $this->length($answer) < 60) {
                $add(self::GATES[4], 'faq_item_too_shallow', $key, "{$path}.faqs.{$index}", 'FAQ questions and answers must be substantive.');
            }
            $normalized = $this->normalize($answer);
            if ($normalized !== '' && isset($seen[$normalized])) {
                $add(self::GATES[4], 'repeated_faq_answer', $key, "{$path}.faqs.{$index}.answer", "FAQ answer repeats {$seen[$normalized]['path']}.");
            }
            if ($normalized !== '') {
                $seen[$normalized] = ['key' => $key, 'path' => "{$path}.faqs.{$index}.answer"];
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateExercise(array $asset, string $key, string $path, callable $add): void
    {
        $exercise = is_array($asset['observation_exercise'] ?? null) ? $asset['observation_exercise'] : [];
        foreach (['context', 'observable_signal', 'page_specific_signal', 'alternative_explanation', 'reflection_prompt'] as $field) {
            if ($this->length($exercise[$field] ?? null) < 30) {
                $add(self::GATES[5], 'exercise_not_page_specific', $key, "{$path}.observation_exercise.{$field}", 'The exercise needs a concrete observable cue, context, alternative, and reflection step.');
            }
        }
        $duration = $exercise['duration_days'] ?? null;
        if (! is_int($duration) || $duration < 1 || $duration > 14) {
            $add(self::GATES[5], 'exercise_duration_invalid', $key, "{$path}.observation_exercise.duration_days", 'Exercise duration must be an integer from 1 to 14 days.');
        }
        if ($duration === 7 && preg_match(self::GENERIC_EXERCISE_PATTERN, json_encode($exercise, JSON_UNESCAPED_UNICODE) ?: '') === 1) {
            $add(self::GATES[5], 'generic_seven_day_exercise', $key, "{$path}.observation_exercise", 'A generic seven-day observation prompt is not page-specific.');
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateAnswerability(array $asset, string $key, string $path, callable $add): void
    {
        $answerability = is_array($asset['answerability'] ?? null) ? $asset['answerability'] : [];
        $questions = is_array($answerability['questions'] ?? null) ? $answerability['questions'] : [];
        if (($answerability['direct_answer_supported'] ?? null) !== true || count(array_unique(array_map('strval', $questions))) < 3) {
            $add(self::GATES[6], 'geo_answerability_insufficient', $key, "{$path}.answerability", 'Each page must declare at least three distinct questions supported by visible direct answers.');
        }
    }

    /** @param array<string, mixed>|null $map @param array<string, array<string, mixed>> $claims @param callable(string, string, ?string, string, string): void $add */
    private function validateEvidenceAndClaims(array $asset, string $key, string $path, ?array $map, array $claims, callable $add): void
    {
        $evidence = is_array($asset['visible_evidence'] ?? null) ? $asset['visible_evidence'] : [];
        $assetClaimIds = array_values(array_unique(array_map('strval', is_array($asset['claim_ids'] ?? null) ? $asset['claim_ids'] : [])));
        $visibleClaimIds = array_values(array_unique(array_map('strval', is_array($evidence['claim_ids'] ?? null) ? $evidence['claim_ids'] : [])));
        if (($evidence['visible'] ?? null) !== true || count(is_array($evidence['limitations'] ?? null) ? $evidence['limitations'] : []) < 2) {
            $add(self::GATES[7], 'visible_evidence_or_limitations_missing', $key, "{$path}.visible_evidence", 'Evidence and at least two limitations must be visible in the candidate content.');
        }
        if ($map === null) {
            return;
        }
        $requiredFactual = array_values(array_map('strval', is_array($map['factual_claim_ids'] ?? null) ? $map['factual_claim_ids'] : []));
        foreach ($requiredFactual as $claimId) {
            if (! in_array($claimId, $assetClaimIds, true)) {
                $add(self::GATES[9], 'mapped_factual_claim_missing', $key, "{$path}.claim_ids", "Required mapped claim {$claimId} is missing.");
            }
            if (! in_array($claimId, $visibleClaimIds, true)) {
                $add(self::GATES[7], 'factual_claim_hidden', $key, "{$path}.visible_evidence.claim_ids", "Factual claim {$claimId} is not represented in visible evidence.");
            }
        }
        $permitted = array_values(array_filter(
            array_map('strval', is_array($map['claim_ids'] ?? null) ? $map['claim_ids'] : []),
            static fn (string $claimId): bool => ($claims[$claimId]['allowed_as_public_claim'] ?? false) === true,
        ));
        foreach ($assetClaimIds as $claimId) {
            if (! isset($claims[$claimId])) {
                $add(self::GATES[9], 'claim_unknown', $key, "{$path}.claim_ids", "Claim {$claimId} is not in the source registry.");
            } elseif (! in_array($claimId, $permitted, true)) {
                $add(self::GATES[9], 'claim_not_authorized_for_page', $key, "{$path}.claim_ids", "Claim {$claimId} is blocked or not authorized for this page.");
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateReviewTruth(array $asset, string $key, string $path, callable $add): void
    {
        $review = is_array($asset['review_truth'] ?? null) ? $asset['review_truth'] : [];
        $expectedReview = [
            'status' => 'pending_manual_review',
            'reviewer' => null,
            'reviewed_at' => null,
            'human_review_completed' => false,
            'review_source' => 'unassigned',
        ];
        foreach ($expectedReview as $field => $expected) {
            if (! array_key_exists($field, $review) || $review[$field] !== $expected) {
                $add(self::GATES[8], 'manual_review_truth_invalid', $key, "{$path}.review_truth.{$field}", 'Automated or model QA must not be presented as completed human review.');
            }
        }
        $release = is_array($asset['release_truth'] ?? null) ? $asset['release_truth'] : [];
        $expectedRelease = [
            'draft_only' => true,
            'publish_eligible' => false,
            'indexability_changed' => false,
            'sitemap_changed' => false,
            'llms_changed' => false,
        ];
        foreach ($expectedRelease as $field => $expected) {
            if (! array_key_exists($field, $release) || $release[$field] !== $expected) {
                $add(self::GATES[8], 'release_truth_invalid', $key, "{$path}.release_truth.{$field}", 'Editorial QA must leave release and discoverability gates closed.');
            }
        }
    }

    /** @param array<string, array{key: string, path: string}> $paragraphs @param array<string, array{key: string, path: string}> $sentences @param array<string, array{key: string, path: string}> $typeTemplates @param callable(string, string, ?string, string, string): void $add */
    private function validateTextSafety(array $asset, string $key, string $path, array &$paragraphs, array &$sentences, array &$typeTemplates, callable $add): void
    {
        $blocks = [(string) ($asset['title'] ?? ''), (string) ($asset['answer_first'] ?? '')];
        foreach (is_array($asset['sections'] ?? null) ? $asset['sections'] : [] as $section) {
            if (is_array($section)) {
                $blocks[] = (string) ($section['body'] ?? '');
            }
        }
        foreach (is_array($asset['faqs'] ?? null) ? $asset['faqs'] : [] as $faq) {
            if (is_array($faq)) {
                $blocks[] = (string) ($faq['question'] ?? '');
                $blocks[] = (string) ($faq['answer'] ?? '');
            }
        }
        $text = implode("\n", $blocks);
        foreach ([
            [self::UNSUPPORTED_CLAIM_PATTERN, 'unsupported_science_claim'],
            [self::PREDICTION_PATTERN, 'career_or_relationship_prediction'],
            [self::COMPETITOR_PATTERN, 'competitor_language_detected'],
        ] as [$pattern, $code]) {
            if (preg_match($pattern, $text) === 1) {
                $add(self::GATES[9], $code, $key, $path, 'Public candidate text crosses the declared evidence or competitor boundary.');
            }
        }

        foreach ($blocks as $index => $block) {
            $normalized = $this->normalize($block);
            if (mb_strlen($normalized) >= 80) {
                if (isset($paragraphs[$normalized]) && $paragraphs[$normalized]['key'] !== $key) {
                    $add(self::GATES[3], 'duplicate_paragraph', $key, "{$path}.text.{$index}", "Paragraph duplicates {$paragraphs[$normalized]['path']}.");
                }
                $paragraphs[$normalized] = ['key' => $key, 'path' => "{$path}.text.{$index}"];

                $template = preg_replace('/(?:type[1-9]|[1-9]w[1-9]|(?:sp|so|sx)[-_]?[1-9]|第?[1-9]型)/iu', '{type}', $normalized) ?? $normalized;
                if (isset($typeTemplates[$template]) && $typeTemplates[$template]['key'] !== $key) {
                    $add(self::GATES[3], 'type_number_substitution_template', $key, "{$path}.text.{$index}", "Text differs from {$typeTemplates[$template]['path']} only by a type label or number.");
                }
                $typeTemplates[$template] = ['key' => $key, 'path' => "{$path}.text.{$index}"];
            }

            foreach (preg_split('/(?<=[.!?。！？])\s*/u', $block, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                $normalizedSentence = $this->normalize($sentence);
                if (mb_strlen($normalizedSentence) < 50) {
                    continue;
                }
                if (isset($sentences[$normalizedSentence]) && $sentences[$normalizedSentence]['key'] !== $key) {
                    $add(self::GATES[3], 'duplicate_sentence', $key, "{$path}.text.{$index}", "Sentence duplicates {$sentences[$normalizedSentence]['path']}.");
                }
                $sentences[$normalizedSentence] = ['key' => $key, 'path' => "{$path}.text.{$index}"];
            }
        }
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))) ?? '';
    }

    private function length(mixed $value): int
    {
        return mb_strlen(trim((string) $value));
    }
}
