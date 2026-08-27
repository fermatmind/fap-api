<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerPresentationV2Contract
{
    public const CONTRACT_VERSION = 'career.detail.presentation.v2';

    public const DESIGN_AUTHORITY_ID = 'universal-career-dossier-v2';

    public const TEMPLATE_ID = 'career-dossier-universal-v2';

    public const PENDING_ENRICHMENT = 'display_placeholder';

    /** @param array<string,mixed> $presentation @param list<string>|null $componentOrder */
    public static function assert(array $presentation, ?array $componentOrder = null): void
    {
        self::assertExactKeys($presentation, [
            'contract_version', 'design_authority', 'template_id', 'locale', 'hero', 'groups',
        ]);
        if (($presentation['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($presentation['template_id'] ?? null) !== self::TEMPLATE_ID
            || ! in_array($presentation['locale'] ?? null, CareerCurrentAuthorityPackage::LOCALES, true)) {
            self::fail();
        }

        $designAuthority = $presentation['design_authority'] ?? null;
        if (! is_array($designAuthority)) {
            self::fail();
        }
        self::assertExactKeys($designAuthority, ['id']);
        if (($designAuthority['id'] ?? null) !== self::DESIGN_AUTHORITY_ID) {
            self::fail();
        }

        self::assertHero($presentation['hero'] ?? null);
        $groups = $presentation['groups'] ?? null;
        if (! is_array($groups) || ! array_is_list($groups) || $groups === []) {
            self::fail();
        }

        $seenGroups = [];
        $seenComponents = [];
        $flattened = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                self::fail();
            }
            $allowedKeys = ($group['content_state'] ?? null) === 'legacy'
                ? [['id', 'label', 'component_ids', 'content_state'], ['id', 'label', 'component_ids', 'content_state', 'pending_enrichment']]
                : [['id', 'label', 'component_ids', 'content_state']];
            $matches = false;
            foreach ($allowedKeys as $keys) {
                if (self::hasExactKeys($group, $keys)) {
                    $matches = true;
                    break;
                }
            }
            $id = $group['id'] ?? null;
            $label = $group['label'] ?? null;
            $contentState = $group['content_state'] ?? null;
            $componentIds = $group['component_ids'] ?? null;
            if (! $matches
                || ! is_string($id) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id) !== 1 || isset($seenGroups[$id])
                || ! is_string($label) || trim($label) === ''
                || ! in_array($contentState, ['enhanced', 'legacy'], true)
                || ! is_array($componentIds) || ! array_is_list($componentIds) || $componentIds === []) {
                self::fail();
            }
            if (array_key_exists('pending_enrichment', $group)
                && ($contentState !== 'legacy' || $group['pending_enrichment'] !== self::PENDING_ENRICHMENT)) {
                self::fail();
            }
            $seenGroups[$id] = true;
            foreach ($componentIds as $componentId) {
                if (! is_string($componentId)
                    || ! in_array($componentId, CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS, true)
                    || isset($seenComponents[$componentId])) {
                    self::fail();
                }
                $seenComponents[$componentId] = true;
                $flattened[] = $componentId;
            }
        }
        if ($componentOrder !== null && $flattened !== $componentOrder) {
            self::fail();
        }
    }

    private static function assertHero(mixed $hero): void
    {
        if (! is_array($hero)) {
            self::fail();
        }
        self::assertExactKeys($hero, ['title', 'lead', 'badges', 'stats', 'ai_exposure', 'cta']);
        self::assertNullableString($hero['lead'] ?? null);
        if (! is_string($hero['title'] ?? null) || trim($hero['title']) === '') {
            self::fail();
        }
        foreach (['badges', 'stats'] as $key) {
            if (! is_array($hero[$key] ?? null) || ! array_is_list($hero[$key])) {
                self::fail();
            }
        }
        foreach ($hero['badges'] as $badge) {
            if (! is_array($badge)) {
                self::fail();
            }
            self::assertExactKeys($badge, ['key', 'text']);
            if (! is_string($badge['key'] ?? null) || trim($badge['key']) === ''
                || ! is_string($badge['text'] ?? null) || trim($badge['text']) === '') {
                self::fail();
            }
        }
        foreach ($hero['stats'] as $stat) {
            if (! is_array($stat)) {
                self::fail();
            }
            self::assertExactKeys($stat, ['key', 'label', 'value', 'source_label']);
            foreach (['key', 'label', 'value'] as $key) {
                if (! is_string($stat[$key] ?? null) || trim($stat[$key]) === '') {
                    self::fail();
                }
            }
            self::assertNullableString($stat['source_label'] ?? null);
        }
        $aiExposure = $hero['ai_exposure'] ?? null;
        if ($aiExposure !== null) {
            if (! is_array($aiExposure)) {
                self::fail();
            }
            self::assertExactKeys($aiExposure, ['value', 'scale', 'display_value', 'label', 'note', 'source_label']);
            if (! is_int($aiExposure['value'] ?? null) || $aiExposure['value'] < 0 || $aiExposure['value'] > 10
                || ($aiExposure['scale'] ?? null) !== 10
                || ($aiExposure['display_value'] ?? null) !== $aiExposure['value'].'/10') {
                self::fail();
            }
            foreach (['label', 'source_label'] as $key) {
                if (! is_string($aiExposure[$key] ?? null) || trim($aiExposure[$key]) === '') {
                    self::fail();
                }
            }
            self::assertNullableString($aiExposure['note'] ?? null);
        }
        $cta = $hero['cta'] ?? null;
        if ($cta !== null) {
            if (! is_array($cta)) {
                self::fail();
            }
            self::assertExactKeys($cta, ['label', 'href']);
            foreach (['label', 'href'] as $key) {
                if (! is_string($cta[$key] ?? null) || trim($cta[$key]) === '') {
                    self::fail();
                }
            }
        }
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        if (! self::hasExactKeys($value, $expected)) {
            self::fail();
        }
    }

    /** @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private static function assertNullableString(mixed $value): void
    {
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CURRENT_PRESENTATION_V2_INVALID');
    }
}
