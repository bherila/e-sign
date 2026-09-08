# Identity

See docs/ARCHITECTURE.md for what this module owns. Keep cross-module calls behind interfaces.

## What is here

| Path | Purpose |
|---|---|
| `Enums/WorkspaceRole.php` | `owner`, `admin`, `sender`, `auditor` and the permission map every authorization decision reads. |
| `Enums/WorkspacePermission.php` | The ability names, which double as the Gate ability strings. |
| `Models/Workspace.php` | The tenancy boundary. Route-bound on `public_id`; `whereMemberOf()` is the only supported lookup on someone's behalf. |
| `Models/WorkspaceMembership.php` | One person's role in one workspace. The only source of workspace authority. |
| `Models/IdentityBinding.php` | The `(issuer, subject)` tuple a local user is bound to. Never email. |
| `Policies/WorkspacePolicy.php` | Every workspace-scoped ability. Registered in `AppServiceProvider`, since auto-discovery does not look in domain modules. |
| `Services/OwnerBootstrapper.php` | The only code that grants `owner`, in one transaction, idempotently. |
| `Console/BootstrapOwnerCommand.php` | `esign:bootstrap-owner`. See `docs/operations/bootstrap.md`. |
| `Audit/` | The append-only `esign_audit_events` writer. |

## Rules that are not obvious from the code

- **Identity binds on `(issuer, subject)`, never email.** `IdentityBinding::forIssuerSubject()`
  is the only lookup; there is deliberately no `findByEmail`. An address is contact data.
- **There is no default administrator.** No `is_admin` column, no first-login promotion, no
  super-user branch in the policy. `owner` comes from `esign:bootstrap-owner` or from an
  existing owner, and nothing else.
- **A directory grant is not a role.** Being admitted to the application by the identity
  provider gets someone authenticated and nothing more. Without a membership row every
  ability, `view` included, is denied.
- **Removing access removes only access.** Nothing references a membership id, and every
  foreign key onto `workspaces.id` is `RESTRICT`, so revoking access can never reach an
  envelope, an artifact, or an audit event. `tests/Feature/Identity/MembershipRemovalTest.php`
  asserts this against a probe table standing in for the future `envelopes` table.
- **Cross-workspace isolation is a suite, not a check.** New surfaces (imports, downloads,
  queue jobs) add their cases to
  `tests/Feature/Identity/CrossWorkspaceIsolationTest.php` rather than testing isolation
  somewhere of their own.
