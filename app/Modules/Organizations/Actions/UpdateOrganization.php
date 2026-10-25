<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use App\Modules\Organizations\Data\UpdateOrganizationData;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Queries\OrganizationSlugs;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\OrganizationScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames an organization or changes its URL name. Changing the URL name breaks old links, and no redirects are kept.
 * Returns the new version.
 */
final class UpdateOrganization
{
    public function __construct(
        private readonly OrganizationSlugs $slugs,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, UpdateOrganizationData $data): int
    {
        if (! $actor->can(Capability::OrgManage, new OrganizationScope($data->organizationId))) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $slug = strtolower(trim($data->slug));

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a name of up to 120 characters.']);
        }

        $problem = $this->slugs->problem($slug, $data->organizationId);

        if ($problem !== null) {
            throw ValidationException::withMessages(['slug' => $problem]);
        }

        try {
            return DB::transaction(function () use ($actor, $data, $name, $slug): int {
                $organization = Organization::query()->findOrFail($data->organizationId);
                $before = ['name' => $organization->name, 'slug' => $organization->slug];

                $version = VersionedUpdate::apply(Organization::query()->whereKey($organization->id), $data->expectedVersion, ['name' => $name, 'slug' => $slug]);

                $this->tenant->run($organization->id, fn () => $this->audit->record(
                    $actor,
                    'organization.updated',
                    new AuditTarget('organization', $organization->id, $name),
                    ['before' => $before, 'after' => ['name' => $name, 'slug' => $slug], 'version' => $version],
                ));

                return $version;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That name is taken.']);
        }
    }
}
