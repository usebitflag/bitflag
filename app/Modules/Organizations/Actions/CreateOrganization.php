<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\CreateOrganizationData;
use App\Modules\Organizations\Data\CreateOrganizationResult;
use App\Modules\Organizations\Domain\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an organization with the signed-in person as its Org Owner. Any signed-in person may, up to a limit that keeps
 * one account from filling the system with organizations. The plan is Free until billing says otherwise.
 */
final class CreateOrganization
{
    public function __construct(
        private readonly OrganizationSlugs $slugs,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationDenied
     */
    public function handle(Actor $actor, CreateOrganizationData $data): CreateOrganizationResult
    {
        $userId = $actor->id();

        if ($actor->type() !== ActorType::User || $userId === null) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $slug = strtolower(trim($data->slug));

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a name of up to 120 characters.']);
        }

        $problem = $this->slugs->problem($slug);

        if ($problem !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        $limit = config()->integer('bitflag.organizations.max_per_user');

        if (Organization::query()->where('created_by', $userId)->count() >= $limit) {
            throw ValidationException::withMessages(['name' => "You can create up to {$limit} organizations."]);
        }

        try {
            $organization = DB::transaction(function () use ($actor, $userId, $name, $slug, $data): Organization {
                $organization = Organization::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'status' => OrganizationStatus::Active,
                    'team_size_hint' => $data->teamSize,
                    'created_by' => $userId,
                ]);

                $this->tenant->run($organization->id, function () use ($actor, $userId, $organization): void {
                    OrganizationMembership::query()->create([
                        'organization_id' => $organization->id,
                        'user_id' => $userId,
                        'role' => OrgRole::Owner,
                    ]);

                    $this->audit->record($actor, 'organization.created', new AuditTarget('organization', $organization->id, $organization->name), ['slug' => $organization->slug]);
                });

                return $organization;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That name is taken.']);
        }

        return new CreateOrganizationResult($organization->id, $organization->slug);
    }
}
