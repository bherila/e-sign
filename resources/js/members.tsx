import './bootstrap';

import { createRoot } from 'react-dom/client';

import type { MembersState } from '@/components/members/MembersPanel';
import MembersPanel from '@/components/members/MembersPanel';

/**
 * Page state written by the server into an attribute. A malformed value renders nothing rather
 * than a page of controls acting on a state nobody sent.
 */
function parseState(raw: string | null): MembersState | null {
  if (!raw) {
    return null;
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    return parsed !== null && typeof parsed === 'object' && Array.isArray((parsed as MembersState).members)
      ? (parsed as MembersState)
      : null;
  } catch {
    return null;
  }
}

const mount = document.getElementById('members');

if (mount) {
  const state = parseState(mount.getAttribute('data-members'));

  if (state !== null) {
    createRoot(mount).render(<MembersPanel initial={state} />);
  }
}
