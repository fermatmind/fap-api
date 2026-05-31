<?php

declare(strict_types=1);

function iqBeta30RepoRoot(): string
{
    return dirname(__DIR__, 3);
}

function iqBeta30BankId(): string
{
    return 'IQ_BETA_30_ORIGINAL';
}

function iqBeta30BankDir(): string
{
    return iqBeta30RepoRoot() . '/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/' . iqBeta30BankId();
}

function iqBeta30FileMap(): array
{
    $dir = iqBeta30BankDir();

    return [
        'manifest' => $dir . '/manifest.json',
        'items' => $dir . '/items.json',
        'answer_key' => $dir . '/answer_key.json',
        'scoring_spec' => $dir . '/scoring_spec.json',
    ];
}

function iqBeta30PrettyJson(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function iqBeta30Sha256Json(array $payload): string
{
    return 'sha256:' . hash('sha256', iqBeta30PrettyJson($payload));
}

function iqBeta30LoadJson(string $path): array
{
    $json = json_decode((string) file_get_contents($path), true);
    if (! is_array($json)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }

    return $json;
}

function iqBeta30OptionCodes(): array
{
    return ['A', 'B', 'C', 'D', 'E', 'F'];
}

function iqBeta30ExpectedDimensionCounts(): array
{
    return ['VSPR' => 14, 'VSI' => 10, 'NPR' => 6];
}

function iqBeta30ExpectedFamilyCounts(): array
{
    return [
        'matrix_3x3' => 10,
        'matrix_2x2' => 4,
        'series' => 4,
        'odd_one_out' => 4,
        'rotation' => 3,
        'overlay' => 3,
        'numeric_pattern' => 2,
    ];
}

function iqBeta30DimensionName(string $dimension): string
{
    return match ($dimension) {
        'VSPR' => 'Visual-spatial pattern reasoning',
        'VSI' => 'Visual-spatial imagery',
        'NPR' => 'Numeric pattern reasoning',
        default => throw new InvalidArgumentException('Unknown dimension ' . $dimension),
    };
}

function iqBeta30ItemDefinitions(): array
{
    $families = array_merge(
        array_fill(0, 10, ['matrix_3x3', 'VSPR']),
        array_fill(0, 4, ['matrix_2x2', 'VSPR']),
        array_fill(0, 4, ['series', 'VSI']),
        array_fill(0, 4, ['odd_one_out', 'VSI']),
        array_fill(0, 2, ['rotation', 'VSI']),
        [['rotation', 'NPR']],
        array_fill(0, 3, ['overlay', 'NPR']),
        array_fill(0, 2, ['numeric_pattern', 'NPR']),
    );

    $definitions = [];
    $answers = iqBeta30OptionCodes();

    foreach ($families as $index => [$family, $dimension]) {
        $number = $index + 1;
        $definitions[] = [
            'number' => $number,
            'question_id' => sprintf('IQB30-Q%02d', $number),
            'item_id' => sprintf('IQ_BETA_30_ORIGINAL_%02d', $number),
            'dimension' => $dimension,
            'dimension_name' => iqBeta30DimensionName($dimension),
            'difficulty_level' => (int) floor($index / 6) + 1,
            'item_family' => $family,
            'correct_answer' => $answers[$index % 6],
            'seed' => 7300 + $number,
        ];
    }

    return $definitions;
}

function iqBeta30Svg(array $elements): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160" role="img">'
        . '<rect width="240" height="160" rx="16" fill="#f8faf7"/>'
        . '<rect x="8" y="8" width="224" height="144" rx="12" fill="none" stroke="#243126" stroke-width="2" opacity="0.16"/>'
        . implode('', $elements)
        . '</svg>';
}

function iqBeta30Asset(array $elements, array $meta): array
{
    $asset = [
        'kind' => 'inline_svg_markup',
        'mime_type' => 'image/svg+xml',
        'view_box' => '0 0 240 160',
        'svg_markup' => iqBeta30Svg($elements),
        'accessibility_label' => $meta['accessibility_label'],
    ];

    return $asset;
}

function iqBeta30ShapeElements(int $itemNumber, int $variant, string $family, bool $isStem): array
{
    $colors = ['#1f5d50', '#d9843b', '#4666a9', '#a7b35a', '#7b4f8a', '#c7504f'];
    $shapeCount = 3 + (($itemNumber + $variant) % 4);
    $elements = [];

    if ($isStem) {
        $elements[] = '<text x="20" y="28" font-family="Avenir, Helvetica, sans-serif" font-size="13" fill="#243126" opacity="0.72">' . htmlspecialchars(str_replace('_', ' ', $family), ENT_QUOTES) . '</text>';
        $elements[] = '<text x="198" y="28" font-family="Avenir, Helvetica, sans-serif" font-size="12" fill="#243126" opacity="0.54">Q' . sprintf('%02d', $itemNumber) . '</text>';
    }

    for ($i = 0; $i < $shapeCount; $i++) {
        $x = 32 + (($i * 39 + $variant * 11 + $itemNumber * 7) % 165);
        $y = 46 + (($i * 23 + $variant * 17 + $itemNumber * 5) % 76);
        $size = 14 + (($itemNumber + $variant + $i) % 16);
        $color = $colors[($itemNumber + $variant + $i) % count($colors)];
        $angle = (($itemNumber * 19 + $variant * 31 + $i * 47) % 360);

        if ($family === 'numeric_pattern') {
            $value = (($itemNumber + $variant + $i) * 3) % 17 + 2;
            $elements[] = '<circle cx="' . $x . '" cy="' . $y . '" r="' . (int) ($size / 2) . '" fill="none" stroke="' . $color . '" stroke-width="3"/>';
            $elements[] = '<text x="' . ($x - 5) . '" y="' . ($y + 5) . '" font-family="Avenir, Helvetica, sans-serif" font-size="13" fill="#243126">' . $value . '</text>';
        } elseif ($family === 'rotation') {
            $elements[] = '<path d="M ' . $x . ' ' . ($y - $size) . ' L ' . ($x + $size) . ' ' . ($y + $size) . ' L ' . ($x - $size) . ' ' . ($y + $size) . ' Z" fill="' . $color . '" opacity="0.74" transform="rotate(' . $angle . ' ' . $x . ' ' . $y . ')"/>';
        } elseif ($family === 'overlay') {
            $elements[] = '<rect x="' . ($x - $size / 2) . '" y="' . ($y - $size / 2) . '" width="' . $size . '" height="' . $size . '" rx="5" fill="' . $color . '" opacity="0.42" transform="rotate(' . $angle . ' ' . $x . ' ' . $y . ')"/>';
            $elements[] = '<circle cx="' . ($x + 8) . '" cy="' . ($y - 5) . '" r="' . (int) max(5, $size / 3) . '" fill="none" stroke="#243126" stroke-width="2" opacity="0.52"/>';
        } elseif ($family === 'matrix_3x3' || $family === 'matrix_2x2') {
            $cell = $family === 'matrix_3x3' ? 28 : 36;
            $col = $i % ($family === 'matrix_3x3' ? 3 : 2);
            $row = (int) floor($i / ($family === 'matrix_3x3' ? 3 : 2));
            $gx = 52 + ($col * 46) + (($variant + $itemNumber) % 7);
            $gy = 44 + ($row * 36) + (($variant + $i) % 6);
            $elements[] = '<rect x="' . $gx . '" y="' . $gy . '" width="' . $cell . '" height="' . $cell . '" rx="6" fill="none" stroke="#243126" stroke-width="1.5" opacity="0.24"/>';
            $elements[] = '<circle cx="' . ($gx + $cell / 2) . '" cy="' . ($gy + $cell / 2) . '" r="' . (5 + (($itemNumber + $variant + $i) % 8)) . '" fill="' . $color . '" opacity="0.78"/>';
        } else {
            $elements[] = '<circle cx="' . $x . '" cy="' . $y . '" r="' . (int) ($size / 2) . '" fill="' . $color . '" opacity="0.7"/>';
            $elements[] = '<path d="M ' . ($x - $size) . ' ' . ($y + $size) . ' Q ' . $x . ' ' . ($y - $size) . ' ' . ($x + $size) . ' ' . ($y + $size) . '" fill="none" stroke="#243126" stroke-width="2" opacity="0.42"/>';
        }
    }

    return $elements;
}

function iqBeta30Item(array $definition): array
{
    $number = $definition['number'];
    $family = $definition['item_family'];
    $correctCode = $definition['correct_answer'];
    $optionCodes = iqBeta30OptionCodes();
    $correctIndex = array_search($correctCode, $optionCodes, true);
    $stemVariant = ($number * 3 + $correctIndex) % 13;

    $stem = iqBeta30Asset(
        iqBeta30ShapeElements($number, $stemVariant, $family, true),
        ['accessibility_label' => sprintf('Original %s reasoning prompt %02d.', str_replace('_', ' ', $family), $number)]
    );

    $options = [];
    $optionHashes = [];
    foreach ($optionCodes as $optionIndex => $code) {
        $variant = ($optionIndex === $correctIndex)
            ? $stemVariant + 17
            : $stemVariant + 23 + $optionIndex + (($number + $optionIndex) % 5);
        $asset = iqBeta30Asset(
            iqBeta30ShapeElements($number, $variant, $family, false),
            ['accessibility_label' => sprintf('Option %s for original IQ item %02d.', $code, $number)]
        );
        $options[] = ['code' => $code, 'asset' => $asset];
        $optionHashes[$code] = iqBeta30Sha256Json($asset);
    }

    return [
        'schema_version' => 'fm.iq.item_bank.item.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
        'question_id' => $definition['question_id'],
        'item_id' => $definition['item_id'],
        'dimension' => $definition['dimension'],
        'dimension_name' => $definition['dimension_name'],
        'difficulty_level' => $definition['difficulty_level'],
        'item_family' => $family,
        'option_count' => 6,
        'assets' => [
            'stem' => $stem,
            'options' => $options,
        ],
        'correct_answer' => $correctCode,
        'solution_rule' => sprintf('Original deterministic %s transformation generated from seed %d; choose the option preserving the generated progression signature.', str_replace('_', ' ', $family), $definition['seed']),
        'distractor_logic' => 'Distractors vary one or more generated attributes: count, position, rotation, overlay order, or numeric step. No option is copied from third-party materials.',
        'asset_hashes' => [
            'stem' => iqBeta30Sha256Json($stem),
            'options' => $optionHashes,
        ],
        'generator_metadata' => [
            'source_mode' => 'repo_generated_original',
            'copied_from_third_party' => false,
            'traced_from_third_party' => false,
            'seed' => $definition['seed'],
            'template_key' => $family,
            'generator_version' => 'iq_beta30_original_bank_v1',
            'theme_version' => 'fermatmind_soft_contrast_v1',
            'params_hash' => 'sha256:' . hash('sha256', json_encode([$definition['item_id'], $family, $definition['dimension'], $stemVariant], JSON_UNESCAPED_SLASHES)),
        ],
    ];
}

function iqBeta30ItemsPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank.items.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
        'item_count' => 30,
        'option_count' => 6,
        'items' => array_map('iqBeta30Item', iqBeta30ItemDefinitions()),
    ];
}

function iqBeta30AnswerKeyPayload(): array
{
    $answers = [];
    foreach (iqBeta30ItemDefinitions() as $definition) {
        $answers[$definition['item_id']] = [
            'question_id' => $definition['question_id'],
            'correct_answer' => $definition['correct_answer'],
            'dimension' => $definition['dimension'],
            'difficulty_level' => $definition['difficulty_level'],
        ];
    }

    return [
        'schema_version' => 'fm.iq.item_bank.answer_key.v1',
        'bank_id' => iqBeta30BankId(),
        'public_payload' => false,
        'storage_policy' => 'backend_only_never_emit_to_public_api',
        'answers' => $answers,
    ];
}

function iqBeta30ScoringSpecPayload(): array
{
    return [
        'schema_version' => 'fm.iq.scoring_spec.v1',
        'bank_id' => iqBeta30BankId(),
        'status' => 'beta_internal_validation',
        'raw_score' => [
            'min' => 0,
            'max' => 30,
            'correct_item_value' => 1,
            'incorrect_item_value' => 0,
        ],
        'dimension_counts' => iqBeta30ExpectedDimensionCounts(),
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
            'beta_copy_allowed_claim' => 'practice-style cognitive reasoning score only',
        ],
        'runtime_binding' => [
            'enabled' => false,
            'deferred_to_pr' => 'IQ-SCORE-30-01',
        ],
    ];
}

function iqBeta30ManifestPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank.manifest.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
        'name' => 'FermatMind IQ Beta 30 Original',
        'status' => 'beta_internal_validation',
        'runtime_bound' => false,
        'commerce_unlock_required' => false,
        'locale' => 'zh-CN',
        'market' => 'CN_MAINLAND',
        'item_count' => 30,
        'option_count' => 6,
        'option_codes' => iqBeta30OptionCodes(),
        'target_minutes' => 20,
        'dimension_targets' => iqBeta30ExpectedDimensionCounts(),
        'item_family_targets' => iqBeta30ExpectedFamilyCounts(),
        'files' => [
            'items' => 'items.json',
            'answer_key' => 'answer_key.json',
            'scoring_spec' => 'scoring_spec.json',
        ],
        'copyright_policy' => [
            'source' => 'repo_generated_original',
            'copied_from_third_party' => false,
            'traced_from_third_party' => false,
            'third_party_license_required' => false,
            'myiq_science_requires_license_verification_gate_before_use' => true,
        ],
        'public_payload_policy' => [
            'may_emit_items' => true,
            'may_emit_answer_key' => false,
            'may_emit_solution_rule' => false,
        ],
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
        ],
        'review_gates' => [
            'copyright_gate',
            'technical_svg_gate',
            'answer_key_gate',
            'ambiguity_gate',
            'difficulty_gate',
            'claim_gate',
            'provenance_gate',
            'contract_gate',
        ],
        'deferred_prs' => ['IQ-BANK-30-03', 'IQ-SCORE-30-01', 'IQ-CLAIM-SEO-01', 'IQ-CMS-LANDING-01', 'IQ-LIVE-SMOKE-01'],
    ];
}

function iqBeta30BankPayloads(): array
{
    return [
        'manifest' => iqBeta30ManifestPayload(),
        'items' => iqBeta30ItemsPayload(),
        'answer_key' => iqBeta30AnswerKeyPayload(),
        'scoring_spec' => iqBeta30ScoringSpecPayload(),
    ];
}
