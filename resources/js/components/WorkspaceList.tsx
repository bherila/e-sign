import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

export interface WorkspaceSummary {
  publicId: string;
  name: string;
  slug: string;
  role: string;
  roleLabel: string;
  membersUrl?: string | null;
}

export interface WorkspaceListProps {
  workspaces: WorkspaceSummary[];
}

/**
 * The workspaces this person belongs to, and the role each membership carries.
 *
 * Nothing here grants, changes, or requests a role. An owner or administrator does that on the
 * workspace's members page, linked from their own row; the very first owner comes from
 * `esign:bootstrap-owner`.
 */
export default function WorkspaceList({ workspaces }: WorkspaceListProps) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Workspace</TableHead>
          <TableHead>Slug</TableHead>
          <TableHead>Your role</TableHead>
          <TableHead>
            <span className="sr-only">Actions</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {workspaces.map((workspace) => (
          <TableRow key={workspace.publicId}>
            <TableCell className="font-medium">{workspace.name}</TableCell>
            <TableCell className="text-muted-foreground">{workspace.slug}</TableCell>
            <TableCell>
              <Badge variant="secondary">{workspace.roleLabel}</Badge>
            </TableCell>
            <TableCell className="text-right">
              {workspace.membersUrl ? (
                <a href={workspace.membersUrl} className="text-sm underline underline-offset-4">
                  Manage members
                </a>
              ) : null}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
