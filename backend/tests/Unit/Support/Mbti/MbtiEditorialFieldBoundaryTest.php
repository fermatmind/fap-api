<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\PersonalityCms\DesktopClone\MbtiResultChapterCopy;
use App\PersonalityCms\DesktopClone\MbtiZhResultContentPackage;
use Tests\TestCase;

final class MbtiEditorialFieldBoundaryTest extends TestCase
{
    public function test_text_mask_preserves_every_non_text_and_structural_boundary(): void
    {
        $content = app(MbtiZhResultContentPackage::class)->compile()['rows'][0]['content_json'];
        $protected = MbtiResultChapterCopy::withoutEditorialSlots($content);
        $mutations = [
            'hero.profile_identity.code' => 'ENTJ-A',
            'chapters.career.traits_unlock.items.0.id' => 'changed-id',
            'chapters.career.traits_unlock.items.0.label' => 'changed-label',
            'chapters.career.traits_unlock.items.0.definition' => ['text became an array'],
            'chapters.career.traits_unlock.items.0.unapproved_field' => 'extra',
            'chapters.career.influentialTraits.0.colorKey' => 'unexpected',
            'chapters.career.strengths.schema_version' => 'unexpected',
            'chapters.career.work_styles.items.0.unapproved_field' => 'extra',
            'chapters.career.visibleBlocks.0.title' => 'compatibility block',
            'chapters.growth.what_energizes.items.0.id' => 'changed-scenario',
            'chapters.growth.what_energizes.items.0.tags' => ['unexpected'],
            'faq.0.extra' => 'unexpected',
        ];
        foreach ($mutations as $path => $value) {
            $changed = $content;
            data_set($changed, $path, $value);
            $this->assertNotSame($protected, MbtiResultChapterCopy::withoutEditorialSlots($changed), $path);
        }
        foreach (['chapters.career.traits_unlock.items', 'chapters.career.strengths.items', 'chapters.career.work_styles.items', 'chapters.growth.what_energizes.items', 'faq'] as $path) {
            $changed = $content;
            $items = data_get($changed, $path);
            array_pop($items);
            data_set($changed, $path, $items);
            $this->assertNotSame($protected, MbtiResultChapterCopy::withoutEditorialSlots($changed), $path);
        }
        $changed = $content;
        unset($changed['chapters']['career']['traits_unlock']['items'][0]['definition']);
        $this->assertNotSame($protected, MbtiResultChapterCopy::withoutEditorialSlots($changed));
        $changed = $content;
        $changed['chapters']['career']['traits_unlock']['items'] = array_reverse($changed['chapters']['career']['traits_unlock']['items']);
        $this->assertNotSame($protected, MbtiResultChapterCopy::withoutEditorialSlots($changed));
    }
}
