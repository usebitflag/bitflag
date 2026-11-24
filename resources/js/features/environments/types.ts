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
  allowedOrigins: string[];
  /** Seconds, or null for no limit. */
  defaultMaxSnapshotAgeSeconds: number | null;
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

export interface KeyRow {
  id: string;
  type: 'server' | 'browser' | 'management' | 'personal';
  label: string;
  masked: string;
  prefix: string;
  last4: string;
  scopes: string[];
  createdAt: number;
  createdBy: { name: string | null; formerMember: boolean };
  lastUsedAt: number | null;
  lastUsedClient: string | null;
  expiresAt: number | null;
  state: 'active' | 'rotating' | 'expired' | 'revoked';
  overlapEndsAt: number | null;
}
