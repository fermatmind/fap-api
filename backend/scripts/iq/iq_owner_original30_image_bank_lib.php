<?php

declare(strict_types=1);

function iqOwner30RepoRoot(): string
{
    return dirname(__DIR__, 3);
}

function iqOwner30BankId(): string
{
    return 'IQ_OWNER_ORIGINAL_30';
}

function iqOwner30PackDir(): string
{
    return iqOwner30RepoRoot().'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO';
}

function iqOwner30BankDir(): string
{
    return iqOwner30PackDir().'/banks/'.iqOwner30BankId();
}

function iqOwner30FileMap(): array
{
    $dir = iqOwner30BankDir();

    return [
        'manifest' => $dir.'/manifest.json',
        'items' => $dir.'/items.json',
        'asset_inventory' => $dir.'/asset_inventory.json',
        'answer_key' => $dir.'/answer_key.json',
        'scoring_spec' => $dir.'/scoring_spec.json',
    ];
}

function iqOwner30PrettyJson(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

function iqOwner30OptionCodes(): array
{
    return ['A', 'B', 'C', 'D', 'E', 'F'];
}

function iqOwner30ReadJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid JSON: '.$path);
    }

    return $decoded;
}

function iqOwner30SourceQuestionDir(string $sourceDir, int $questionNumber): string
{
    foreach (['Q'.$questionNumber, 'q'.$questionNumber] as $name) {
        $dir = rtrim($sourceDir, '/').'/'.$name;
        if (is_dir($dir)) {
            return $dir;
        }
    }

    throw new RuntimeException(sprintf('Missing source directory for Q%d under %s', $questionNumber, $sourceDir));
}

function iqOwner30FindSourceFile(string $questionDir, int $questionNumber, string $role, ?string $optionCode = null): string
{
    $prefix = 'q'.$questionNumber.'-';
    $name = $role === 'question'
        ? $prefix.'question.*'
        : $prefix.'option-'.strtolower((string) $optionCode).'.*';
    $matches = glob($questionDir.'/'.$name);
    if (! is_array($matches) || count($matches) !== 1) {
        throw new RuntimeException(sprintf('Expected exactly one %s asset for Q%d in %s', $role, $questionNumber, $questionDir));
    }

    return $matches[0];
}

function iqOwner30FindSourceManifest(string $questionDir, int $questionNumber): ?array
{
    foreach (['q'.$questionNumber.'-manifest.json', 'manifest.json'] as $name) {
        $path = $questionDir.'/'.$name;
        if (is_file($path)) {
            return iqOwner30ReadJson($path);
        }
    }

    return null;
}

function iqOwner30MediaType(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => throw new RuntimeException('Unsupported image extension: '.$path),
    };
}

function iqOwner30ImageMetadata(string $path): array
{
    $size = getimagesize($path);
    if (! is_array($size)) {
        throw new RuntimeException('Unable to read image dimensions: '.$path);
    }

    return [
        'media_type' => iqOwner30MediaType($path),
        'byte_length' => filesize($path),
        'width' => $size[0],
        'height' => $size[1],
        'sha256' => hash_file('sha256', $path),
    ];
}

function iqOwner30ManifestHashes(?array $sourceManifest): array
{
    $hashes = [];
    foreach (($sourceManifest['files'] ?? []) as $file) {
        if (! is_array($file)) {
            continue;
        }
        $filename = (string) ($file['filename'] ?? '');
        $sha = (string) ($file['sha256'] ?? '');
        if ($filename !== '' && $sha !== '') {
            $hashes[$filename] = $sha;
        }
    }

    return $hashes;
}

function iqOwner30AssertSourceHash(string $filename, array $sourceHashes, array $metadata): void
{
    if ($sourceHashes === []) {
        return;
    }
    if (! isset($sourceHashes[$filename])) {
        throw new RuntimeException('Missing source manifest hash for '.$filename);
    }
    if ($sourceHashes[$filename] !== $metadata['sha256']) {
        throw new RuntimeException('Source manifest hash mismatch for '.$filename);
    }
}

function iqOwner30DestinationRelativePath(int $questionNumber, string $filename): string
{
    return sprintf('assets/iq_owner_original_30/q%02d/%s', $questionNumber, $filename);
}

function iqOwner30DestinationAbsolutePath(string $relativePath): string
{
    return iqOwner30PackDir().'/'.$relativePath;
}

function iqOwner30BuildPayloadsFromSource(string $sourceDir, bool $copyAssets): array
{
    $items = [];
    $inventoryAssets = [];

    for ($questionNumber = 1; $questionNumber <= 30; $questionNumber++) {
        $questionDir = iqOwner30SourceQuestionDir($sourceDir, $questionNumber);
        $sourceManifest = iqOwner30FindSourceManifest($questionDir, $questionNumber);
        $sourceHashes = iqOwner30ManifestHashes($sourceManifest);
        $stemSource = iqOwner30FindSourceFile($questionDir, $questionNumber, 'question');
        $stemFilename = sprintf('q%d-question.%s', $questionNumber, strtolower(pathinfo($stemSource, PATHINFO_EXTENSION)));
        $stemMetadata = iqOwner30ImageMetadata($stemSource);
        iqOwner30AssertSourceHash($stemFilename, $sourceHashes, $stemMetadata);
        $stemRelative = iqOwner30DestinationRelativePath($questionNumber, $stemFilename);

        if ($copyAssets) {
            iqOwner30CopyAsset($stemSource, $stemRelative);
        }

        $options = [];
        $optionHashes = [];
        foreach (iqOwner30OptionCodes() as $code) {
            $optionSource = iqOwner30FindSourceFile($questionDir, $questionNumber, 'option', $code);
            $optionFilename = sprintf('q%d-option-%s.%s', $questionNumber, strtolower($code), strtolower(pathinfo($optionSource, PATHINFO_EXTENSION)));
            $optionMetadata = iqOwner30ImageMetadata($optionSource);
            iqOwner30AssertSourceHash($optionFilename, $sourceHashes, $optionMetadata);
            $optionRelative = iqOwner30DestinationRelativePath($questionNumber, $optionFilename);

            if ($copyAssets) {
                iqOwner30CopyAsset($optionSource, $optionRelative);
            }

            $options[] = [
                'code' => $code,
                'label' => $code,
                'type' => 'image',
                'media_type' => $optionMetadata['media_type'],
                'assets' => [
                    'image' => $optionRelative,
                ],
                'width' => $optionMetadata['width'],
                'height' => $optionMetadata['height'],
                'sha256' => 'sha256:'.$optionMetadata['sha256'],
                'accessibility_label' => sprintf('Option %s for owner-original IQ item %02d.', $code, $questionNumber),
            ];
            $optionHashes[$code] = 'sha256:'.$optionMetadata['sha256'];
            $inventoryAssets[] = iqOwner30InventoryEntry($questionNumber, 'option', $code, $optionRelative, $optionMetadata);
        }

        $items[] = [
            'schema_version' => 'fm.iq.owner_image_bank.item.v1',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => iqOwner30BankId(),
            'question_id' => sprintf('IQOWNER30-Q%02d', $questionNumber),
            'item_id' => sprintf('IQ_OWNER_ORIGINAL_30_%02d', $questionNumber),
            'sequence' => $questionNumber,
            'title' => '选择合适的形状来替换缺失的形状。',
            'stem' => [
                'type' => 'image',
                'media_type' => $stemMetadata['media_type'],
                'assets' => [
                    'image' => $stemRelative,
                ],
                'width' => $stemMetadata['width'],
                'height' => $stemMetadata['height'],
                'sha256' => 'sha256:'.$stemMetadata['sha256'],
                'accessibility_label' => sprintf('Owner-original IQ prompt %02d.', $questionNumber),
            ],
            'options' => $options,
            'asset_hashes' => [
                'stem' => 'sha256:'.$stemMetadata['sha256'],
                'options' => $optionHashes,
            ],
            'answer_key_status' => 'private_backend_answer_key_available',
            'provenance' => iqOwner30Provenance(),
        ];
        $inventoryAssets[] = iqOwner30InventoryEntry($questionNumber, 'stem', null, $stemRelative, $stemMetadata);
    }

    return [
        'manifest' => iqOwner30ManifestPayload(),
        'items' => iqOwner30ItemsPayload($items),
        'asset_inventory' => iqOwner30AssetInventoryPayload($inventoryAssets),
    ];
}

function iqOwner30CopyAsset(string $sourcePath, string $relativePath): void
{
    $dest = iqOwner30DestinationAbsolutePath($relativePath);
    if (! is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0775, true);
    }
    if (! copy($sourcePath, $dest)) {
        throw new RuntimeException('Failed to copy '.$sourcePath.' to '.$dest);
    }
}

function iqOwner30Provenance(): array
{
    return [
        'source_mode' => 'owner_original_local_asset_import',
        'declared_original_owner' => 'FermatMind / Rainie',
        'rights_basis' => 'owner_declared_original_work_and_authorized_fermatmind_use',
        'copied_from_third_party' => false,
        'traced_from_third_party' => false,
        'third_party_license_required' => false,
        'public_answer_or_solution_included' => false,
    ];
}

function iqOwner30ManifestPayload(): array
{
    return [
        'schema_version' => 'fm.iq.owner_image_bank.manifest.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqOwner30BankId(),
        'name' => 'FermatMind IQ Owner Original 30',
        'status' => 'owner_original_imported_runtime_unbound',
        'runtime_bound' => false,
        'locale' => 'zh-CN',
        'market' => 'CN_MAINLAND',
        'item_count' => 30,
        'option_count' => 6,
        'option_codes' => iqOwner30OptionCodes(),
        'target_minutes' => 20,
        'files' => [
            'items' => 'items.json',
            'asset_inventory' => 'asset_inventory.json',
            'answer_key' => 'answer_key.json',
            'scoring_spec' => 'scoring_spec.json',
        ],
        'asset_root' => 'assets/iq_owner_original_30',
        'ownership_policy' => iqOwner30Provenance(),
        'public_payload_policy' => [
            'may_emit_items' => true,
            'may_emit_image_assets' => true,
            'may_emit_answer_key' => false,
            'may_emit_solution_rule' => false,
            'may_emit_source_capture_urls' => false,
        ],
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
        ],
        'review_gates' => [
            'asset_integrity_gate',
            'ownership_gate',
            'answer_key_gate',
            'scoring_gate',
            'frontend_renderer_gate',
            'runtime_switch_gate',
        ],
        'deferred_prs' => [
            'IQ-OWNER-30-BACKEND-SCORING-02',
            'IQ-OWNER-30-FE-IMAGE-RENDERER-03',
            'IQ-OWNER-30-FE-FORMCODE-04',
            'IQ-SESSION-QUESTION-DELIVERY-05',
        ],
    ];
}

function iqOwner30ItemsPayload(array $items): array
{
    return [
        'schema_version' => 'fm.iq.owner_image_bank.items.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqOwner30BankId(),
        'item_count' => 30,
        'option_count' => 6,
        'items' => $items,
    ];
}

function iqOwner30InventoryEntry(
    int $questionNumber,
    string $role,
    ?string $optionCode,
    string $relativePath,
    array $metadata
): array {
    $entry = [
        'question_index' => $questionNumber,
        'role' => $role,
        'path' => $relativePath,
        'media_type' => $metadata['media_type'],
        'byte_length' => $metadata['byte_length'],
        'width' => $metadata['width'],
        'height' => $metadata['height'],
        'sha256' => 'sha256:'.$metadata['sha256'],
    ];
    if ($optionCode !== null) {
        $entry['option_code'] = $optionCode;
    }

    return $entry;
}

function iqOwner30AssetInventoryPayload(array $assets): array
{
    usort($assets, static fn (array $a, array $b): int => [$a['question_index'], $a['role'], $a['option_code'] ?? ''] <=> [$b['question_index'], $b['role'], $b['option_code'] ?? '']);

    return [
        'schema_version' => 'fm.iq.owner_image_bank.asset_inventory.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqOwner30BankId(),
        'asset_count' => 210,
        'assets' => $assets,
    ];
}

function iqOwner30WritePayloads(array $payloads): void
{
    foreach ($payloads as $key => $payload) {
        $path = iqOwner30FileMap()[$key];
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, iqOwner30PrettyJson($payload));
    }
}

function iqOwner30VerifyCommittedArtifacts(): void
{
    $files = iqOwner30FileMap();
    foreach ($files as $path) {
        if (! is_file($path)) {
            throw new RuntimeException('Missing bank artifact: '.$path);
        }
    }

    $manifest = iqOwner30ReadJson($files['manifest']);
    $itemsPayload = iqOwner30ReadJson($files['items']);
    $inventory = iqOwner30ReadJson($files['asset_inventory']);

    if (($manifest['bank_id'] ?? null) !== iqOwner30BankId() || ($itemsPayload['bank_id'] ?? null) !== iqOwner30BankId()) {
        throw new RuntimeException('Bank id mismatch');
    }
    if (($manifest['runtime_bound'] ?? false) !== true) {
        throw new RuntimeException('Owner bank must be runtime-bound after scoring runtime bind');
    }
    if (($manifest['public_payload_policy']['may_emit_answer_key'] ?? true) !== false) {
        throw new RuntimeException('Manifest must not allow public answer key emission');
    }
    if (($manifest['ownership_policy']['copied_from_third_party'] ?? true) !== false) {
        throw new RuntimeException('Ownership policy must declare no third-party copying');
    }

    $items = $itemsPayload['items'] ?? [];
    if (! is_array($items) || count($items) !== 30) {
        throw new RuntimeException('Expected 30 items');
    }
    $assets = $inventory['assets'] ?? [];
    if (! is_array($assets) || count($assets) !== 210 || ($inventory['asset_count'] ?? null) !== 210) {
        throw new RuntimeException('Expected 210 inventory assets');
    }

    $seenIds = [];
    foreach ($items as $index => $item) {
        $questionNumber = $index + 1;
        $itemId = (string) ($item['item_id'] ?? '');
        if ($itemId === '' || isset($seenIds[$itemId])) {
            throw new RuntimeException('Missing or duplicate item id: '.$itemId);
        }
        $seenIds[$itemId] = true;
        if (($item['answer_key_status'] ?? '') !== 'private_backend_answer_key_available') {
            throw new RuntimeException($itemId.' must declare private backend answer key availability');
        }
        iqOwner30VerifyAssetNode((array) ($item['stem'] ?? []), $questionNumber, 'stem');
        $options = $item['options'] ?? [];
        if (! is_array($options) || count($options) !== 6) {
            throw new RuntimeException($itemId.' must have six options');
        }
        foreach ($options as $option) {
            $code = (string) ($option['code'] ?? '');
            if (! in_array($code, iqOwner30OptionCodes(), true)) {
                throw new RuntimeException($itemId.' invalid option code '.$code);
            }
            iqOwner30VerifyAssetNode((array) $option, $questionNumber, 'option '.$code);
        }
    }

    foreach ($assets as $asset) {
        iqOwner30VerifyInventoryAsset((array) $asset);
    }
}

function iqOwner30VerifyPrivateScoringArtifacts(): void
{
    iqOwner30VerifyCommittedArtifacts();

    $files = iqOwner30FileMap();
    $manifest = iqOwner30ReadJson($files['manifest']);
    $itemsPayload = iqOwner30ReadJson($files['items']);
    $answerKey = iqOwner30ReadJson($files['answer_key']);
    $scoring = iqOwner30ReadJson($files['scoring_spec']);

    if (($manifest['files']['answer_key'] ?? null) !== 'answer_key.json') {
        throw new RuntimeException('Manifest must point to the private answer_key.json');
    }
    if (($manifest['files']['scoring_spec'] ?? null) !== 'scoring_spec.json') {
        throw new RuntimeException('Manifest must point to scoring_spec.json');
    }
    if (($manifest['public_payload_policy']['may_emit_answer_key'] ?? true) !== false) {
        throw new RuntimeException('Manifest must keep answer key out of public payloads');
    }
    if (($manifest['public_payload_policy']['may_emit_solution_rule'] ?? true) !== false) {
        throw new RuntimeException('Manifest must keep solution rules out of public payloads');
    }
    if (($answerKey['public_payload'] ?? true) !== false) {
        throw new RuntimeException('Answer key must not be public payload');
    }
    if (($answerKey['storage_policy'] ?? '') !== 'backend_only_never_emit_to_public_api') {
        throw new RuntimeException('Answer key storage policy must be backend-only');
    }
    if (($scoring['runtime_binding']['enabled'] ?? false) !== true) {
        throw new RuntimeException('Scoring spec must be runtime-bound');
    }
    if (($scoring['runtime_binding']['mode'] ?? '') !== 'backend_private_answer_key') {
        throw new RuntimeException('Scoring runtime binding must use backend private answer key mode');
    }
    if (($scoring['norm_policy']['iq_claims_enabled'] ?? true) !== false) {
        throw new RuntimeException('IQ claims must remain disabled before norm authority');
    }

    $items = $itemsPayload['items'] ?? [];
    $answers = $answerKey['answers'] ?? [];
    if (! is_array($items) || count($items) !== 30 || ! is_array($answers) || count($answers) !== 30) {
        throw new RuntimeException('Expected 30 items and 30 private answers');
    }

    $dimensionCounts = [];
    foreach ($items as $index => $item) {
        $itemId = (string) ($item['item_id'] ?? '');
        $questionId = (string) ($item['question_id'] ?? '');
        if ($itemId === '' || ! isset($answers[$itemId])) {
            throw new RuntimeException('Missing private answer for '.$itemId);
        }

        iqOwner30AssertNoPublicAnswerLeak((array) $item, $itemId);

        $answer = (array) $answers[$itemId];
        if (($answer['question_id'] ?? null) !== $questionId) {
            throw new RuntimeException('Question id mismatch for '.$itemId);
        }
        $correct = (string) ($answer['correct_answer'] ?? '');
        if (! in_array($correct, iqOwner30OptionCodes(), true)) {
            throw new RuntimeException('Invalid correct answer for '.$itemId);
        }
        $dimension = (string) ($answer['dimension'] ?? '');
        if ($dimension === '') {
            throw new RuntimeException('Missing scoring dimension for '.$itemId);
        }
        $dimensionCounts[$dimension] = ($dimensionCounts[$dimension] ?? 0) + 1;
        $difficulty = $answer['difficulty_level'] ?? null;
        if (! is_int($difficulty) || $difficulty < 1 || $difficulty > 5) {
            throw new RuntimeException('Invalid difficulty level for '.$itemId);
        }

        $expectedItemId = sprintf('IQ_OWNER_ORIGINAL_30_%02d', $index + 1);
        if ($itemId !== $expectedItemId) {
            throw new RuntimeException('Unexpected item id order: '.$itemId);
        }
    }

    ksort($dimensionCounts);
    $scoringCounts = (array) ($scoring['dimension_counts'] ?? []);
    ksort($scoringCounts);
    if ($scoringCounts !== $dimensionCounts) {
        throw new RuntimeException('Scoring dimension counts do not match answer key');
    }
    if (($scoring['raw_score']['max'] ?? null) !== 30 || ($scoring['raw_score']['correct_item_value'] ?? null) !== 1) {
        throw new RuntimeException('Raw score spec must score 30 one-point items');
    }
}

function iqOwner30AssertNoPublicAnswerLeak(array $node, string $context): void
{
    $forbidden = [
        'answer_key',
        'answerKey',
        'correct_answer',
        'correctAnswer',
        'solution_rule',
        'solutionRule',
    ];

    foreach ($node as $key => $value) {
        if (is_string($key) && in_array($key, $forbidden, true)) {
            throw new RuntimeException('Public item payload leaks private field '.$key.' in '.$context);
        }
        if (is_array($value)) {
            iqOwner30AssertNoPublicAnswerLeak($value, $context);
        }
    }
}

function iqOwner30VerifyAssetNode(array $node, int $questionNumber, string $context): void
{
    $relative = (string) (($node['assets']['image'] ?? ''));
    if ($relative === '') {
        throw new RuntimeException(sprintf('Missing image asset for Q%02d %s', $questionNumber, $context));
    }
    $path = iqOwner30DestinationAbsolutePath($relative);
    if (! is_file($path)) {
        throw new RuntimeException('Missing image file: '.$relative);
    }
    $metadata = iqOwner30ImageMetadata($path);
    if (($node['sha256'] ?? '') !== 'sha256:'.$metadata['sha256']) {
        throw new RuntimeException('Hash mismatch for '.$relative);
    }
    if (($node['width'] ?? null) !== $metadata['width'] || ($node['height'] ?? null) !== $metadata['height']) {
        throw new RuntimeException('Dimension mismatch for '.$relative);
    }
    if (($node['media_type'] ?? null) !== $metadata['media_type']) {
        throw new RuntimeException('Media type mismatch for '.$relative);
    }
}

function iqOwner30VerifyInventoryAsset(array $asset): void
{
    $relative = (string) ($asset['path'] ?? '');
    if ($relative === '') {
        throw new RuntimeException('Inventory asset missing path');
    }
    $path = iqOwner30DestinationAbsolutePath($relative);
    if (! is_file($path)) {
        throw new RuntimeException('Inventory asset missing file: '.$relative);
    }
    $metadata = iqOwner30ImageMetadata($path);
    foreach (['width', 'height', 'byte_length'] as $key) {
        if (($asset[$key] ?? null) !== $metadata[$key]) {
            throw new RuntimeException('Inventory '.$key.' mismatch for '.$relative);
        }
    }
    if (($asset['sha256'] ?? '') !== 'sha256:'.$metadata['sha256']) {
        throw new RuntimeException('Inventory hash mismatch for '.$relative);
    }
    if (($asset['media_type'] ?? '') !== $metadata['media_type']) {
        throw new RuntimeException('Inventory media type mismatch for '.$relative);
    }
}
