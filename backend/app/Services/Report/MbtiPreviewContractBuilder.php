<?php

declare(strict_types=1);

namespace App\Services\Report;

final class MbtiPreviewContractBuilder
{
    public const KEY = 'mbti_preview_v1';

    private const MODE_NONE = 'none';

    private const MODE_MODULE_PREVIEW = 'module_preview';

    /**
     * @param  array<string,mixed>  $reportEnvelope
     * @return array{
     *   mode:string,
     *   modules:list<string>,
     *   sections:list<array{
     *     key:string,
     *     module_code:string,
     *     has_preview_content:bool,
     *     visible_preview_cards:list<array{
     *       id:string,
     *       title:string,
     *       body:?string,
     *       bullets:list<string>,
     *       tips:list<string>,
     *       tags:list<string>,
     *       module_code:string,
     *       access_level:string
     *     }>,
     *     has_locked_remainder:bool
     *   }>
     * }
     */
    public function buildFromReportEnvelope(array $reportEnvelope): array
    {
        $isPreviewCandidate = (bool) ($reportEnvelope['locked'] ?? false)
            || ReportAccess::normalizeVariant((string) ($reportEnvelope['variant'] ?? '')) === ReportAccess::VARIANT_FREE
            || ReportAccess::normalizeReportAccessLevel((string) ($reportEnvelope['access_level'] ?? '')) === ReportAccess::REPORT_ACCESS_FREE;

        if (! $isPreviewCandidate) {
            return [
                'mode' => self::MODE_NONE,
                'modules' => [],
                'sections' => [],
            ];
        }

        $previewModules = ReportAccess::normalizeModules(
            is_array($reportEnvelope['modules_preview'] ?? null) ? $reportEnvelope['modules_preview'] : []
        );

        $sections = $this->buildSections(
            is_array($reportEnvelope['report'] ?? null) ? ($reportEnvelope['report']['sections'] ?? null) : null,
            $previewModules
        );

        $hasPreviewContent = false;
        foreach ($sections as $section) {
            if (($section['has_preview_content'] ?? false) === true) {
                $hasPreviewContent = true;
                break;
            }
        }

        $mode = ($previewModules !== [] || $hasPreviewContent)
            ? self::MODE_MODULE_PREVIEW
            : self::MODE_NONE;

        return [
            'mode' => $mode,
            'modules' => $mode === self::MODE_MODULE_PREVIEW ? $previewModules : [],
            'sections' => $mode === self::MODE_MODULE_PREVIEW ? $sections : [],
        ];
    }

    /**
     * @param  mixed  $sectionsNode
     * @param  list<string>  $previewModules
     * @return list<array{
     *   key:string,
     *   module_code:string,
     *   has_preview_content:bool,
     *   visible_preview_cards:list<array{
     *     id:string,
     *     title:string,
     *     body:?string,
     *     bullets:list<string>,
     *     tips:list<string>,
     *     tags:list<string>,
     *     module_code:string,
     *     access_level:string
     *   }>,
     *   has_locked_remainder:bool
     * }>
     */
    private function buildSections(mixed $sectionsNode, array $previewModules): array
    {
        $previewModuleSet = array_fill_keys($previewModules, true);

        if (is_array($sectionsNode) && array_is_list($sectionsNode)) {
            $out = [];
            foreach ($sectionsNode as $rawSection) {
                if (! is_array($rawSection)) {
                    continue;
                }

                $section = $this->buildSection(
                    $this->normalizeIdentifier($rawSection['key'] ?? ''),
                    $rawSection,
                    $previewModuleSet,
                    'blocks'
                );

                if ($section !== null) {
                    $out[] = $section;
                }
            }

            return $out;
        }

        if (! is_array($sectionsNode)) {
            return [];
        }

        $out = [];
        foreach ($sectionsNode as $key => $rawSection) {
            if (! is_array($rawSection)) {
                continue;
            }

            $section = $this->buildSection(
                $this->normalizeIdentifier($key),
                $rawSection,
                $previewModuleSet,
                'cards'
            );

            if ($section !== null) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $section
     * @param  array<string,true>  $previewModuleSet
     * @return array{
     *   key:string,
     *   module_code:string,
     *   has_preview_content:bool,
     *   visible_preview_cards:list<array{
     *     id:string,
     *     title:string,
     *     body:?string,
     *     bullets:list<string>,
     *     tips:list<string>,
     *     tags:list<string>,
     *     module_code:string,
     *     access_level:string
     *   }>,
     *   has_locked_remainder:bool
     * }|null
     */
    private function buildSection(
        string $sectionKey,
        array $section,
        array $previewModuleSet,
        string $cardsField
    ): ?array {
        if ($sectionKey === '') {
            return null;
        }

        $sectionModuleCode = $this->normalizeIdentifier($section['module_code'] ?? '');
        if ($sectionModuleCode === '') {
            $sectionModuleCode = ReportAccess::defaultModuleCodeForSection($sectionKey);
        }

        $visiblePreviewCards = [];
        $hasPaidOrLockedContent = (bool) ($section['locked'] ?? false)
            || $this->normalizeIdentifier($section['access_level'] ?? '') === ReportAccess::CARD_ACCESS_PAID;

        foreach ($this->normalizeItems($section[$cardsField] ?? null) as $rawCard) {
            $accessLevel = ReportAccess::normalizeCardAccessLevel((string) ($rawCard['access_level'] ?? ''));
            $moduleCode = $this->normalizeIdentifier($rawCard['module_code'] ?? '');
            if ($moduleCode === '') {
                $moduleCode = $sectionModuleCode;
            }

            if ($accessLevel === ReportAccess::CARD_ACCESS_PAID) {
                $hasPaidOrLockedContent = true;
                continue;
            }

            if ($accessLevel !== ReportAccess::CARD_ACCESS_PREVIEW) {
                continue;
            }

            if ($previewModuleSet !== [] && ! isset($previewModuleSet[$moduleCode])) {
                $hasPaidOrLockedContent = true;
                continue;
            }

            $title = $this->normalizeContentText($rawCard['title'] ?? null, $rawCard['label'] ?? null);
            $body = $this->normalizeNullableContentText(
                $rawCard['desc'] ?? null,
                $rawCard['body'] ?? null,
                $rawCard['content'] ?? null,
                $rawCard['text'] ?? null
            );
            $bullets = $this->normalizeStringArray($rawCard['bullets'] ?? null);

            if ($title === '' && $body === null && $bullets === []) {
                continue;
            }

            $visiblePreviewCards[] = [
                'id' => $this->normalizeContentText($rawCard['id'] ?? ''),
                'title' => $title,
                'body' => $body,
                'bullets' => $bullets,
                'tips' => $this->normalizeStringArray($rawCard['tips'] ?? null),
                'tags' => $this->normalizeStringArray($rawCard['tags'] ?? null),
                'module_code' => $moduleCode,
                'access_level' => ReportAccess::CARD_ACCESS_PREVIEW,
            ];
        }

        return [
            'key' => $sectionKey,
            'module_code' => $sectionModuleCode,
            'has_preview_content' => $visiblePreviewCards !== [],
            'visible_preview_cards' => $visiblePreviewCards,
            'has_locked_remainder' => $hasPaidOrLockedContent,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $normalized = $this->normalizeContentText($item);
            if ($normalized !== '') {
                $out[$normalized] = true;
            }
        }

        return array_keys($out);
    }

    private function normalizeIdentifier(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeContentText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeNullableContentText(mixed ...$values): ?string
    {
        $normalized = $this->normalizeContentText(...$values);

        return $normalized !== '' ? $normalized : null;
    }
}
