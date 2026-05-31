<?php

declare(strict_types=1);

use App\Services\Iq\IqResultPayloadRedactor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class IqBeta30OriginalBankImportTest extends TestCase
{
    private function runCommand(array $command): void
    {
        $process = new Process($command, base_path('..'));
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function generated_beta30_bank_artifacts_are_current_and_verified(): void
    {
        $this->runCommand(['php', 'backend/scripts/iq/build_iq_beta30_original_bank.php', '--check']);
        $this->runCommand(['php', 'backend/scripts/iq/verify_iq_beta30_original_bank.php']);
    }

    #[Test]
    public function generated_beta30_bank_has_provenance_hash_and_backend_answer_key_gates(): void
    {
        $itemsPayload = $this->readJson('items.json');
        $answerKey = $this->readJson('answer_key.json');
        $items = $itemsPayload['items'] ?? [];

        $this->assertSame('IQ_BETA_30_ORIGINAL', $itemsPayload['bank_id'] ?? null);
        $this->assertCount(30, $items);
        $this->assertFalse((bool) ($answerKey['public_payload'] ?? true));
        $this->assertSame('backend_only_never_emit_to_public_api', $answerKey['storage_policy'] ?? null);

        foreach ($items as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $this->assertNotSame('', $itemId);
            $this->assertSame($item['correct_answer'] ?? null, data_get($answerKey, "answers.{$itemId}.correct_answer"));
            $this->assertSame('repo_generated_original', data_get($item, 'generator_metadata.source_mode'));
            $this->assertFalse((bool) data_get($item, 'generator_metadata.copied_from_third_party', true));
            $this->assertFalse((bool) data_get($item, 'generator_metadata.traced_from_third_party', true));
            $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', (string) data_get($item, 'generator_metadata.params_hash'));
            $this->assertSame($this->assetHash($item['assets']['stem'] ?? []), data_get($item, 'asset_hashes.stem'));

            $optionCodes = [];
            foreach (($item['assets']['options'] ?? []) as $option) {
                $code = (string) ($option['code'] ?? '');
                $optionCodes[] = $code;
                $this->assertSame($this->assetHash($option['asset'] ?? []), data_get($item, "asset_hashes.options.{$code}"));
            }

            $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $optionCodes);
        }
    }

    #[Test]
    public function iq_public_payload_redactor_removes_beta30_answer_solution_and_provenance_private_fields(): void
    {
        $items = $this->readJson('items.json')['items'] ?? [];
        $this->assertNotEmpty($items);

        $redacted = IqResultPayloadRedactor::redactAnswerKeys([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => 'IQ_BETA_30_ORIGINAL',
            'items' => [$items[0]],
            'answer_key' => $this->readJson('answer_key.json'),
        ]);

        $this->assertSame('IQ_BETA_30_ORIGINAL', $redacted['bank_id'] ?? null);
        $this->assertSame('inline_svg_markup', data_get($redacted, 'items.0.assets.stem.kind'));
        $this->assertPayloadHasNoPrivateIqFields($redacted);
    }

    private function readJson(string $file): array
    {
        $path = base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_BETA_30_ORIGINAL/'.$file);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assetHash(array $asset): string
    {
        return 'sha256:'.hash('sha256', json_encode(
            $asset,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n");
    }

    private function assertPayloadHasNoPrivateIqFields(array $payload): void
    {
        $forbidden = [
            'answer_key',
            'answerKey',
            'correct_answer',
            'correctAnswer',
            'solution_rule',
            'solutionRule',
            'distractor_logic',
            'distractorLogic',
            'asset_hashes',
            'assetHashes',
            'generator_metadata',
            'generatorMetadata',
        ];

        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $forbidden);

            if (is_array($value)) {
                $this->assertPayloadHasNoPrivateIqFields($value);
            }
        }
    }
}
