import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import type { MembersState } from './MembersPanel';
import MembersPanel from './MembersPanel';

/**
 * The page offers what the viewer's role can do and shows what the server answers. Controls are
 * found by their accessible names, because a control a screen reader cannot name is not really
 * offered.
 */

const PLACEHOLDER = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

function state(overrides: Partial<MembersState> = {}): MembersState {
  return {
    workspace: { publicId: '01J00000000000000000000001', name: 'Synthetic Workspace' },
    viewer: { role: 'admin', isOwner: false },
    roles: [
      { value: 'owner', label: 'Owner', grantable: false },
      { value: 'admin', label: 'Administrator', grantable: true },
      { value: 'sender', label: 'Sender', grantable: true },
      { value: 'auditor', label: 'Auditor', grantable: true },
    ],
    members: [
      { publicId: '01J000000000000000000000A1', name: 'Example Owner', email: 'owner@example.test', role: 'owner', roleLabel: 'Owner', isYou: false },
      { publicId: '01J000000000000000000000A2', name: 'Example Admin', email: 'admin@example.test', role: 'admin', roleLabel: 'Administrator', isYou: true },
      { publicId: '01J000000000000000000000A3', name: 'Example Sender', email: null, role: 'sender', roleLabel: 'Sender', isYou: false },
    ],
    invitations: [],
    urls: {
      self: '/workspaces/ws/members',
      dashboard: '/dashboard',
      invitations: '/workspaces/ws/invitations',
      member: `/workspaces/ws/members/${PLACEHOLDER}`,
      invitation: `/workspaces/ws/invitations/${PLACEHOLDER}`,
      placeholder: PLACEHOLDER,
    },
    ...overrides,
  };
}

function fetchMock(): jest.Mock {
  return globalThis.fetch as unknown as jest.Mock;
}

function respond(status: number, body: unknown): void {
  fetchMock().mockResolvedValueOnce({
    ok: status >= 200 && status < 300,
    status,
    text: () => Promise.resolve(JSON.stringify(body)),
  });
}

beforeEach(() => {
  globalThis.fetch = jest.fn() as unknown as typeof fetch;
});

describe('MembersPanel', () => {
  it('does not offer an administrator an owner row or the owner role', () => {
    render(<MembersPanel initial={state()} />);

    expect(screen.queryByRole('combobox', { name: 'Role for Example Owner' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Remove Example Owner' })).toBeNull();

    const senderRole = screen.getByRole('combobox', { name: 'Role for Example Sender' });
    expect(Array.from((senderRole as HTMLSelectElement).options).map((option) => option.value)).toEqual(['admin', 'sender', 'auditor']);
    expect(screen.getByText('Not known until they sign in')).toBeTruthy();
  });

  it('offers an owner every role and every row', () => {
    const initial = state({ viewer: { role: 'owner', isOwner: true }, roles: state().roles.map((role) => ({ ...role, grantable: true })) });
    render(<MembersPanel initial={initial} />);

    expect(screen.getByRole('combobox', { name: 'Role for Example Owner' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Remove Example Owner' })).toBeTruthy();
  });

  it('changes a role through the member route and shows the state the server returns', async () => {
    const before = state();
    const after: MembersState = {
      ...before,
      members: before.members.map((member) =>
        member.name === 'Example Sender' ? { ...member, role: 'auditor', roleLabel: 'Auditor' } : member,
      ),
    };
    respond(200, after);

    render(<MembersPanel initial={state()} />);
    fireEvent.change(screen.getByRole('combobox', { name: 'Role for Example Sender' }), { target: { value: 'auditor' } });

    await waitFor(() => expect((screen.getByRole('combobox', { name: 'Role for Example Sender' }) as HTMLSelectElement).value).toBe('auditor'));

    const [url, init] = fetchMock().mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/workspaces/ws/members/01J000000000000000000000A3');
    expect(init.method).toBe('PATCH');
    expect(JSON.parse(String(init.body))).toEqual({ role: 'auditor' });
    expect((init.headers as Record<string, string>).Accept).toBe('application/json');
  });

  it("shows the server's refusal as it was written and keeps the stored role", async () => {
    respond(422, { message: "This is the workspace's only owner.", code: 'last_owner' });

    render(<MembersPanel initial={state()} />);
    fireEvent.change(screen.getByRole('combobox', { name: 'Role for Example Sender' }), { target: { value: 'auditor' } });

    expect(await screen.findByRole('alert')).toHaveProperty('textContent', "This is the workspace's only owner.");
    expect((screen.getByRole('combobox', { name: 'Role for Example Sender' }) as HTMLSelectElement).value).toBe('sender');
  });

  it('shows a new invitation link once, and the invitation in the open list', async () => {
    const after = state({ invitations: [{ publicId: '01J000000000000000000000B1', role: 'auditor', roleLabel: 'Auditor', expiresAt: '2026-09-21T12:00:00+00:00' }] });
    respond(201, { url: 'https://esign.example.test/invitations/' + 'a'.repeat(64), state: after });

    render(<MembersPanel initial={state()} />);
    fireEvent.change(screen.getByLabelText('Role for the new member'), { target: { value: 'auditor' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create invitation link' }));

    const link = await screen.findByLabelText('Invitation link for a new Auditor');
    expect((link as HTMLInputElement).value).toBe('https://esign.example.test/invitations/' + 'a'.repeat(64));
    expect(screen.getByRole('button', { name: 'Revoke the Auditor invitation' })).toBeTruthy();

    const [, init] = fetchMock().mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toEqual({ role: 'auditor' });
  });

  it('forgets a generated link as soon as the role selector changes', async () => {
    respond(201, { url: 'https://esign.example.test/invitations/' + 'b'.repeat(64), state: state() });

    render(<MembersPanel initial={state()} />);
    fireEvent.change(screen.getByLabelText('Role for the new member'), { target: { value: 'admin' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create invitation link' }));
    expect(await screen.findByLabelText('Invitation link for a new Administrator')).toBeTruthy();

    // A label that followed the selector would now call an administrator link an auditor one.
    fireEvent.change(screen.getByLabelText('Role for the new member'), { target: { value: 'auditor' } });

    expect(screen.queryByLabelText(/Invitation link for a new/)).toBeNull();
  });

  it('stops offering controls once the viewer no longer manages members', async () => {
    respond(200, { ...state(), viewer: { role: 'sender', isOwner: false } });

    render(<MembersPanel initial={state()} />);
    fireEvent.change(screen.getByRole('combobox', { name: 'Role for Example Admin' }), { target: { value: 'sender' } });

    expect(await screen.findByRole('status')).toHaveProperty(
      'textContent',
      expect.stringContaining('You no longer manage the members of Synthetic Workspace'),
    );
    expect(screen.queryAllByRole('combobox')).toHaveLength(0);
    expect(screen.queryByRole('button', { name: 'Create invitation link' })).toBeNull();
  });
});
