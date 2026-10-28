<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\RemoveProjectMemberData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Events\MemberRemovedFromProject;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes another person out of a project, or, when the person is the actor, lets them leave it: leaving needs no permission
 * beyond being a member, removing someone else needs `members.manage`. Either way the project keeps at least one Owner.
 * Once committed, `MemberRemovedFromProject` lets other modules revoke what depended on belonging.
 */
final class RemoveProjectMember
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws LastOwner
     * @throws ValidationException
     */
    public function handle(Actor $actor, RemoveProjectMemberData $data): void
    {
        $removed = $this->tenant->run($data->organizationId, fn (): string => DB::transaction(function () use ($actor, $data): string {
            $owners = ProjectMembership::query()->where('project_id', $data->projectId)->where('role', ProjectRole::Owner->value)->lockForUpdate()->get();
            $membership = ProjectMembership::query()->where('project_id', $data->projectId)->whereKey($data->membershipId)->lockForUpdate()->firstOrFail();
            $leaving = $membership->user_id === $actor->id();

            if (! $leaving && ! $actor->can(Capability::MembersManage, new ProjectScope($data->organizationId, $data->projectId))) {
                throw new AuthorizationDenied;
            }

            if ($membership->role === ProjectRole::Owner && $owners->count() <= 1) {
                throw new LastOwner('This is the last Owner of the project. Make someone else an Owner first.');
            }

            $membership->delete();

            $this->audit->record($actor, 'project_member.removed', new AuditTarget('project_member', $membership->user_id), [
                'role' => $membership->role->value,
                'left' => $leaving,
            ], projectId: $data->projectId);

            return $membership->user_id;
        }));

        event(new MemberRemovedFromProject($data->organizationId, $data->projectId, $removed));
    }
}
