<?php

declare(strict_types=1);

namespace App\Shared\Capabilities;

/**
 * Which project capabilities each project role holds. Mirrors the table in docs/conventions.md; a test compares the two.
 */
final class RoleCapabilityMap
{
    /**
     * @return list<Capability>
     */
    public static function for(ProjectRole $role): array
    {
        return match ($role) {
            ProjectRole::Owner => [
                Capability::ProjectRead,
                Capability::ProjectManage,
                Capability::MembersManage,
                Capability::FlagsDraftWrite,
                Capability::SegmentsWrite,
                Capability::EnvironmentPublish,
                Capability::EnvironmentPublishProtected,
                Capability::ChangesetApprove,
                Capability::EnvironmentManage,
                Capability::CredentialsManage,
                Capability::ExperimentsManage,
                Capability::IntegrationsManage,
                Capability::AuditRead,
                Capability::SensitiveRead,
                Capability::ChangesetSubmit,
                Capability::AuditExport,
                Capability::TelemetrySubjectDelete,
            ],
            ProjectRole::Publisher => [
                Capability::ProjectRead,
                Capability::FlagsDraftWrite,
                Capability::SegmentsWrite,
                Capability::EnvironmentPublish,
                Capability::EnvironmentPublishProtected,
                Capability::ChangesetApprove,
                Capability::ExperimentsManage,
                Capability::AuditRead,
                Capability::SensitiveRead,
                Capability::ChangesetSubmit,
                Capability::AuditExport,
            ],
            ProjectRole::Developer => [
                Capability::ProjectRead,
                Capability::FlagsDraftWrite,
                Capability::SegmentsWrite,
                Capability::EnvironmentPublish,
                Capability::AuditRead,
                Capability::SensitiveRead,
                Capability::ChangesetSubmit,
                Capability::AuditExport,
            ],
            ProjectRole::Viewer => [
                Capability::ProjectRead,
                Capability::AuditRead,
                Capability::AuditExport,
            ],
        };
    }

    public static function allows(ProjectRole $role, Capability $capability): bool
    {
        return in_array($capability, self::for($role), true);
    }
}
