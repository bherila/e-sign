import { useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

export interface RoleOption {
  value: string;
  label: string;
  grantable: boolean;
}

export interface MemberRow {
  publicId: string;
  name: string;
  email: string | null;
  role: string;
  roleLabel: string;
  isYou: boolean;
}

export interface InvitationRow {
  publicId: string;
  role: string;
  roleLabel: string;
  expiresAt: string;
}

export interface MembersState {
  workspace: { publicId: string; name: string };
  viewer: { role: string | null; isOwner: boolean };
  roles: RoleOption[];
  members: MemberRow[];
  invitations: InvitationRow[];
  urls: { self: string; invitations: string; member: string; invitation: string; placeholder: string };
}

export interface MembersPanelProps {
  initial: MembersState;
}

function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * One JSON call to the members routes. Every successful answer is the page's whole current state,
 * and every refusal carries the server's own sentence, which is what the page shows.
 */
async function requestJson<T>(url: string, method: string, body?: unknown): Promise<T> {
  const response = await fetch(url, {
    method,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });

  const text = await response.text();
  let data: unknown = null;

  try {
    data = text === '' ? null : JSON.parse(text);
  } catch {
    data = null;
  }

  if (!response.ok) {
    const message =
      data !== null && typeof data === 'object' && typeof (data as { message?: unknown }).message === 'string'
        ? (data as { message: string }).message
        : `The change could not be made (${response.status}).`;

    throw new Error(message);
  }

  return data as T;
}

function formatExpiry(iso: string): string {
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}

/**
 * A workspace's members and open invitations, for its owners and administrators.
 *
 * The page offers only what the viewer's role can do — an administrator is not offered the owner
 * role or an owner's row — but it decides nothing: the server refuses anything it would not
 * allow, and its message is shown as it was written.
 */
export default function MembersPanel({ initial }: MembersPanelProps) {
  const [state, setState] = useState<MembersState>(initial);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [inviteRole, setInviteRole] = useState('sender');
  const [inviteUrl, setInviteUrl] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const labelFor = (value: string): string => state.roles.find((role) => role.value === value)?.label ?? value;
  const memberUrl = (publicId: string): string => state.urls.member.replace(state.urls.placeholder, publicId);
  const invitationUrl = (publicId: string): string => state.urls.invitation.replace(state.urls.placeholder, publicId);

  async function run(action: () => Promise<MembersState>): Promise<void> {
    setBusy(true);
    setError(null);

    try {
      setState(await action());
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'The change could not be made.');
    } finally {
      setBusy(false);
    }
  }

  function changeRole(member: MemberRow, role: string): void {
    void run(() => requestJson<MembersState>(memberUrl(member.publicId), 'PATCH', { role }));
  }

  function remove(member: MemberRow): void {
    const who = member.isYou ? 'yourself' : member.name;

    if (!window.confirm(`Remove ${who} from ${state.workspace.name}? Their access ends; nothing they did is deleted.`)) {
      return;
    }

    void run(() => requestJson<MembersState>(memberUrl(member.publicId), 'DELETE'));
  }

  function revoke(invitation: InvitationRow): void {
    void run(() => requestJson<MembersState>(invitationUrl(invitation.publicId), 'DELETE'));
  }

  function invite(): void {
    setCopied(false);
    setInviteUrl(null);

    void run(async () => {
      const created = await requestJson<{ url: string; state: MembersState }>(state.urls.invitations, 'POST', {
        role: inviteRole,
      });
      setInviteUrl(created.url);

      return created.state;
    });
  }

  async function copyInvite(): Promise<void> {
    if (inviteUrl === null) {
      return;
    }

    try {
      await navigator.clipboard.writeText(inviteUrl);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }

  return (
    <div className="flex flex-col gap-8">
      {error !== null ? (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      <section aria-labelledby="members-heading">
        <h2 id="members-heading" className="text-lg font-semibold mb-3">
          Members
        </h2>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {state.members.map((member) => {
              // An owner's row is an owner's to change. The server enforces it; this only avoids
              // offering a control that can only be refused.
              const locked = member.role === 'owner' && !state.viewer.isOwner;
              const name = member.isYou ? `${member.name} (you)` : member.name;

              return (
                <TableRow key={member.publicId}>
                  <TableCell className="font-medium">{name}</TableCell>
                  <TableCell className="text-muted-foreground">{member.email ?? 'Not known until they sign in'}</TableCell>
                  <TableCell>
                    {locked ? (
                      <Badge variant="secondary">{member.roleLabel}</Badge>
                    ) : (
                      <select
                        aria-label={`Role for ${member.name}`}
                        className="rounded-md border bg-background px-2 py-1 text-sm"
                        value={member.role}
                        disabled={busy}
                        onChange={(event) => changeRole(member, event.target.value)}
                      >
                        {state.roles
                          .filter((role) => role.grantable || role.value === member.role)
                          .map((role) => (
                            <option key={role.value} value={role.value}>
                              {role.label}
                            </option>
                          ))}
                      </select>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    {locked ? null : (
                      <Button variant="outline" size="sm" disabled={busy} onClick={() => remove(member)} aria-label={`Remove ${member.name}`}>
                        Remove
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </section>

      <section aria-labelledby="invite-heading" className="flex flex-col gap-3">
        <h2 id="invite-heading" className="text-lg font-semibold">
          Invite someone
        </h2>
        <p className="text-sm text-muted-foreground">
          Creates a link that works once and expires after seven days. Whoever opens it and accepts while signed in joins
          with the role you choose, so send it only to the person it is for.
        </p>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1">
            <Label htmlFor="invite-role">Role for the new member</Label>
            <select
              id="invite-role"
              className="rounded-md border bg-background px-2 py-1 text-sm"
              value={inviteRole}
              disabled={busy}
              onChange={(event) => setInviteRole(event.target.value)}
            >
              {state.roles
                .filter((role) => role.grantable)
                .map((role) => (
                  <option key={role.value} value={role.value}>
                    {role.label}
                  </option>
                ))}
            </select>
          </div>
          <Button disabled={busy} onClick={invite}>
            Create invitation link
          </Button>
        </div>

        {inviteUrl !== null ? (
          <div className="flex flex-col gap-1">
            <Label htmlFor="invite-url">Invitation link for a new {labelFor(inviteRole)}</Label>
            <div className="flex gap-2">
              <Input id="invite-url" readOnly value={inviteUrl} onFocus={(event) => event.currentTarget.select()} />
              <Button variant="outline" onClick={() => void copyInvite()}>
                {copied ? 'Copied' : 'Copy'}
              </Button>
            </div>
            <p className="text-sm text-muted-foreground">This link is shown once. If it is lost, revoke it and create another.</p>
          </div>
        ) : null}
      </section>

      <section aria-labelledby="invitations-heading">
        <h2 id="invitations-heading" className="text-lg font-semibold mb-3">
          Open invitations
        </h2>
        {state.invitations.length === 0 ? (
          <p className="text-sm text-muted-foreground">No invitations are waiting to be accepted.</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {state.invitations.map((invitation) => (
              <li key={invitation.publicId} className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
                <span>
                  {invitation.roleLabel} · expires {formatExpiry(invitation.expiresAt)}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  onClick={() => revoke(invitation)}
                  aria-label={`Revoke the ${invitation.roleLabel} invitation`}
                >
                  Revoke
                </Button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
