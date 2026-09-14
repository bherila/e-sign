<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use RuntimeException;

/**
 * A membership change this application will not make, and the stable reason why.
 *
 * Every refusal from {@see WorkspaceMembers} is one of these, so the members page and the
 * delegated-access adapter answer the same situation with the same code. The message is written
 * for the person who asked; the code is what a caller branches on.
 */
final class MembershipChangeRefused extends RuntimeException
{
    public const NOT_PERMITTED = 'not_permitted';

    public const OWNER_ONLY = 'owner_only';

    public const LAST_OWNER = 'last_owner';

    public const ALREADY_MEMBER = 'already_member';

    public const INVITATION_UNAVAILABLE = 'invitation_unavailable';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notPermitted(): self
    {
        return new self(self::NOT_PERMITTED, 'Only an owner or an administrator of this workspace can manage its members.');
    }

    public static function ownerOnly(): self
    {
        return new self(self::OWNER_ONLY, 'Only an owner can grant, change or remove the owner role.');
    }

    public static function lastOwner(): self
    {
        return new self(
            self::LAST_OWNER,
            'This is the workspace\'s only owner. Make someone else an owner first: a workspace without an owner '
            .'cannot delete itself, rotate its service credentials or grant ownership again.',
        );
    }

    public static function alreadyMember(): self
    {
        return new self(
            self::ALREADY_MEMBER,
            'You are already a member of this workspace. The invitation was not used, so it still works for '
            .'the person it was meant for; ask an owner or administrator to change your role instead.',
        );
    }

    public static function invitationUnavailable(): self
    {
        return new self(
            self::INVITATION_UNAVAILABLE,
            'This invitation cannot be used. It may have expired, been revoked or already been accepted. Ask the '
            .'person who sent it for a new one.',
        );
    }
}
