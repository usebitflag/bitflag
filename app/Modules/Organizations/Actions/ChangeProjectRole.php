<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\ChangeProjectRoleData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a person's role in a project. The project always keeps at least one Owner, even when two Owners are demoted at the
 * same moment: the project's Owner rows are locked while the count is taken. Nobody changes their own role, and a change
 * made from an out-of-date list is refused.
 */
final class ChangeProjectRole
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws LastOwner
     * @throws ValidationException
     */
    public function handle(Actor $actor, ChangeProjectRoleData $data): void
    {
        if (! $actor->can(Capability::MembersManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, function () use ($actor, $data): void {
            DB::transaction(function () use ($actor, $data): void {
                $owners = ProjectMembership::query()->where('project_id', $data->projectId)->where('role', ProjectRole::Owner->value)->lockForUpdate()->get();
                $membership = ProjectMembership::query()->where('project_id', $data->projectId)->whereKey($data->membershipId)->lockForUpdate()->firstOrFail();

                if ($membership->user_id === $actor->id()) {
                    throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
                }

                if ($membership->role === $data->role) {
                    return;
                }

                if ($membership->role === ProjectRole::Owner && $owners->count() <= 1) {
                    throw new LastOwner('This is the last Owner of the project. Make someone else an Owner first.');
                }

                $before = $membership->role;
                $version = VersionedUpdate::apply(ProjectMembership::query()->whereKey($membership->id), $data->expectedVersion, ['role' => $data->role->value]);

                $this->audit->record($actor, 'project_member.role_changed', new AuditTarget('project_member', $membership->user_id), [
                    'before' => $before->value,
                    'after' => $data->role->value,
                    'version' => $version,
                ], projectId: $data->projectId);
                $this->outbox->push(OutboxMessage::forOrganization($data->organizationId, 'internal.member.role_changed', 'user', $membership->user_id, ['user_id' => $membership->user_id, 'project_id' => $data->projectId], projectId: $data->projectId));
            });
        });
    }
}
