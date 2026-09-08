<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Enums\WorkspaceRole;
use App\Domain\Identity\Models\Workspace;
use App\Domain\Identity\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Fixtures for the document intake and download tests: a workspace with a member in a
 * chosen role, and a real UploadedFile built from a committed synthetic PDF.
 *
 * The uploads are real files on disk rather than `UploadedFile::fake()`, because everything
 * under test — the sniffed MIME check, the preflight parse, the digest, the byte-for-byte
 * retention assertion — depends on the actual bytes.
 */
final class DocumentWorkspace
{
    /** @var array<int, string> */
    private static array $tempPaths = [];

    public static function memberOf(Workspace $workspace, WorkspaceRole $role): User
    {
        $user = User::factory()->create();

        WorkspaceMembership::create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role->value,
        ]);

        return $user;
    }

    /** A copy of a committed fixture, wrapped as an upload. The copy is deleted on teardown. */
    public static function upload(string $fixture, ?string $clientName = null): UploadedFile
    {
        return self::uploadOfBytes(PdfFixtures::bytes($fixture), $clientName ?? $fixture.'.pdf');
    }

    public static function uploadOfBytes(string $bytes, string $clientName): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'esign-upload-test-');
        file_put_contents($path, $bytes);
        self::$tempPaths[] = $path;

        return new UploadedFile($path, $clientName, 'application/pdf', null, true);
    }

    public static function cleanUp(): void
    {
        foreach (self::$tempPaths as $path) {
            @unlink($path);
        }

        self::$tempPaths = [];
    }
}
