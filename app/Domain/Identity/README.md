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
| `Credentials/Scope.php` | The scope vocabulary API credentials are granted from. Nothing is implied by anything else. |
| `Credentials/ServiceCredential.php` | An API caller as a principal: one workspace, a scope list, a salted digest of its secret. |
| `Credentials/CredentialSecret.php` | Minting, prefix parsing, and digesting of secrets. The only place the hashing choice lives. |
| `Credentials/ServiceCredentialIssuer.php` | The only code that issues, rotates, or revokes a credential, and the only code that ever holds a plaintext. |
| `Credentials/CurrentPrincipal.php` | The service principal acting in the current request. Scoped binding; ask it for the workspace before looking a resource up. |
| `Credentials/Console/` | `esign:credential:issue`, `:rotate`, `:revoke`, `:list`. See `docs/operations/service-credentials.md`. |

The HTTP adapters for credentials are `app/Http/Middleware/AuthenticateServiceCredential.php`
(alias `service-credential`) and `app/Http/Middleware/RequireScope.php` (alias
`require-scope`), registered in `bootstrap/app.php`.

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
- **An API caller is a principal, not a person.** A service credential has no membership, no
  role, and no user row. It cannot be given one, and issuing one grants nobody anything.
  Workspace-scoped means the workspace comes from the credential, never from the request.
- **Scopes imply nothing.** `envelopes:write` does not grant `envelopes:read`, and
  `compat:firma-v1` grants no resource access at all — it only admits a credential to the
  compatibility facade, whose routes require the same scopes as the native API.
- **Scope the query, do not check the row.** Constrain by
  `CurrentPrincipal::workspaceIdOrFail()` before using an identifier from the request.
  Loading by id and comparing the workspace afterwards leaks existence even when it 403s.
- **A secret is shown once and stored as a digest.** There is no read-back command because
  there is nothing to read back, and nothing logs a secret: failures log the public prefix.
- **Cross-workspace isolation is a suite, not a check.** New surfaces (imports, downloads,
  queue jobs) add their cases to
  `tests/Feature/Identity/CrossWorkspaceIsolationTest.php` rather than testing isolation
  somewhere of their own.
