# Workspace members

Who has which role in a workspace, and how that changes after the first owner is bootstrapped
(issue #110). The first owner comes from `esign:bootstrap-owner`
([bootstrap.md](bootstrap.md)); everyone after that is granted a role here.

| | |
|---|---|
| Page | **Manage members** on the dashboard, `/workspaces/{workspace}/members` |
| Who can use it | the workspace's owners and administrators |
| Service | `App\Domain\Identity\Services\WorkspaceMembers` — the only code that changes a role after bootstrap |
| Tests | `tests/Feature/Identity/WorkspaceMembersTest.php`, `MembersHttpTest.php` |

## Adding someone: invitation links

Identity binds on the identity provider's `(issuer, subject)`, never an email address, so there
is no "add by email". An owner or administrator creates an **invitation link** for a role and
sends it to the person, who opens it while signed in and accepts.

- **Single use, seven days.** The first person to accept it joins; after that, or after it
  expires or is revoked, the link says it cannot be used.
- **Shown once.** Only a SHA-256 digest of the token is stored. A lost link cannot be read back —
  revoke it and create another.
- **Whoever accepts, joins.** The membership binds to the account the person is signed in with.
  Send the link only to the person it is for.
- **Opening it changes nothing.** Following a link shows the workspace and role; only pressing
  **Accept invitation** (a POST) joins. A mail scanner or a link preview cannot use it.
- **The token never reaches the session store.** Following a link runs without a session: it
  moves the token into a short-lived, encrypted, HTTP-only cookie scoped to `/invitations` and
  redirects to `/invitations/accept`, a URL with no token in it. Someone not signed in is sent to
  sign in and brought back there. Sessions are commonly a plaintext database table, so a
  remembered URL holding the token would put a live credential where a read-only copy of that
  table could use it.
- **Links are rooted at `APP_URL`**, never at the host a request named.
- **An existing member** cannot use a link, and following one does not use it up or change their
  role.

## The rules

| Rule | Refusal code |
|---|---|
| Only an owner or administrator of this workspace manages its members | `not_permitted` (403) |
| Only an owner grants `owner`, or changes or removes an owner's membership | `owner_only` (403) |
| The last owner cannot be demoted or removed | `last_owner` (422) |
| A member cannot accept an invitation to a workspace they are already in | `already_member` (422) |
| An expired, revoked, accepted or unknown invitation cannot be used | `invitation_unavailable` (422) |

Authority is decided under the same locks as the change: the workspace's owner rows first, then
the actor's and the target's membership rows in id order. An actor demoted by a concurrent request
cannot finish a change on the authority they just lost, and two changes cannot each see another
owner and leave the workspace with none.

**Removing access removes only access.** Removing a member deletes one membership row. Envelopes,
documents, artifacts and audit events stay exactly as they are; the schema's `RESTRICT` foreign
keys enforce that ([MembershipRemovalTest](../../tests/Feature/Identity/MembershipRemovalTest.php)).
To stop someone signing in at all, also delete their identity binding or disable the account.

## Audit trail

Every change writes one event; a change that changes nothing writes none.

| Action | Payload |
|---|---|
| `identity.member_invited` | invitation public id, role, expiry — never the token |
| `identity.member_invitation_revoked` | invitation public id, role |
| `identity.member_joined` | invitation public id, user id, role |
| `identity.member_role_changed` | user id, from, to |
| `identity.member_removed` | user id, role |

## Central administration

Managing these roles from the identity provider's directory is issue #111. It will call the same
`WorkspaceMembers` service, so every rule above applies there too, and this page keeps working
when the provider is unreachable.
