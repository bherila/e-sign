import './bootstrap';

import { createRoot } from 'react-dom/client';

import type { WorkspaceSummary } from '@/components/WorkspaceList';
import WorkspaceList from '@/components/WorkspaceList';

/**
 * Session state written by the server into an attribute, so a malformed value degrades to
 * an empty list rather than a blank page.
 */
function parseWorkspaces(raw: string | null): WorkspaceSummary[] {
  if (!raw) {
    return [];
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    return Array.isArray(parsed) ? (parsed as WorkspaceSummary[]) : [];
  } catch {
    return [];
  }
}

const mount = document.getElementById('workspaces');

if (mount) {
  const workspaces = parseWorkspaces(mount.getAttribute('data-workspaces'));

  if (workspaces.length > 0) {
    createRoot(mount).render(<WorkspaceList workspaces={workspaces} />);
  }
}
