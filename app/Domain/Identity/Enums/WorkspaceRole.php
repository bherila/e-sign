<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Workspace-scoped local roles.
 *
 * A directory grant at the identity provider admits a person to the application. It never
 * confers a role here: membership rows are the only source of workspace authority, and
 * `owner` is only ever granted by the explicit `esign:bootstrap-owner` command or by an
 * existing owner. There is no implicit administrator and no first-login promotion.
 *
 * Stored as a string so a new role is an additive migration-free change and so the column
 * reads the same on SQLite, MySQL 8, and MariaDB (no native ENUM; see
 * docs/adr/0002-supported-databases.md).
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Sender = 'sender';
    case Auditor = 'auditor';

    /**
     * Seniority, highest first. Used only for "X and above" readability; the permission map
     * below is authoritative, so a role is never granted an ability by rank alone.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 400,
            self::Admin => 300,
            self::Sender => 200,
            self::Auditor => 100,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Sender => 'Sender',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * The permission map. Everything the policy allows comes from here.
     *
     * @return list<WorkspacePermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                WorkspacePermission::View,
                WorkspacePermission::Update,
                WorkspacePermission::Delete,
                WorkspacePermission::ManageMembers,
                WorkspacePermission::CreateTemplates,
                WorkspacePermission::CreateEnvelopes,
                WorkspacePermission::ReadAudit,
                WorkspacePermission::RotateCredentials,
            ],
            self::Admin => [
                WorkspacePermission::View,
                WorkspacePermission::Update,
                WorkspacePermission::ManageMembers,
                WorkspacePermission::CreateTemplates,
                WorkspacePermission::CreateEnvelopes,
                WorkspacePermission::ReadAudit,
            ],
            self::Sender => [
                WorkspacePermission::View,
                WorkspacePermission::CreateTemplates,
                WorkspacePermission::CreateEnvelopes,
                WorkspacePermission::ReadAudit,
            ],
            self::Auditor => [
                WorkspacePermission::View,
                WorkspacePermission::ReadAudit,
            ],
        };
    }

    public function can(WorkspacePermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
