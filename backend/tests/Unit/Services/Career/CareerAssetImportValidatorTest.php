<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Import\CareerAssetImportValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAssetImportValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_actors_as_the_only_ready_for_pilot_row_and_gates_missing_soc_placeholders(): void
    {
        $validator = app(CareerAssetImportValidator::class);

        $report = $validator->validate([
            $this->placeholderRow('accountants-and-auditors'),
            $this->actorsRow(),
            $this->placeholderRow('actuaries'),
        ], CareerAssetImportValidator::expectedHeaders());

        $this->assertTrue($report['header_exact_match']);
        $this->assertSame(3, $report['total_rows_processed']);
        $this->assertTrue($report['actors_integrity_pass']);
        $this->assertSame(2, $report['rows_with_missing_soc']);
        $this->assertSame(2, $report['rows_with_missing_links']);
        $this->assertSame(2, $report['blocked_from_sitemap']);
        $this->assertSame(2, $report['blocked_from_llms_full']);
        $this->assertSame(['actors'], $report['ready_for_pilot']);
        $this->assertSame([], $report['json_parse_errors']);
        $this->assertSame([], $report['schema_errors']);
        $this->assertSame([
            'needs_source_code' => 2,
            'ready_for_pilot' => 1,
        ], $report['normalized_release_status_counts']);
        $this->assertSame('pass_for_database_import_test', $report['import_decision']);
        $this->assertSame('actors_only_ready_for_pilot_validation', $report['release_decision']);
        $this->assertFalse($report['release_status_rows_sample'][0]['ready_for_sitemap']);
        $this->assertFalse($report['release_status_rows_sample'][0]['ready_for_llms_full']);
        $this->assertFalse($report['release_status_rows_sample'][0]['ready_for_paid']);
        $this->assertNull($report['release_status_rows_sample'][0]['normalized_EN_Occupation_Schema_JSON']);
        $this->assertNull($report['release_status_rows_sample'][0]['normalized_CN_Occupation_Schema_JSON']);
        $this->assertFalse($report['release_status_rows_sample'][1]['ready_for_paid']);
    }

    #[Test]
    public function it_does_not_parse_non_actor_content_json_fields(): void
    {
        $row = $this->placeholderRow('accountants-and-auditors');
        $row['EN_FAQ_SCHEMA_JSON'] = '{invalid-json';
        $row['EN_Occupation_Schema_JSON'] = '{invalid-json';

        $report = app(CareerAssetImportValidator::class)->validate([
            $row,
            $this->actorsRow(),
        ], CareerAssetImportValidator::expectedHeaders());

        $this->assertSame([], $report['json_parse_errors']);
        $this->assertTrue($report['actors_integrity_pass']);
    }

    #[Test]
    public function it_fails_when_actor_occupation_schema_contains_forbidden_public_content(): void
    {
        $row = $this->actorsRow();
        $row['EN_Occupation_Schema_JSON'] = json_encode([
            '@type' => 'Occupation',
            'occupationalCategory' => '27-2011',
            'mainEntityOfPage' => 'https://example.test/careers/actors',
            'description' => 'AI Exposure should stay out of public Occupation schema.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $report = app(CareerAssetImportValidator::class)->validate([
            $row,
        ], CareerAssetImportValidator::expectedHeaders());

        $this->assertFalse($report['actors_integrity_pass']);
        $this->assertSame('fail_import_validation', $report['import_decision']);
        $this->assertContains('AI Exposure', array_column($report['schema_errors'], 'needle'));
    }

    /**
     * @return array<string, string>
     */
    public static function actorsRow(): array
    {
        $row = self::baseRow();
        $row['Asset_Version'] = 'v4.2';
        $row['Locale'] = 'en+zh';
        $row['Slug'] = 'actors';
        $row['Job_ID'] = 'career_job:actors';
        $row['SOC_Code'] = '27-2011';
        $row['O_NET_Code'] = '27-2011.00';
        $row['EN_Title'] = 'Actors';
        $row['CN_Title'] = '演员';
        $row['Content_Status'] = 'approved';
        $row['Review_State'] = 'human_reviewed';
        $row['Release_Status'] = 'ready_for_pilot';
        $row['EN_H1'] = 'Actors';
        $row['CN_H1'] = '演员';
        $row['EN_Quick_Answer'] = 'Actors interpret roles for audiences.';
        $row['CN_Quick_Answer'] = '演员通过表演呈现角色。';
        $row['Primary_CTA_URL'] = '/tests/riasec';
        $row['Primary_CTA_Target_Action'] = 'start_riasec_test';
        $row['Entry_Surface'] = 'career_job_detail';
        $row['Subject_Slug'] = 'actors';
        $row['Ready_For_Sitemap'] = 'true';
        $row['Ready_For_LLMS'] = 'true';
        $row['Ready_For_Paid'] = 'false';

        foreach ([
            'EN_Target_Queries',
            'CN_Target_Queries',
            'Search_Intent_Type',
            'EN_Snapshot_Data',
            'CN_Snapshot_Data',
            'EN_Responsibilities',
            'CN_Responsibilities',
            'EN_Comparison_Block',
            'CN_Comparison_Block',
            'EN_RIASEC_Fit',
            'CN_RIASEC_Fit',
            'EN_Personality_Fit',
            'CN_Personality_Fit',
            'EN_Next_Steps',
            'CN_Next_Steps',
            'AI_Exposure_Explanation',
            'Claim_Level_Source_Refs',
            'Secondary_CTA_URL',
        ] as $field) {
            $row[$field] = json_encode(['value' => $field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $row['EN_How_To_Decide_Fit'] = json_encode([
            'fit' => 'Acting may fit you if you like rehearsing, feedback, and live performance.',
            'caution' => 'Be careful if unstable schedules or public critique drain you.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['CN_How_To_Decide_Fit'] = json_encode([
            'fit' => '更适合你，如果你享受排练、表达和现场反馈。',
            'caution' => '需要谨慎，如果不稳定收入或公开评价会持续消耗你。',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['EN_FAQ_SCHEMA_JSON'] = self::faqSchema('What do actors do?', 'Actors perform roles.');
        $row['CN_FAQ_SCHEMA_JSON'] = self::faqSchema('演员做什么？', '演员呈现角色。');
        $row['EN_Occupation_Schema_JSON'] = self::occupationSchema('https://example.test/careers/actors');
        $row['CN_Occupation_Schema_JSON'] = self::occupationSchema('https://example.test/zh/careers/actors');
        $row['EN_Internal_Links'] = json_encode([['slug' => 'performing-arts']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['CN_Internal_Links'] = json_encode([['slug' => 'performing-arts']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $row;
    }

    /**
     * @return array<string, string>
     */
    public static function placeholderRow(string $slug): array
    {
        $row = self::baseRow();
        $row['Asset_Version'] = 'v4.1';
        $row['Locale'] = 'en+zh';
        $row['Slug'] = $slug;
        $row['Job_ID'] = 'career_job:'.$slug;
        $row['Content_Status'] = 'pending_production';
        $row['Review_State'] = 'pending_production';
        $row['Release_Status'] = 'pending_production';
        $row['EN_Internal_Links'] = '[]';
        $row['CN_Internal_Links'] = '[]';

        return $row;
    }

    /**
     * @return array<string, string>
     */
    private static function baseRow(): array
    {
        return array_fill_keys(CareerAssetImportValidator::expectedHeaders(), '');
    }

    private static function faqSchema(string $question, string $answer): string
    {
        return json_encode([
            '@type' => 'FAQPage',
            'mainEntity' => [[
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function occupationSchema(string $url): string
    {
        return json_encode([
            '@type' => 'Occupation',
            'occupationalCategory' => '27-2011',
            'mainEntityOfPage' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
