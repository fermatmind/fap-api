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

    public function read(bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('mbti_result_copy_lock_requires_transaction');
        }
        $profiles = $this->query('personality_profiles', ['org_id' => 0, 'locale' => 'en', 'scale_code' => 'MBTI', 'type_code' => 'INTJ'], $lock);
        if (count($profiles) !== 1 || $profiles[0]['status'] !== 'published' || ! (bool) $profiles[0]['is_public']
            || ($profiles[0]['deleted_at'] ?? null) !== null || $this->future($profiles[0]['published_at'] ?? null)) {
            throw new DomainException('mbti_result_copy_profile_hold');
        }
        $profile = $profiles[0];
        $variants = $this->query('personality_profile_variants', ['personality_profile_id' => $profile['id']], $lock);
        $targets = array_values(array_filter($variants, static fn (array $v): bool => $v['runtime_type_code'] === 'INTJ-T'));
        if (count($targets) !== 1 || (int) $targets[0]['org_id'] !== 0 || $targets[0]['canonical_type_code'] !== 'INTJ'
            || $targets[0]['variant_code'] !== 'T' || ! (bool) $targets[0]['is_published']
            || $this->future($targets[0]['published_at'] ?? null)) {
            throw new DomainException('mbti_result_copy_variant_hold');
        }
        $variant = $targets[0];
        $allSections = [];
        foreach ($variants as $control) {
            $allSections = array_merge($allSections, $this->query('personality_profile_variant_sections', ['personality_profile_variant_id' => $control['id']], $lock));
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
    public function projection(): array
    {
        $state = $this->read();
        $profile = new PersonalityProfile;
        $profile->setRawAttributes($state['controls']['profile'], true);
        $variant = new PersonalityProfileVariant;
        $target = array_values(array_filter($state['controls']['variants'], static fn ($v): bool => $v['runtime_type_code'] === 'INTJ-T'))[0];
        $variant->setRawAttributes($target, true);
        $adapter = app(MbtiPersonalityProfileAuthoritySourceAdapter::class);
        $authority = $adapter->overlayVariant($adapter->fromBaseProfile($profile), $variant);
        $section = $authority['sections']['growth.motivators'] ?? [];

        return ['body_md' => $section['body_md'] ?? null, 'teaser' => $section['payload']['teaser'] ?? null];
    }

    public function assertBaseline(array $row, array $state): void
    {
        $copy = $this->copy($state);
        if ($copy !== $row['before'] && $copy !== $row['snapshot']) {
            throw new DomainException('mbti_result_copy_reviewed_prestate_drift');
        }
    }

    public function publish(array $row, array $before, PromotionContext $context): void
    {
        $this->assertBaseline($row, $before);
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
        DB::table('personality_profile_variant_sections')->where('id', $before['values']['id'])->update(array_intersect_key($before['values'], array_flip(['body_md', 'payload_json', 'updated_at'])));
        $restored = $this->read(true);
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
        if ($before['controls']['profile'] !== $after['controls']['profile'] || $before['controls']['variants'] !== $after['controls']['variants']) {
            throw new DomainException('mbti_result_copy_parent_changed');
        }
        $scrub = static function (array $state): array {
            return array_map(static function (array $section) use ($state): array {
                if ($section['id'] === $state['values']['id']) {
                    unset($section['body_md'], $section['updated_at']);
                    $payload = json_decode((string) $section['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                    unset($payload['teaser']);
                    $section['payload_json'] = $payload;
                }

                return $section;
            }, $state['controls']['sections']);
        };
        if ($scrub($before) !== $scrub($after)) {
            throw new DomainException('mbti_result_copy_unowned_field_changed');
        }
    }

    private function copy(array $state): array
    {
        return ['body_md' => $state['values']['body_md'], 'teaser' => $this->payload($state['values'])['teaser']];
    }

    private function payload(array $section): array
    {
        $value = json_decode((string) $section['payload_json'], true, 64, JSON_THROW_ON_ERROR);
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
