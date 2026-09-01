<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Personality\Current;

use App\Domain\Personality\Current\PersonalityLegacyPublicAuthorityArchive;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PersonalityLegacyPublicAuthorityArchiveTest extends TestCase
{
    public function test_public_runtime_cannot_disable_current_authority(): void
    {
        self::assertTrue(PersonalityLegacyPublicAuthorityArchive::shouldUseCurrentAuthority(0, false, false));
        self::assertTrue(PersonalityLegacyPublicAuthorityArchive::shouldUseCurrentAuthority(0, false, true));
        self::assertFalse(PersonalityLegacyPublicAuthorityArchive::shouldUseCurrentAuthority(9, false, true));
    }

    public function test_legacy_database_fixture_is_available_only_to_tests(): void
    {
        self::assertFalse(PersonalityLegacyPublicAuthorityArchive::shouldUseCurrentAuthority(0, true, true));
        self::assertTrue(PersonalityLegacyPublicAuthorityArchive::shouldUseCurrentAuthority(0, true, false));
    }

    public function test_non_test_legacy_write_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is archived');

        PersonalityLegacyPublicAuthorityArchive::assertLegacyWriteIsArchived(true, false, 'legacy write');
    }

    public function test_read_only_and_test_fixture_operations_remain_available(): void
    {
        PersonalityLegacyPublicAuthorityArchive::assertLegacyWriteIsArchived(false, false, 'dry run');
        PersonalityLegacyPublicAuthorityArchive::assertLegacyWriteIsArchived(true, true, 'test fixture');

        self::addToAssertionCount(2);
    }
}
