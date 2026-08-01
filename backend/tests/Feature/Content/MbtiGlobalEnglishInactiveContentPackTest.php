<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentLintService;
use App\Services\Content\ContentPacksIndexFallbackScanner;
use Tests\TestCase;

final class MbtiGlobalEnglishInactiveContentPackTest extends TestCase
{
    private const PACK_ID = 'MBTI.global.en.default';

    private const DIRECTORY_VERSION = 'MBTI-GLOBAL-en-v0.3';

    private const FORBIDDEN_PRIVATE_KEYS = [
        'attempt_id',
        'attempt_uuid',
        'report_token',
        'result_lookup_token',
        'share_token',
        'user_id',
        'account_id',
        'email',
        'phone',
        'user_scores',
        'raw_scores',
        'answers',
        'answer_key',
        'orders',
        'payments',
        'recovery_data',
        'secret',
        'cookie',
        'authorization',
    ];

    public function test_pack_is_exactly_the_new_global_english_inactive_authority(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $version = $this->jsonFile('version.json');
        $commercial = $this->jsonFile('commercial_spec.json');

        self::assertSame('pack-manifest@v1', $manifest['schema_version']);
        self::assertSame('content_pack', $manifest['pack_type']);
        self::assertSame('MBTI', $manifest['scale_code']);
        self::assertSame('GLOBAL', $manifest['region']);
        self::assertSame('en', $manifest['locale']);
        self::assertSame('v0.3', $manifest['content_package_version']);
        self::assertSame(self::PACK_ID, $manifest['pack_id']);
        self::assertSame([], $manifest['fallback']);
        self::assertSame('inactive_draft', data_get($manifest, 'lifecycle.state'));
        self::assertFalse(data_get($manifest, 'lifecycle.runtime_available'));
        self::assertFalse(data_get($manifest, 'lifecycle.active_pointer_registered'));
        self::assertFalse(data_get($manifest, 'lifecycle.publication_allowed'));
        self::assertFalse(data_get($manifest, 'lifecycle.indexability_allowed'));
        self::assertSame([
            'rules' => ['commercial_spec.json'],
        ], $manifest['assets']);

        self::assertSame(self::PACK_ID, $version['pack_id']);
        self::assertSame(self::DIRECTORY_VERSION, $version['dir_version']);
        self::assertSame('v0.3', $version['content_package_version']);
        self::assertSame('inactive_draft', $version['authority_state']);
        self::assertFalse($version['runtime_available']);

        self::assertSame('fap.report.rules.v1', $commercial['schema']);
        self::assertSame('MBTI', $commercial['scale_code']);
        self::assertSame('GLOBAL', $commercial['region']);
        self::assertSame('en', $commercial['locale']);
        self::assertSame('inactive_draft', $commercial['authority_state']);
        self::assertFalse($commercial['runtime_available']);
        self::assertFalse($commercial['active_pointer_registered']);
        self::assertSame([], $commercial['offers']);
        self::assertSame([], $commercial['variants']);
        self::assertSame([
            'assessment_role' => 'structured_reference_and_working_hypothesis',
            'diagnosis' => false,
            'fixed_identity' => false,
            'ability_judgment' => false,
            'outcome_prediction' => false,
        ], $commercial['claim_boundary']);
        foreach ($commercial['permissions'] as $permission) {
            self::assertFalse($permission);
        }
    }

    public function test_pack_is_not_registered_as_a_runtime_pack_or_fallback(): void
    {
        $packRoot = dirname($this->packDirectory(), 4);
        $index = (new ContentPacksIndexFallbackScanner)->scan($packRoot, 'local', [
            'default_pack_id' => self::PACK_ID,
            'default_dir_version' => self::DIRECTORY_VERSION,
        ]);

        self::assertTrue($index['ok']);
        self::assertArrayNotHasKey(self::PACK_ID, $index['by_pack_id']);
        self::assertFalse(collect($index['items'])->contains(
            static fn (array $item): bool => ($item['pack_id'] ?? null) === self::PACK_ID,
        ));
        self::assertFileDoesNotExist($this->packDirectory().'/questions.json');
    }

    public function test_pack_passes_focused_lint_claim_and_private_field_boundaries(): void
    {
        $lint = $this->app->make(ContentLintService::class)->lintAll(self::PACK_ID);
        self::assertTrue($lint['ok'], json_encode($lint['packs'], JSON_PRETTY_PRINT));
        self::assertCount(1, $lint['packs']);

        $serialized = json_encode([
            $this->jsonFile('manifest.json'),
            $this->jsonFile('version.json'),
            $this->jsonFile('commercial_spec.json'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertDoesNotMatchRegularExpression('/\p{Han}/u', $serialized);

        $keys = [];
        $this->collectKeys(json_decode($serialized, true, 512, JSON_THROW_ON_ERROR), $keys);
        foreach (self::FORBIDDEN_PRIVATE_KEYS as $forbidden) {
            self::assertNotContains($forbidden, $keys);
        }

        foreach ([
            'diagnoses users',
            'fixed identity',
            'predicts outcomes',
            'guarantees a career',
            'guarantees income',
            'hiring decision',
        ] as $forbiddenClaim) {
            self::assertStringNotContainsString($forbiddenClaim, strtolower($serialized));
        }
    }

    private function packDirectory(): string
    {
        return base_path('../content_packages/default/GLOBAL/en/'.self::DIRECTORY_VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFile(string $filename): array
    {
        $path = $this->packDirectory().'/'.$filename;
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $keys
     */
    private function collectKeys(array $value, array &$keys): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = strtolower($key);
            }
            if (is_array($child)) {
                $this->collectKeys($child, $keys);
            }
        }
    }
}
