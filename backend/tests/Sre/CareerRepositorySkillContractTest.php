<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CareerRepositorySkillContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function skillProvider(): iterable
    {
        yield 'canonical builder' => ['fap-api-career-canonical-builder'];
        yield 'content orchestrator' => ['fap-api-career-content-orchestrator'];
        yield 'editorial QA' => ['fermatmind-career-editorial-qa'];
    }

    #[DataProvider('skillProvider')]
    public function test_career_skill_name_matches_its_directory(string $name): void
    {
        $path = dirname(__DIR__, 3).'/.agents/skills/'.$name.'/SKILL.md';
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);
        self::assertMatchesRegularExpression('/\A---\nname: '.preg_quote($name, '/').'\ndescription: .+\n---\n/s', $contents);
    }

    public function test_career_skills_route_to_single_repository_authorities(): void
    {
        $root = dirname(__DIR__, 3).'/.agents/skills/';
        $builder = (string) file_get_contents($root.'fap-api-career-canonical-builder/SKILL.md');
        $orchestrator = (string) file_get_contents($root.'fap-api-career-content-orchestrator/SKILL.md');
        $editorial = (string) file_get_contents($root.'fermatmind-career-editorial-qa/SKILL.md');

        self::assertStringContainsString('career:ten-block-current-package-compile', $builder);
        self::assertStringContainsString('fap-api-career-release-authority', $builder.$orchestrator);
        self::assertStringContainsString('fap-api-deploy-sre', $orchestrator);
        self::assertStringContainsString('approved 1046 zh-CN master', $editorial);
        self::assertStringContainsString('advisory_only', (string) file_get_contents(
            $root.'fermatmind-career-editorial-qa/scripts/ai_trace_probe.py',
        ));
    }

    public function test_career_skills_do_not_embed_retired_control_planes(): void
    {
        $root = dirname(__DIR__, 3).'/.agents/skills/';
        $contents = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            [
                $root.'fap-api-career-canonical-builder/SKILL.md',
                $root.'fap-api-career-content-orchestrator/SKILL.md',
                $root.'fermatmind-career-editorial-qa/SKILL.md',
            ],
        ));

        self::assertStringNotContainsString('/Users/rainie/WorkBuddy/', $contents);
        self::assertStringNotContainsString('career-page-template.html', $contents);
        self::assertStringNotContainsString('PROD/_template', $contents);
        self::assertStringNotContainsString('ALLOW_PRODUCTION_DEPLOY', $contents);
        self::assertStringNotContainsString('BACKEND_DEPLOY_SHA', $contents);
        self::assertStringNotContainsString('production_import_approved', $contents);
    }
}
