<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\RiasecPrivateResultCompileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RiasecCanonicalPrivateResultAuthorityTest extends TestCase
{
    public function test_manifest_declares_one_editable_chinese_private_result_authority(): void
    {
        $compiled = app(RiasecPrivateResultCompileService::class)->compile();
        $manifest = $compiled['manifest'];

        $this->assertSame(RiasecPrivateResultCompileService::AUTHORITY_ID, $manifest['authority_id']);
        $this->assertSame('backend/content_assets/riasec', $manifest['authority_root']);
        $this->assertSame(1, $manifest['editable_authority_count']);
        $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], $manifest['coverage']['dimensions']);
        $this->assertSame(['faq', 'technical_note', 'pdf', 'print', 'history', 'compare', 'share', 'lifecycle'], $manifest['coverage']['secondary_surfaces']);
        $this->assertFalse($manifest['generated']['manual_edit_allowed']);
    }

    public function test_no_new_private_result_source_is_outside_manifest_whitelist(): void
    {
        $root = base_path('content_assets/riasec');
        $allowed = array_fill_keys(array_keys(RiasecPrivateResultCompileService::SOURCE_CONTRACT), true);
        foreach (File::files($root) as $file) {
            $name = $file->getFilename();
            if (! str_contains($name, 'zh-CN') || str_ends_with($name, '.md')) {
                continue;
            }
            $this->assertArrayHasKey($name, $allowed, "Chinese private-result source is not manifest-bound: {$name}");
        }
    }

    public function test_runtime_php_contains_no_editable_chinese_private_result_body(): void
    {
        foreach ([
            'app/Services/Riasec/RiasecActivityExplorerService.php',
            'app/Services/Riasec/RiasecDeepCopySlotRegistry.php',
            'app/Services/Riasec/RiasecPublicProjectionService.php',
            'app/Services/Riasec/RiasecTechnicalNoteService.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));
            $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', $contents, "Editable Chinese result body remains in PHP: {$path}");
        }
    }
}
