/**
 * Props the server shares with every Inertia page (App\Http\Middleware\HandleInertiaRequests).
 * Identifiers, names and booleans only.
 */

import type { CapabilityName } from './capabilities';

export type ProjectRole = 'owner' | 'publisher' | 'developer' | 'viewer';
export type OrgRole = 'owner' | 'member';
export type ToastTone = 'neutral' | 'accent';

export interface SharedUser {
  id: string;
  name: string;
  email: string;
  initials: string;
  emailVerified: boolean;
}

export interface SharedEnvironment {
  id: string;
  key: string;
  name: string;
  color: string;
  /** Null until the environment has a published revision. */
  revision: number | null;
}

export interface SharedScope {
  organization: { id: string; slug: string; name: string } | null;
  project: { id: string; key: string; name: string } | null;
  environment: SharedEnvironment | null;
  projectRole: ProjectRole | null;
  orgRole: OrgRole | null;
}

export interface NavigationItem {
  key: string;
  label: string;
  icon: string;
  group: string;
  href: string;
  count: number | null;
  active: boolean;
}

export type SharedProps = {
  auth: { user: SharedUser | null };
  scope: SharedScope;
  environments: SharedEnvironment[];
  navigation: NavigationItem[];
  /** Capability name to allowed. The server always rechecks. */
  can: Partial<Record<CapabilityName, boolean>>;
  flash: { toast: { message: string; tone: ToastTone } | null };
  requestId: string | null;
  /** Validation errors flashed by the last request, by field. */
  errors?: Record<string, string>;
  /** Loaded on demand by a partial reload when the switcher opens. */
  switcher?: SwitcherData;
};

export interface SwitcherData {
  organizations: Array<{
    id: string;
    slug: string;
    name: string;
    role: OrgRole;
    projectCount: number;
    projects: Array<{ id: string; key: string; name: string }>;
  }>;
}
