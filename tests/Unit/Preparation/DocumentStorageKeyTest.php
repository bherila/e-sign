<?php

declare(strict_types=1);

namespace Tests\Unit\Preparation;

use App\Domain\Preparation\Documents\DocumentStorageKey;
use App\Domain\Preparation\Documents\RevisionKind;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentStorageKeyTest extends TestCase
{
    private const WORKSPACE = '01K4J8Z0000000000000000000';

    private const DOCUMENT = '01K4J8Z1111111111111111111';

    private const DIGEST = 'bf12cfc222734a3a5507cff6add3e0f752ae8be242880a1b7c1a7165b671426b';

    public function test_the_key_is_scoped_by_workspace_and_document_and_named_by_content(): void
    {
        $key = DocumentStorageKey::for(self::WORKSPACE, self::DOCUMENT, RevisionKind::Original, self::DIGEST);

        $this->assertSame(
            'documents/'.self::WORKSPACE.'/'.self::DOCUMENT.'/original-'.self::DIGEST.'.pdf',
            $key->value,
        );
        $this->assertSame($key->value, (string) $key);
    }

    public function test_the_two_kinds_of_one_document_never_collide(): void
    {
        $original = DocumentStorageKey::for(self::WORKSPACE, self::DOCUMENT, RevisionKind::Original, self::DIGEST);
        $review = DocumentStorageKey::for(self::WORKSPACE, self::DOCUMENT, RevisionKind::Review, self::DIGEST);

        $this->assertNotSame($original->value, $review->value);
    }

    public function test_different_content_gets_a_different_key_so_nothing_is_ever_overwritten(): void
    {
        $first = DocumentStorageKey::for(self::WORKSPACE, self::DOCUMENT, RevisionKind::Review, self::DIGEST);
        $second = DocumentStorageKey::for(
            self::WORKSPACE,
            self::DOCUMENT,
            RevisionKind::Review,
            str_repeat('a', 64),
        );

        $this->assertNotSame($first->value, $second->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function hostileInputs(): array
    {
        return [
            'traversal in the workspace segment' => ['../../etc', self::DOCUMENT, self::DIGEST],
            'traversal in the document segment' => [self::WORKSPACE, '..', self::DIGEST],
            'separator in a segment' => [self::WORKSPACE, 'a/b/c/document', self::DIGEST],
            'null byte in a segment' => [self::WORKSPACE, "01K4J8Z1111111111111111111\0", self::DIGEST],
            'empty segment' => [self::WORKSPACE, '', self::DIGEST],
            'uppercase digest' => [self::WORKSPACE, self::DOCUMENT, strtoupper(self::DIGEST)],
            'truncated digest' => [self::WORKSPACE, self::DOCUMENT, substr(self::DIGEST, 0, 32)],
            'digest with a separator' => [self::WORKSPACE, self::DOCUMENT, str_repeat('a', 63).'/'],
        ];
    }

    #[DataProvider('hostileInputs')]
    public function test_a_key_cannot_be_built_from_anything_that_could_escape_its_prefix(
        string $workspace,
        string $document,
        string $digest,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        DocumentStorageKey::for($workspace, $document, RevisionKind::Original, $digest);
    }
}
