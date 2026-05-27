<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TakeEnQuestionLocaleScan00Test extends TestCase
{
    #[Test]
    public function take_en_question_locale_scan_artifact_exists_with_expected_findings(): void
    {
        $reportPath = base_path('docs/seo/take-en-question-locale-scan-00.md');
        $generatedPath = base_path('docs/seo/generated/take-en-question-locale-scan-00.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $generated = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('take-en-question-locale-scan-00.v1', $generated['schema_version'] ?? null);
        $this->assertSame('TAKE-EN-QUESTION-LOCALE-SCAN-00', $generated['task'] ?? null);
        $this->assertSame('question_locale_scan_completed_ready_for_content_pack_fixes', $generated['final_decision'] ?? null);

        $matrix = $generated['question_locale_matrix'] ?? null;
        $this->assertIsArray($matrix);
        $this->assertCount(12, $matrix);

        $caseByKey = [];
        foreach ($matrix as $case) {
            $this->assertIsArray($case);
            $key = ($case['scale_code'] ?? '').'|'.($case['form_code'] ?? 'default');
            $caseByKey[$key] = $case;
        }

        foreach ([
            'MBTI|mbti_93',
            'MBTI|mbti_144',
            'RIASEC|riasec_60',
            'RIASEC|riasec_140',
            'ENNEAGRAM|enneagram_forced_choice_144',
        ] as $key) {
            $this->assertArrayHasKey($key, $caseByKey);
            $this->assertStringStartsWith('fail_', (string) ($caseByKey[$key]['visible_en_status'] ?? ''));
            $this->assertNotSame('no_action', $caseByKey[$key]['required_action'] ?? null);
        }

        foreach ([
            'BIG5_OCEAN|big5_90',
            'BIG5_OCEAN|big5_120',
            'ENNEAGRAM|enneagram_likert_105',
            'IQ_RAVEN|default',
            'EQ_60|default',
            'CLINICAL_COMBO_68|default',
            'SDS_20|default',
        ] as $key) {
            $this->assertArrayHasKey($key, $caseByKey);
            $this->assertStringStartsWith('pass_', (string) ($caseByKey[$key]['visible_en_status'] ?? ''));
            $this->assertSame('no_action', $caseByKey[$key]['required_action'] ?? null);
        }

        $recommendedTrain = $generated['recommended_pr_train'] ?? null;
        $this->assertIsArray($recommendedTrain);
        $this->assertSame([
            'MBTI-EN-QUESTIONS-PACK-PROJECTION-01',
            'RIASEC-EN-QUESTIONS-PACK-TRANSLATION-02',
            'ENNEAGRAM-FC144-EN-QUESTIONS-PACK-03',
        ], array_column($recommendedTrain, 'id'));

        $this->assertTrue($generated['no_api_mutation'] ?? false);
        $this->assertTrue($generated['no_cms_mutation'] ?? false);
        $this->assertTrue($generated['no_content_pack_mutation'] ?? false);
        $this->assertTrue($generated['no_publish'] ?? false);
        $this->assertTrue($generated['no_deploy'] ?? false);
        $this->assertTrue($generated['no_search_channel_action'] ?? false);
        $this->assertTrue($generated['no_url_submission'] ?? false);
        $this->assertSame('MBTI-EN-QUESTIONS-PACK-PROJECTION-01', $generated['next_task'] ?? null);
    }
}
