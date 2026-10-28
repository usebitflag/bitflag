export type EnvironmentKeyType = 'server' | 'browser' | 'management';

export interface KeyKind {
  type: EnvironmentKeyType;
  title: string;
  help: string;
  /** The line under the keys table that says what the kind is for. */
  note: string;
  variable: string;
  scopes: { value: string; label: string; help: string }[];
  defaults: string[];
}

/** The three kinds of key made on an environment's Keys tab, in the order the wizard offers them. */
export const KEY_KINDS: KeyKind[] = [
  {
    type: 'server',
    title: 'Server SDK',
    help: 'Backend services. Secret; keep it out of browsers and mobile apps.',
    note: 'Secret. For backend SDKs (Node, PHP, Laravel). Reads every flag and can call remote evaluation.',
    variable: 'BITFLAG_SERVER_KEY',
    scopes: [
      {
        value: 'snapshot:read',
        label: 'Read snapshots',
        help: 'Download the environment snapshot.',
      },
      {
        value: 'evaluate',
        label: 'Evaluate remotely',
        help: 'Ask BitFlag to evaluate flags for a context.',
      },
      { value: 'events:write', label: 'Send events', help: 'Report evaluation summaries.' },
    ],
    defaults: ['snapshot:read'],
  },
  {
    type: 'browser',
    title: 'Browser SDK',
    help: 'Web apps. Public; limited to client-visible flags and allowed origins.',
    note: 'Public. For web apps. Only fetches client-visible flags, from allowed origins.',
    variable: 'BITFLAG_CLIENT_KEY',
    scopes: [
      { value: 'snapshot:read', label: 'Read snapshots', help: 'Download the public snapshot.' },
      { value: 'events:write', label: 'Send events', help: 'Report evaluation summaries.' },
    ],
    defaults: ['snapshot:read'],
  },
  {
    type: 'management',
    title: 'CLI, CI or API',
    help: 'Scripts that read flags or publish. Secret, scoped to this environment.',
    note: 'Secret. For CI and scripts using the CLI or API. Scoped to this environment.',
    variable: 'BITFLAG_API_KEY',
    scopes: [
      { value: 'flags:read', label: 'Read flags', help: 'Read project and flag data.' },
      {
        value: 'sensitive:read',
        label: 'Read sensitive data',
        help: 'See override keys and include lists.',
      },
      { value: 'flags:write', label: 'Edit drafts', help: 'Change flags and segments in drafts.' },
      {
        value: 'publish',
        label: 'Publish',
        help: 'Submit and publish changes. Protected environments still need a review.',
      },
    ],
    defaults: ['flags:read'],
  },
];

export const EXPIRY_OPTIONS = [
  { value: '', label: 'Never' },
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: '365', label: '1 year' },
];

export const OVERLAP_OPTIONS = [
  { value: '3600', label: '1 hour' },
  { value: '86400', label: '24 hours' },
  { value: '604800', label: '7 days' },
];
