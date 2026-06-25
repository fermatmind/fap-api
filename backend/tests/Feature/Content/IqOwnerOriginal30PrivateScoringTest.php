<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Iq\IqOwnerOriginal30BankService;
use App\Services\Iq\IqResultPayloadRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IqOwnerOriginal30PrivateScoringTest extends TestCase
{
    private function bankDir(): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_OWNER_ORIGINAL_30');
    }

    private function readJson(string $file): array
    {
        $payload = json_decode((string) file_get_contents($this->bankDir().'/'.$file), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    #[Test]
    public function private_answer_key_and_scoring_spec_are_present_and_runtime_bound(): void
    {
        $manifest = $this->readJson('manifest.json');
        $answerKey = $this->readJson('answer_key.json');
        $scoring = $this->readJson('scoring_spec.json');

        $this->assertSame('IQ_OWNER_ORIGINAL_30', $manifest['bank_id']);
        $this->assertSame('answer_key.json', $manifest['files']['answer_key']);
        $this->assertSame('scoring_spec.json', $manifest['files']['scoring_spec']);
        $this->assertTrue($manifest['runtime_bound']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_answer_key']);
        $this->assertFalse($answerKey['public_payload']);
        $this->assertSame('backend_only_never_emit_to_public_api', $answerKey['storage_policy']);
        $this->assertCount(30, $answerKey['answers']);
        $this->assertSame(30, $scoring['raw_score']['max']);
        $this->assertSame(1, $scoring['raw_score']['correct_item_value']);
        $this->assertFalse($scoring['norm_policy']['iq_claims_enabled']);
        $this->assertTrue($scoring['runtime_binding']['enabled']);
        $this->assertSame('backend_private_answer_key', $scoring['runtime_binding']['mode']);
    }

    #[Test]
    public function runtime_scoring_spec_matches_driver_contract_without_becoming_public_payload(): void
    {
        $spec = app(IqOwnerOriginal30BankService::class)->runtimeScoringSpec();

        $this->assertSame('IQ_OWNER_ORIGINAL_30', data_get($spec, 'item_bank.bank_id'));
        $this->assertSame('scored', $spec['scoring_mode'] ?? null);
        $this->assertSame('unavailable', $spec['norm_table_version'] ?? null);
        $this->assertFalse((bool) data_get($spec, 'norm_policy.iq_claims_enabled'));
        $this->assertFalse((bool) data_get($spec, 'public_payload_policy.may_emit_answer_key'));
        $this->assertCount(30, $spec['items'] ?? []);

        foreach (($spec['items'] ?? []) as $item) {
            $this->assertContains($item['correct_answer'] ?? null, ['A', 'B', 'C', 'D', 'E', 'F']);
            $this->assertContains($item['dimension'] ?? null, ['VSPR', 'VSI', 'NPR']);
            $this->assertNotEmpty($item['item_family'] ?? '');
            $this->assertNotEmpty($item['difficulty_level'] ?? '');
            $this->assertNotEmpty($item['solution_rule'] ?? '');
            $this->assertNotEmpty($item['distractor_logic'] ?? '');
            $this->assertNotEmpty($item['assets'] ?? []);
            $this->assertNotEmpty($item['asset_hashes'] ?? []);
            $this->assertNotEmpty($item['generator_metadata'] ?? []);
        }
    }

    #[Test]
    public function answer_key_covers_every_item_without_exposing_correct_answers_in_items(): void
    {
        $items = $this->readJson('items.json')['items'] ?? [];
        $answers = $this->readJson('answer_key.json')['answers'] ?? [];
        $this->assertCount(30, $items);
        $this->assertCount(30, $answers);

        foreach ($items as $index => $item) {
            $itemId = sprintf('IQ_OWNER_ORIGINAL_30_%02d', $index + 1);
            $this->assertSame($itemId, $item['item_id'] ?? null);
            $this->assertSame('private_backend_answer_key_available', $item['answer_key_status'] ?? null);
            $this->assertArrayHasKey($itemId, $answers);
            $this->assertSame($item['question_id'] ?? null, $answers[$itemId]['question_id'] ?? null);
            $this->assertContains($answers[$itemId]['correct_answer'] ?? null, ['A', 'B', 'C', 'D', 'E', 'F']);
            $this->assertPayloadHasNoPrivateIqFields($item);
        }
    }

    #[Test]
    public function redacted_public_payload_contains_no_answer_key_or_solution_fields(): void
    {
        $items = $this->readJson('items.json')['items'] ?? [];
        $this->assertNotEmpty($items);

        $redacted = IqResultPayloadRedactor::redactAnswerKeys([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'items' => [$items[0]],
            'answer_key' => $this->readJson('answer_key.json'),
            'scoring_spec' => $this->readJson('scoring_spec.json'),
        ]);

        $this->assertSame('IQ_OWNER_ORIGINAL_30', $redacted['bank_id'] ?? null);
        $this->assertPayloadHasNoPrivateIqFields($redacted);
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
