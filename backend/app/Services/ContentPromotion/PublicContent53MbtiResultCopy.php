<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Services\Mbti\Adapters\MbtiPersonalityProfileAuthoritySourceAdapter;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Existing English result-copy resource; no attempts, entitlements or URL inventory. */
final class PublicContent53MbtiResultCopy
{
    public const KIND = 'mbti-result';

    public const FOUR_LEAF_PAGE = 'MBTI-EN-INTJ-T-FOUR-LEAVES';

    public const NATIVE_SCHEMA = 'fermatmind.mbti_english_native_leaf_copy.v1';

    private const NATIVE_TYPES = ['ENFJ', 'ENFP', 'ENTJ', 'ENTP', 'ESFJ', 'ESFP', 'ESTJ', 'ESTP', 'INFJ', 'INFP', 'INTJ', 'INTP', 'ISFJ', 'ISFP', 'ISTJ', 'ISTP'];

    private const NATIVE_LEAVES = [
        'career_advantage' => ['section_key' => 'career.advantages', 'path' => ['payload_json', 'items', 1, 'body'], 'render_variant' => 'bullets'],
        'growth_weakness' => ['section_key' => 'growth.weaknesses', 'path' => ['payload_json', 'items', 0, 'body'], 'render_variant' => 'bullets'],
        'motivator_body' => ['section_key' => 'growth.motivators', 'path' => ['body_md'], 'render_variant' => 'premium_teaser'],
        'motivator_teaser' => ['section_key' => 'growth.motivators', 'path' => ['payload_json', 'teaser'], 'render_variant' => 'premium_teaser'],
        // The concrete FAQ array index is resolved by question + intent under the native lock.
        'stress_answer' => ['section_key' => 'faq', 'path' => ['payload_json', 'items', 'answer'], 'render_variant' => 'faq'],
        'growth_strength' => ['section_key' => 'growth.strengths', 'path' => ['payload_json', 'items', 0, 'body'], 'render_variant' => 'bullets'],
    ];

    public static function nativeCodes(): array
    {
        $codes = [];
        foreach (self::NATIVE_TYPES as $type) {
            foreach (['A', 'T'] as $variant) {
                $codes[] = $type.'-'.$variant;
            }
        }

        return $codes;
    }

    public static function nativeIdentity(string $code): array
    {
        if (! in_array($code, self::nativeCodes(), true)) {
            throw new DomainException('mbti_result_copy_identity_invalid');
        }
        $map = $code === 'INTJ-T' ? ['growth_strength' => self::NATIVE_LEAVES['growth_strength']] : self::NATIVE_LEAVES;
        $identity = ['canonical_type_code' => substr($code, 0, 4), 'runtime_type_code' => $code, 'leaf_map' => $map];
        if ($code !== 'INTJ-T') {
            $identity['faq_identity'] = ['question' => 'What stresses '.$code.'?', 'intent' => 'stress'];
        }

        return $identity;
    }

    /** The fixed 32 variants own exactly 187 leaves; accepted older packages are immutable. */
    public static function nativeLeafPackage(string $bytes, string $reviewBytes): array
    {
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($reviewBytes, true, 64, JSON_THROW_ON_ERROR);
        $codes = self::nativeCodes();
        $rows = $data['candidates'] ?? null;
        $pages = array_fill_keys(array_map(static fn ($code): string => 'MBTI-EN-'.$code.'-NATIVE-LEAVES', $codes), 'PASS');
        if (($data['schema'] ?? null) !== self::NATIVE_SCHEMA || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== 32
            || ($review['schema'] ?? null) !== 'fermatmind.mbti_english_native_leaf_copy.review.v1'
            || ($review['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($review['verdict'] ?? null) !== 'PASS' || ($review['pages'] ?? null) !== $pages) {
            throw new DomainException('mbti_result_copy_package_invalid');
        }
        foreach ($codes as $index => $code) {
            $row = $rows[$index];
            $identity = self::nativeIdentity($code);
            if (array_keys($row) !== ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']
                || $row['page_id'] !== 'MBTI-EN-'.$code.'-NATIVE-LEAVES' || $row['kind'] !== self::KIND
                || $row['identity'] !== ['org_id' => 0, 'slug' => strtolower($code), 'locale' => 'en']
                || $row['source_identity'] !== $identity || $row['staging_source_identity'] !== $identity) {
                throw new DomainException('mbti_result_copy_identity_invalid');
            }
            foreach (['before', 'staging_before', 'snapshot'] as $key) {
                if (! is_array($row[$key]) || array_keys($row[$key]) !== array_keys($identity['leaf_map'])) {
                    throw new DomainException('mbti_result_copy_fields_invalid');
                }
                foreach ($row[$key] as $value) {
                    if (! is_string($value) || trim($value) === '' || strlen($value) > 2000 || preg_match('/[<>]|\p{Han}/u', $value)) {
                        throw new DomainException('mbti_result_copy_fields_invalid');
                    }
                }
            }
        }

        return $data;
    }

    private function nativeCode(?array $row): ?string
    {
        if (! str_ends_with((string) ($row['page_id'] ?? ''), '-NATIVE-LEAVES')) {
            return null;
        }
        $code = (string) ($row['source_identity']['runtime_type_code'] ?? '');
        if (($row['page_id'] ?? null) !== 'MBTI-EN-'.$code.'-NATIVE-LEAVES'
            || PromotionContextFactory::canonicalJson($row['source_identity'] ?? []) !== PromotionContextFactory::canonicalJson(self::nativeIdentity($code))
            || PromotionContextFactory::canonicalJson($row['identity'] ?? []) !== PromotionContextFactory::canonicalJson(['org_id' => 0, 'slug' => strtolower($code), 'locale' => 'en'])) {
            throw new DomainException('mbti_result_copy_identity_invalid');
        }

        return $code;
    }

    private const FOUR_LEAVES = [
        'trait_overview' => ['trait_overview', 'body_md'],
        'career_advantage' => ['career.advantages', 'payload.items.1.body'],
        'growth_weakness' => ['growth.weaknesses', 'payload.items.0.body'],
        'stress_answer' => ['faq', 'payload.items.5.answer'],
    ];

    private const FOUR_RENDERS = ['trait_overview' => 'trait_dimension_grid', 'career.advantages' => 'bullets', 'growth.weaknesses' => 'bullets', 'faq' => 'faq'];

    public static function package(string $bytes, string $reviewBytes): array
    {
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($reviewBytes, true, 64, JSON_THROW_ON_ERROR);
        $rows = $data['candidates'] ?? null;
        if (($data['schema'] ?? null) !== 'fermatmind.mbti_english_result_copy.v1'
            || ! is_array($rows) || count($rows) !== 1 || ! array_is_list($rows)
            || ($review['schema'] ?? null) !== 'fermatmind.mbti_english_result_copy.review.v1'
            || ($review['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($review['verdict'] ?? null) !== 'PASS'
            || ($review['pages'] ?? null) !== ['MBTI-EN-INTJ-T-MOTIVATORS' => 'PASS']) {
            throw new DomainException('mbti_result_copy_package_invalid');
        }
        $row = $rows[0];
        $identity = ['canonical_type_code' => 'INTJ', 'runtime_type_code' => 'INTJ-T', 'section_key' => 'growth.motivators'];
        if (array_keys($row) !== ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']
            || $row['page_id'] !== 'MBTI-EN-INTJ-T-MOTIVATORS' || $row['kind'] !== self::KIND
            || $row['identity'] !== ['org_id' => 0, 'slug' => 'intj-t', 'locale' => 'en']
            || $row['source_identity'] !== $identity || $row['staging_source_identity'] !== $identity
            || $row['before'] !== $row['staging_before']) {
            throw new DomainException('mbti_result_copy_identity_invalid');
        }
        foreach (['before', 'staging_before', 'snapshot'] as $key) {
            $copy = $row[$key];
            if (! is_array($copy) || array_keys($copy) !== ['body_md', 'teaser']
                || ! is_string($copy['body_md']) || trim($copy['body_md']) === '' || strlen($copy['body_md']) > 2000
                || $copy['body_md'] !== $copy['teaser'] || preg_match('/[<>]|\p{Han}/u', $copy['body_md'])) {
                throw new DomainException('mbti_result_copy_fields_invalid');
            }
        }

        return $data;
    }

    /** A separate immutable package identity; the accepted motivator package stays unchanged. */
    public static function fourLeafPackage(string $bytes, string $reviewBytes): array
    {
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $review = json_decode($reviewBytes, true, 64, JSON_THROW_ON_ERROR);
        $rows = $data['candidates'] ?? null;
        if (($data['schema'] ?? null) !== 'fermatmind.mbti_english_four_leaf_copy.v1'
            || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1
            || ($review['schema'] ?? null) !== 'fermatmind.mbti_english_four_leaf_copy.review.v1'
            || ($review['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($review['verdict'] ?? null) !== 'PASS' || ($review['pages'] ?? null) !== [self::FOUR_LEAF_PAGE => 'PASS']) {
            throw new DomainException('mbti_result_copy_package_invalid');
        }
        $row = $rows[0];
        $identity = ['canonical_type_code' => 'INTJ', 'runtime_type_code' => 'INTJ-T',
            'section_keys' => array_keys(self::FOUR_RENDERS), 'faq_question' => 'What stresses INTJ-T?', 'faq_intent' => 'stress'];
        if (array_keys($row) !== ['page_id', 'kind', 'identity', 'before', 'staging_before', 'snapshot', 'source_identity', 'staging_source_identity']
            || $row['page_id'] !== self::FOUR_LEAF_PAGE || $row['kind'] !== self::KIND
            || $row['identity'] !== ['org_id' => 0, 'slug' => 'intj-t', 'locale' => 'en']
            || $row['source_identity'] !== $identity || $row['staging_source_identity'] !== $identity) {
            throw new DomainException('mbti_result_copy_identity_invalid');
        }
        foreach (['before', 'staging_before', 'snapshot'] as $key) {
            if (! is_array($row[$key]) || array_keys($row[$key]) !== array_keys(self::FOUR_LEAVES)) {
                throw new DomainException('mbti_result_copy_fields_invalid');
            }
            foreach ($row[$key] as $value) {
                if (! is_string($value) || trim($value) === '' || strlen($value) > 2000 || preg_match('/[<>]|\p{Han}/u', $value)) {
                    throw new DomainException('mbti_result_copy_fields_invalid');
                }
            }
        }

        return $data;
    }

    public function read(bool $lock = false, ?array $row = null): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('mbti_result_copy_lock_requires_transaction');
        }
        $nativeCode = $this->nativeCode($row);
        $runtime = $nativeCode ?? 'INTJ-T';
        $canonical = substr($runtime, 0, 4);
        $profiles = $this->query('personality_profiles', ['org_id' => 0, 'locale' => 'en', 'scale_code' => 'MBTI', 'type_code' => $canonical], $lock);
        if (count($profiles) !== 1 || $profiles[0]['status'] !== 'published' || ! (bool) $profiles[0]['is_public']
            || ($profiles[0]['deleted_at'] ?? null) !== null || $this->future($profiles[0]['published_at'] ?? null)) {
            throw new DomainException('mbti_result_copy_profile_hold');
        }
        $profile = $profiles[0];
        $variants = $this->query('personality_profile_variants', ['personality_profile_id' => $profile['id']], $lock);
        $targets = array_values(array_filter($variants, static fn (array $v): bool => $v['runtime_type_code'] === $runtime));
        if (count($targets) !== 1 || (int) $targets[0]['org_id'] !== 0 || $targets[0]['canonical_type_code'] !== $canonical
            || $targets[0]['variant_code'] !== substr($runtime, -1) || ! (bool) $targets[0]['is_published']
            || $this->future($targets[0]['published_at'] ?? null)) {
            throw new DomainException('mbti_result_copy_variant_hold');
        }
        $variant = $targets[0];
        $allSections = [];
        foreach ($variants as $control) {
            $allSections = array_merge($allSections, $this->query('personality_profile_variant_sections', ['personality_profile_variant_id' => $control['id']], $lock));
        }
        if ($nativeCode !== null) {
            // Each row owns one variant. The package's 32 locked rows jointly protect both A/T siblings.
            $ownSections = array_values(array_filter($allSections, static fn ($section): bool => (int) $section['personality_profile_variant_id'] === (int) $variant['id']));
            $map = self::nativeIdentity($nativeCode)['leaf_map'];
            $selected = [];
            foreach ($map as $name => &$leaf) {
                $key = $leaf['section_key'];
                $matches = array_values(array_filter($ownSections, static fn ($section): bool => $section['section_key'] === $key));
                if (count($matches) !== 1 || (int) $matches[0]['org_id'] !== 0 || ! (bool) $matches[0]['is_enabled']
                    || $matches[0]['render_variant'] !== $leaf['render_variant']) {
                    throw new DomainException('mbti_result_copy_section_hold');
                }
                $selected[$key] = $matches[0];
                if ($name === 'stress_answer') {
                    $items = $this->payload($matches[0])['items'] ?? null;
                    if (! is_array($items) || ! array_is_list($items)) {
                        throw new DomainException('mbti_result_copy_faq_identity_drift');
                    }
                    $indices = array_keys(array_filter($items, static fn ($item): bool => is_array($item)
                        && ($item['question'] ?? null) === 'What stresses '.$nativeCode.'?' && ($item['intent'] ?? null) === 'stress'));
                    if (count($indices) !== 1) {
                        throw new DomainException('mbti_result_copy_faq_identity_drift');
                    }
                    array_splice($leaf['path'], 2, 0, [$indices[0]]);
                }
                if ($key === 'growth.motivators' && ($this->payload($matches[0])['is_premium'] ?? null) !== true) {
                    throw new DomainException('mbti_result_copy_payload_invalid');
                }
            }
            unset($leaf);
            $state = ['table' => 'personality_profile_variant_sections', 'values' => reset($selected), 'relations' => [],
                'native_sections' => $selected, 'native_leaf_map' => $map, 'native_row' => $row,
                'controls' => ['profile' => $profile, 'variants' => $variants, 'sections' => $ownSections,
                    'revisions' => $this->query('personality_profile_variant_revisions', ['personality_profile_variant_id' => $variant['id']], $lock)]];
            $this->copy($state);

            return $state;
        }
        if (($row['page_id'] ?? null) === self::FOUR_LEAF_PAGE) {
            $selected = [];
            foreach (self::FOUR_RENDERS as $key => $render) {
                $matches = array_values(array_filter($allSections, static fn (array $s): bool => (int) $s['personality_profile_variant_id'] === (int) $variant['id'] && $s['section_key'] === $key));
                if (count($matches) !== 1 || (int) $matches[0]['org_id'] !== 0 || ! (bool) $matches[0]['is_enabled'] || $matches[0]['render_variant'] !== $render) {
                    throw new DomainException('mbti_result_copy_section_hold');
                }
                $selected[$key] = $matches[0];
            }
            $faq = $this->payload($selected['faq'])['items'][5] ?? null;
            if (! is_array($faq) || ($faq['question'] ?? null) !== 'What stresses INTJ-T?' || ($faq['intent'] ?? null) !== 'stress') {
                throw new DomainException('mbti_result_copy_faq_identity_drift');
            }
            $state = ['table' => 'personality_profile_variant_sections', 'values' => $selected['trait_overview'], 'relations' => [],
                'four_leaf_sections' => $selected,
                'controls' => ['profile' => $profile, 'variants' => $variants, 'sections' => $allSections,
                    'revisions' => $this->query('personality_profile_variant_revisions', ['personality_profile_variant_id' => $variant['id']], $lock)]];
            $this->copy($state);

            return $state;
        }
        $targets = array_values(array_filter($allSections, static fn (array $s): bool => (int) $s['personality_profile_variant_id'] === (int) $variant['id'] && $s['section_key'] === 'growth.motivators'));
        if (count($targets) !== 1 || (int) $targets[0]['org_id'] !== 0 || ! (bool) $targets[0]['is_enabled']
            || $targets[0]['render_variant'] !== 'premium_teaser') {
            throw new DomainException('mbti_result_copy_section_hold');
        }
        $payload = $this->payload($targets[0]);
        if (($payload['is_premium'] ?? null) !== true || ! is_string($payload['teaser'] ?? null)) {
            throw new DomainException('mbti_result_copy_payload_invalid');
        }

        return ['table' => 'personality_profile_variant_sections', 'values' => $targets[0], 'relations' => [],
            'controls' => ['profile' => $profile, 'variants' => $variants, 'sections' => $allSections,
                'revisions' => $this->query('personality_profile_variant_revisions', ['personality_profile_variant_id' => $variant['id']], $lock)]];
    }

    /** Uses the same native CMS adapter as the English private-report overlay. */
    public function projection(?array $row = null): array
    {
        $state = $this->read(false, $row);
        $profile = new PersonalityProfile;
        $profile->setRawAttributes($state['controls']['profile'], true);
        $variant = new PersonalityProfileVariant;
        $runtime = $this->nativeCode($row) ?? 'INTJ-T';
        $target = array_values(array_filter($state['controls']['variants'], static fn ($v): bool => $v['runtime_type_code'] === $runtime))[0];
        $variant->setRawAttributes($target, true);
        $adapter = app(MbtiPersonalityProfileAuthoritySourceAdapter::class);
        $authority = $adapter->overlayVariant($adapter->fromBaseProfile($profile), $variant);
        if (isset($state['native_sections'])) {
            $state['native_sections'] = array_map(static fn ($section): array => [
                'body_md' => $section['body_md'] ?? null, 'payload_json' => $section['payload'] ?? null,
            ], $authority['sections']);

            return $this->copy($state);
        }
        if (($row['page_id'] ?? null) === self::FOUR_LEAF_PAGE) {
            return $this->fourLeafCopy(array_map(static fn (array $section): array => ['body_md' => $section['body_md'] ?? null, 'payload_json' => $section['payload'] ?? null], $authority['sections']));
        }
        $section = $authority['sections']['growth.motivators'] ?? [];

        return ['body_md' => $section['body_md'] ?? null, 'teaser' => $section['payload']['teaser'] ?? null];
    }

    public function assertBaseline(array $row, array $state): void
    {
        $copy = $this->copy($state);
        if (isset($state['native_sections'])) {
            if ($this->projection($row) !== $copy) {
                throw new DomainException('mbti_result_copy_native_readback_failed');
            }
            foreach ($copy as $name => $value) {
                if ($value !== PublicContent53Package::before($row)[$name] && $value !== $row['snapshot'][$name]) {
                    throw new DomainException('mbti_result_copy_reviewed_prestate_drift');
                }
            }

            return;
        }
        if ($copy !== PublicContent53Package::before($row) && $copy !== $row['snapshot']) {
            throw new DomainException('mbti_result_copy_reviewed_prestate_drift');
        }
    }

    public function publish(array $row, array $before, PromotionContext $context): void
    {
        $this->assertBaseline($row, $before);
        if (isset($before['native_sections'])) {
            $updates = [];
            $oldCopy = $this->copy($before);
            foreach ($before['native_leaf_map'] as $name => $leaf) {
                if ($oldCopy[$name] === $row['snapshot'][$name]) {
                    continue;
                }
                $key = $leaf['section_key'];
                if ($leaf['path'][0] === 'body_md') {
                    $updates[$key]['body_md'] = $row['snapshot'][$name];
                } else {
                    $payload = $updates[$key]['payload_json'] ?? $this->payload($before['native_sections'][$key]);
                    data_set($payload, implode('.', array_slice($leaf['path'], 1)), $row['snapshot'][$name]);
                    $updates[$key]['payload_json'] = $payload;
                }
            }
            if ($updates === []) {
                return;
            }
            foreach ($updates as $key => $values) {
                if (isset($values['payload_json'])) {
                    $values['payload_json'] = json_encode($values['payload_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $values['updated_at'] = now();
                DB::table('personality_profile_variant_sections')->where('id', $before['native_sections'][$key]['id'])->update($values);
            }
            $after = $this->read(true, $row);
            $this->assertUnchangedControls($before, $after);
            if ($this->copy($after) !== $row['snapshot'] || $this->projection($row) !== $row['snapshot']) {
                throw new DomainException('mbti_result_copy_native_readback_failed');
            }
            $this->revision($context, $before, $after, 'publish');

            return;
        }
        if (isset($before['four_leaf_sections'])) {
            foreach (self::FOUR_LEAVES as $name => [$key, $path]) {
                $section = $before['four_leaf_sections'][$key];
                $values = ['updated_at' => now()];
                if ($path === 'body_md') {
                    $values['body_md'] = $row['snapshot'][$name];
                } else {
                    $payload = $this->payload($section);
                    data_set($payload, substr($path, strlen('payload.')), $row['snapshot'][$name]);
                    $values['payload_json'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                DB::table('personality_profile_variant_sections')->where('id', $section['id'])->update($values);
            }
            $after = $this->read(true, $row);
            $this->assertUnchangedControls($before, $after);
            if ($this->copy($after) !== $row['snapshot'] || $this->projection($row) !== $row['snapshot']) {
                throw new DomainException('mbti_result_copy_native_readback_failed');
            }
            $this->revision($context, $before, $after, 'publish');

            return;
        }
        $payload = $this->payload($before['values']);
        $payload['teaser'] = $row['snapshot']['teaser'];
        DB::table('personality_profile_variant_sections')->where('id', $before['values']['id'])->update([
            'body_md' => $row['snapshot']['body_md'], 'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        $after = $this->read(true);
        $this->assertUnchangedControls($before, $after);
        if ($this->copy($after) !== $row['snapshot'] || $this->projection() !== $row['snapshot']) {
            throw new DomainException('mbti_result_copy_native_readback_failed');
        }
        $this->revision($context, $before, $after, 'publish');
    }

    public function restore(PromotionContext $context, array $before, array $after): void
    {
        if (isset($before['native_sections']) && $before['native_sections'] === $after['native_sections']) {
            return;
        }
        $sections = $before['native_sections'] ?? $before['four_leaf_sections'] ?? [$before['values']];
        foreach ($sections as $section) {
            DB::table('personality_profile_variant_sections')->where('id', $section['id'])->update(array_intersect_key($section, array_flip(['body_md', 'payload_json', 'updated_at'])));
        }
        $restored = $this->read(true, $before['native_row'] ?? (isset($before['four_leaf_sections']) ? ['page_id' => self::FOUR_LEAF_PAGE] : null));
        $this->assertUnchangedControls($before, $restored);
        if ($this->copy($restored) !== $this->copy($before)) {
            throw new DomainException('mbti_result_copy_restore_readback_failed');
        }
        $this->revision($context, $after, $restored, 'restore');
    }

    private function revision(PromotionContext $context, array $before, array $after, string $mode): void
    {
        $variantId = (int) $before['values']['personality_profile_variant_id'];
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $variantId,
            'revision_no' => ((int) PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $variantId)->max('revision_no')) + 1,
            'snapshot_json' => ['mbti_result_copy_v1' => ['mode' => $mode, 'package_sha256' => $context->packageSha256,
                'source_commit' => $context->sourceCommit, 'before' => $this->copy($before), 'after' => $this->copy($after)]],
            'note' => 'Exact-package English result copy: '.$mode, 'created_at' => now(),
        ]);
    }

    private function assertUnchangedControls(array $before, array $after): void
    {
        if (PromotionContextFactory::canonicalJson($before['controls']['profile']) !== PromotionContextFactory::canonicalJson($after['controls']['profile'])
            || PromotionContextFactory::canonicalJson($before['controls']['variants']) !== PromotionContextFactory::canonicalJson($after['controls']['variants'])) {
            throw new DomainException('mbti_result_copy_parent_changed');
        }
        $scrub = static function (array $state): array {
            return array_map(static function (array $section) use ($state): array {
                if (isset($state['native_sections'])) {
                    $payload = null;
                    foreach ($state['native_leaf_map'] as $leaf) {
                        if ($section['id'] !== $state['native_sections'][$leaf['section_key']]['id']) {
                            continue;
                        }
                        unset($section['updated_at']);
                        if ($leaf['path'][0] === 'body_md') {
                            unset($section['body_md']);
                        } else {
                            $payload ??= json_decode((string) $section['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                            data_forget($payload, implode('.', array_slice($leaf['path'], 1)));
                        }
                    }
                    if ($payload !== null) {
                        $section['payload_json'] = $payload;
                    }

                    return $section;
                }
                if (isset($state['four_leaf_sections'])) {
                    foreach (self::FOUR_LEAVES as [$key, $path]) {
                        if ($section['id'] !== $state['four_leaf_sections'][$key]['id']) {
                            continue;
                        }
                        unset($section['updated_at']);
                        if ($path === 'body_md') {
                            unset($section['body_md']);
                        } else {
                            $payload = json_decode((string) $section['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                            data_forget($payload, substr($path, strlen('payload.')));
                            $section['payload_json'] = $payload;
                        }
                    }

                    return $section;
                }
                if ($section['id'] === $state['values']['id']) {
                    unset($section['body_md'], $section['updated_at']);
                    $payload = json_decode((string) $section['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                    unset($payload['teaser']);
                    $section['payload_json'] = $payload;
                }

                return $section;
            }, $state['controls']['sections']);
        };
        if (PromotionContextFactory::canonicalJson($scrub($before)) !== PromotionContextFactory::canonicalJson($scrub($after))) {
            throw new DomainException('mbti_result_copy_unowned_field_changed');
        }
    }

    private function copy(array $state): array
    {
        if (isset($state['native_sections'])) {
            $copy = [];
            foreach (array_keys(self::nativeIdentity($state['native_row']['source_identity']['runtime_type_code'])['leaf_map']) as $name) {
                $leaf = $state['native_leaf_map'][$name];
                $section = $state['native_sections'][$leaf['section_key']] ?? [];
                $value = $leaf['path'][0] === 'body_md' ? ($section['body_md'] ?? null)
                    : data_get($this->payload($section), implode('.', array_slice($leaf['path'], 1)));
                if (! is_string($value) || trim($value) === '') {
                    throw new DomainException('mbti_result_copy_payload_invalid');
                }
                $copy[$name] = $value;
            }

            return $copy;
        }
        if (isset($state['four_leaf_sections'])) {
            return $this->fourLeafCopy($state['four_leaf_sections']);
        }

        return ['body_md' => $state['values']['body_md'], 'teaser' => $this->payload($state['values'])['teaser']];
    }

    private function fourLeafCopy(array $sections): array
    {
        $copy = [];
        foreach (self::FOUR_LEAVES as $name => [$key, $path]) {
            $section = $sections[$key] ?? [];
            $value = $path === 'body_md' ? ($section['body_md'] ?? null) : data_get($this->payload($section), substr($path, strlen('payload.')));
            if (! is_string($value) || trim($value) === '') {
                throw new DomainException('mbti_result_copy_payload_invalid');
            }
            $copy[$name] = $value;
        }

        return $copy;
    }

    private function payload(array $section): array
    {
        $value = is_array($section['payload_json'] ?? null) ? $section['payload_json'] : json_decode((string) ($section['payload_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException('mbti_result_copy_payload_invalid');
        }

        return $value;
    }

    private function query(string $table, array $where, bool $lock): array
    {
        $query = DB::table($table)->where($where)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return array_map(static fn ($row): array => (array) $row, $query->get()->all());
    }

    private function future(?string $value): bool
    {
        return $value !== null && strtotime($value) > time();
    }
}
