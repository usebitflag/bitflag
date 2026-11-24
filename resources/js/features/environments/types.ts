export interface EnvironmentRow {
  id: string;
  name: string;
  key: string;
  color: string;
  position: number;
  status: 'active' | 'archived';
  isProtected: boolean;
  requiredApprovals: number;
  policyVersion: number;
  /** Unix seconds of the first publication, or null while nothing is published. */
  firstPublishedAt: number | null;
  version: number;
}

export interface HistoryEntry {
  id: string;
  action: string;
  actor: string;
  target: string | null;
  reason: string | null;
  occurredAt: number;
}
