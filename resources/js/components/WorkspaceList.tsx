import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

export interface WorkspaceSummary {
  publicId: string;
  name: string;
  slug: string;
  role: string;
  roleLabel: string;
}

export interface WorkspaceListProps {
  workspaces: WorkspaceSummary[];
}

/**
 * The workspaces this person belongs to, and the role each membership carries.
 *
 * Read-only. Nothing here grants, changes, or requests a role; membership is granted by an
 * owner or administrator, or by `esign:bootstrap-owner` for the very first one.
 */
export default function WorkspaceList({ workspaces }: WorkspaceListProps) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Workspace</TableHead>
          <TableHead>Slug</TableHead>
          <TableHead>Your role</TableHead>
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
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
