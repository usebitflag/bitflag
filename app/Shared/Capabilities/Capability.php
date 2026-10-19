<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Everything a person or credential can be allowed to do. Policies and actions check capabilities, never role names.
 * The canonical table of which role holds which capability is in docs/conventions.md.
 */
enum Capability: string
{
    // Project capabilities
    case ProjectRead = 'project.read';
    case ProjectManage = 'project.manage';
    case MembersManage = 'members.manage';
    case FlagsDraftWrite = 'flags.draft.write';
    case SegmentsWrite = 'segments.write';
    case EnvironmentPublish = 'environment.publish';
    case EnvironmentPublishProtected = 'environment.publish.protected';
    case ChangesetApprove = 'changeset.approve';
    case EnvironmentManage = 'environment.manage';
    case CredentialsManage = 'credentials.manage';
    case ExperimentsManage = 'experiments.manage';
    case IntegrationsManage = 'integrations.manage';
    case AuditRead = 'audit.read';
    case SensitiveRead = 'sensitive.read';
    case ChangesetSubmit = 'changeset.submit';
    case AuditExport = 'audit.export';
    case TelemetrySubjectDelete = 'telemetry.subject.delete';

    // Organization capabilities
    case OrgRead = 'org.read';
    case OrgManage = 'org.manage';
    case OrgMembersManage = 'org.members.manage';
    case OrgBillingManage = 'org.billing.manage';
    case ProjectsCreate = 'projects.create';

    public function isOrganizationLevel(): bool
    {
        return in_array($this, [
            self::OrgRead,
            self::OrgManage,
            self::OrgMembersManage,
            self::OrgBillingManage,
            self::ProjectsCreate,
        ], true);
    }
}
