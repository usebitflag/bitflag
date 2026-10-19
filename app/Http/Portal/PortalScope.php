<?php

declare(strict_types=1);

namespace App\Http\Portal;

use App\Modules\Environments\Data\EnvironmentSummary;
use App\Modules\Organizations\Data\OrganizationSummary;
use App\Modules\Projects\Data\ProjectSummary;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;

/**
 * The organization, project and environment a portal request acts in, and the person's roles there. Built by
 * ResolvePortalScope once the person is known to belong.
 */
final readonly class PortalScope
{
    public function __construct(
        public OrganizationSummary $organization,
        public OrgRole $orgRole,
        public ?ProjectSummary $project = null,
        public ?ProjectRole $projectRole = null,
        public ?EnvironmentSummary $environment = null,
    ) {}
}
