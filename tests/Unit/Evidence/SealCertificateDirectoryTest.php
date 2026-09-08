<?php

declare(strict_types=1);

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\Sealing\Exceptions\SealKeyUnknownException;
use App\Domain\Evidence\Sealing\Exceptions\SealMaterialUnavailableException;
use App\Domain\Evidence\Sealing\SealCertificateDirectory;
use Tests\Support\SealingFixtures;
use Tests\TestCase;

/**
 * The rules the retired-key list follows, pinned separately from the rotation drill.
 *
 * The drill proves the behaviour end to end; these pin the parsing and resolution decisions
 * that would otherwise only be visible in a comment — in particular the two that are easy to
 * get wrong in the direction of quietly verifying against the wrong certificate.
 */
final class SealCertificateDirectoryTest extends TestCase
{
    public function test_it_parses_the_compact_environment_form(): void
    {
        $parsed = SealCertificateDirectory::parseEnvironment(
            'seal-2026-a|/keys/2026/seal.crt|/keys/2026/chain.crt, seal-2025-a|/keys/2025/seal.crt'
        );

        $this->assertSame([
            ['key_id' => 'seal-2026-a', 'certificate_path' => '/keys/2026/seal.crt', 'chain_path' => '/keys/2026/chain.crt'],
            ['key_id' => 'seal-2025-a', 'certificate_path' => '/keys/2025/seal.crt', 'chain_path' => ''],
        ], $parsed);
    }

    public function test_it_ignores_entries_with_no_key_id_or_no_certificate(): void
    {
        // An unset variable, an empty one, and a half-written entry all mean "no retired keys",
        // never "a retired key with an empty path", which would resolve to something surprising.
        $this->assertSame([], SealCertificateDirectory::parseEnvironment(null));
        $this->assertSame([], SealCertificateDirectory::parseEnvironment(''));
        $this->assertSame([], SealCertificateDirectory::parseEnvironment('seal-2026-a'));
        $this->assertSame([], SealCertificateDirectory::parseEnvironment('|/keys/seal.crt'));
    }

    public function test_a_retired_entry_never_shadows_the_active_key_id(): void
    {
        // The half-finished rotation: the operator appended the outgoing key to the retired
        // list but has not yet changed ESIGN_SEAL_KEY_ID. The active id must keep resolving to
        // the active certificate path, not to the stale one.
        $directory = SealCertificateDirectory::fromConfig([
            'key_id' => SealingFixtures::KEY_ID,
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
            'chain_path' => '',
            'retired_keys' => [[
                'key_id' => SealingFixtures::KEY_ID,
                'certificate_path' => SealingFixtures::cryptoPath('seal-b.test.crt'),
                'chain_path' => '',
            ]],
        ]);

        $this->assertSame([], $directory->retiredKeyIds());
        $this->assertSame(
            SealingFixtures::KEY_ID,
            $directory->certificateFor(SealingFixtures::KEY_ID)->keyId,
        );
        $this->assertStringContainsString(
            'TEST SEAL - NOT FOR USE',
            $directory->certificateFor(SealingFixtures::KEY_ID)->subject,
        );
    }

    public function test_an_unlisted_key_id_is_refused_rather_than_guessed(): void
    {
        $directory = SealCertificateDirectory::fromConfig([
            'key_id' => SealingFixtures::KEY_ID,
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
        ]);

        $this->assertFalse($directory->knows('seal-never-configured'));
        $this->assertNotNull($directory->problemWith('seal-never-configured'));

        $this->expectException(SealKeyUnknownException::class);

        $directory->certificateFor('seal-never-configured');
    }

    public function test_a_listed_key_whose_file_is_gone_is_reported_as_a_problem(): void
    {
        $directory = SealCertificateDirectory::fromConfig([
            'key_id' => SealingFixtures::KEY_ID,
            'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
            'retired_keys' => [[
                'key_id' => 'seal-2025-a',
                'certificate_path' => SealingFixtures::cryptoPath('this-file-does-not-exist.test.crt'),
                'chain_path' => '',
            ]],
        ]);

        $this->assertSame(['seal-2025-a'], $directory->retiredKeyIds());
        $this->assertNotNull($directory->problemWith('seal-2025-a'));

        $this->expectException(SealMaterialUnavailableException::class);

        $directory->certificateFor('seal-2025-a');
    }

    public function test_it_reports_the_key_ids_it_can_answer_for(): void
    {
        $directory = SealCertificateDirectory::fromConfig([
            'key_id' => SealingFixtures::KEY_ID_B,
            'certificate_path' => SealingFixtures::cryptoPath('seal-b.test.crt'),
            'retired_keys' => [[
                'key_id' => SealingFixtures::KEY_ID,
                'certificate_path' => SealingFixtures::cryptoPath('seal.test.crt'),
                'chain_path' => SealingFixtures::cryptoPath('root.test.crt'),
            ]],
        ]);

        $this->assertSame(SealingFixtures::KEY_ID_B, $directory->activeKeyId());
        $this->assertSame([SealingFixtures::KEY_ID_B, SealingFixtures::KEY_ID], $directory->keyIds());

        $retired = $directory->certificateFor(SealingFixtures::KEY_ID);

        $this->assertNull($directory->problemWith(SealingFixtures::KEY_ID));
        $this->assertNotSame('', $retired->chainPem);
        $this->assertTrue($retired->matchesFingerprint(strtoupper($retired->fingerprint)));
        $this->assertFalse($retired->matchesFingerprint(''));
    }
}
