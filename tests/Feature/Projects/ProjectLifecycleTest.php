<?php

declare(strict_types=1);

use App\Modules\Credentials\Actions\RevokeCredential;
use App\Modules\Credentials\Actions\RotateCredential;
use App\Modules\Credentials\Data\RevokeCredentialData;
use App\Modules\Credentials\Data\RotateCredentialData;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Environments\Actions\CreateEnvironment;
use App\Modules\Environments\Data\CreateEnvironmentData;
use App\Modules\Organizations\Actions\CancelOrganizationDeletion;
use App\Modules\Organizations\Actions\PurgeOrganization;
use App\Modules\Organizations\Actions\ScheduleOrganizationDeletion;
use App\Modules\Organizations\Data\OrganizationDeletionData;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Projects\Actions\ArchiveProject;
use App\Modules\Projects\Actions\PurgeProject;
use App\Modules\Projects\Actions\RestoreProject;
use App\Modules\Projects\Actions\ScheduleProjectDeletion;
use App\Modules\Projects\Actions\UnarchiveProject;
use App\Modules\Projects\Actions\UpdateProjectSettings;
use App\Modules\Projects\Data\ProjectLifecycleData;
use App\Modules\Projects\Domain\Exceptions\ProjectNotWritable;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Queries\DueDeletions;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Lifecycle\ProjectPurger;
use App\Shared\Lifecycle\ProjectPurgers;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Plans\BillingStatus;
use App\Shared\Plans\EnforcePlanLimit;
use App\Shared\Plans\LimitKey;
use App\Shared\Plans\NoBilling;
use App\Shared\Plans\PlanLimitReached;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Redis::flushdb();
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
});

function life(array $p, ?string $typed = null): ProjectLifecycleData
{
    return new ProjectLifecycleData($p['organization']->id, $p['project']->id, $typed);
}

function project_row(array $p): Project
{
    return Tenancy::crossTenant('inspect', fn () => Project::query()->findOrFail($p['project']->id));
}

function audit_count(string $action): int
{
    return Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', $action)->count());
}

describe('archiving', function () {
    it('makes the project read-only, keeps its keys serving and lets it be unarchived', function () {
        $key = make_credential($this->p);

        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));

        expect(project_row($this->p)->status->value)->toBe('archived')
            ->and(project_row($this->p)->archived_by)->toBe($this->p['user']->id)
            ->and(app(CredentialResolver::class)->resolve($key['secret'])->status)->toBe(CredentialStatus::Active)
            ->and(audit_count('project.archived'))->toBe(1);

        app(UnarchiveProject::class)->handle($this->p['actor'], life($this->p));

        expect(project_row($this->p)->status->value)->toBe('active')->and(project_row($this->p)->archived_at)->toBeNull();
    });

    it('refuses edits while archived, but rotation and revocation still work', function () {
        $key = make_credential($this->p);
        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));

        expect(fn () => app(UpdateProjectSettings::class)->handle($this->p['actor'], settings_data($this->p)))->toThrow(ProjectNotWritable::class)
            ->and(fn () => app(CreateEnvironment::class)->handle($this->p['actor'], new CreateEnvironmentData($this->p['organization']->id, $this->p['project']->id, 'QA', 'qa', 'slate', null, false)))->toThrow(ProjectNotWritable::class);

        $rotated = app(RotateCredential::class)->handle($this->p['actor'], new RotateCredentialData($this->p['organization']->id, $this->p['project']->id, $this->p['environment']->id, $key['credential']->id, 3600));
        app(RevokeCredential::class)->handle($this->p['actor'], new RevokeCredentialData($this->p['organization']->id, $rotated->replacementId));

        expect(fn () => app(CredentialResolver::class)->resolve($rotated->secret->reveal()))->toThrow(RevokedCredential::class);
    });

    it('reports the code of the state that blocks it', function () {
        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));
        try {
            app(UpdateProjectSettings::class)->handle($this->p['actor'], settings_data($this->p));
        } catch (ProjectNotWritable $e) {
            expect($e->errorCode())->toBe('PROJECT_ARCHIVED')->and($e->httpStatus())->toBe(403);
        }

        app(UnarchiveProject::class)->handle($this->p['actor'], life($this->p));
        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        try {
            app(UpdateProjectSettings::class)->handle($this->p['actor'], settings_data($this->p));
        } catch (ProjectNotWritable $e) {
            expect($e->errorCode())->toBe('PROJECT_DELETION_SCHEDULED');
        }
    });

    it('is for project Owners and only for an active project', function () {
        $viewer = person(OrgRole::Member, ProjectRole::Viewer);
        expect(fn () => app(ArchiveProject::class)->handle($viewer['actor'], life($viewer)))->toThrow(AuthorizationDenied::class);

        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));
        expect(fn () => app(ArchiveProject::class)->handle($this->p['actor'], life($this->p)))->toThrow(ValidationException::class)
            ->and(fn () => app(UnarchiveProject::class)->handle($viewer['actor'], life($viewer)))->toThrow(AuthorizationDenied::class);
    });

    it('does not check the project limit on unarchive, because an archived project already counts', function () {
        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));
        app()->bind(EnforcePlanLimit::class, fn () => new class implements EnforcePlanLimit
        {
            public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
            {
                throw new PlanLimitReached($limit);
            }
        });

        app(UnarchiveProject::class)->handle($this->p['actor'], life($this->p));

        expect(project_row($this->p)->status->value)->toBe('active');
    });
});

describe('scheduling a deletion', function () {
    it('needs the project key typed, and goes read-only for the grace period while keys keep working', function () {
        $key = make_credential($this->p);

        expect(fn () => app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, 'nope')))->toThrow(ValidationException::class);

        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        $row = project_row($this->p);

        expect($row->status->value)->toBe('deletion_scheduled')
            ->and($row->status_before_deletion?->value)->toBe('active')
            ->and($row->deletion_scheduled_at?->diffInDays($row->deletion_due_at))->toBe(7.0)
            ->and(app(CredentialResolver::class)->resolve($key['secret'])->status)->toBe(CredentialStatus::Active)
            ->and(audit_count('project.deletion_scheduled'))->toBe(1)
            ->and(Tenancy::crossTenant('inspect', fn () => OutboxMessageRecord::query()->where('type', 'internal.email.requested')->get()->contains(fn ($m) => ($m->payload['template'] ?? null) === 'project_deletion_scheduled')))->toBeTrue();
    });

    it('remembers an archived project as archived', function () {
        app(ArchiveProject::class)->handle($this->p['actor'], life($this->p));
        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        app(RestoreProject::class)->handle($this->p['actor'], life($this->p));

        expect(project_row($this->p)->status->value)->toBe('archived');
    });
});

describe('restoring', function () {
    it('returns the project until the due time, then refuses', function () {
        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        $this->travel(6)->days();
        app(RestoreProject::class)->handle($this->p['actor'], life($this->p));

        expect(project_row($this->p)->status->value)->toBe('active')
            ->and(project_row($this->p)->deletion_due_at)->toBeNull()
            ->and(audit_count('project.deletion_cancelled'))->toBe(1);

        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        $this->travel(8)->days();

        expect(fn () => app(RestoreProject::class)->handle($this->p['actor'], life($this->p)))->toThrow(ValidationException::class);
    });

    it('is blocked by the project limit, which a project waiting to be deleted does not count against', function () {
        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $this->p['project']->key));
        app()->bind(EnforcePlanLimit::class, fn () => new class implements EnforcePlanLimit
        {
            public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
            {
                throw new PlanLimitReached($limit);
            }
        });

        expect(fn () => app(RestoreProject::class)->handle($this->p['actor'], life($this->p)))->toThrow(PlanLimitReached::class)
            ->and(project_row($this->p)->status->value)->toBe('deletion_scheduled');
    });
});

describe('purging', function () {
    it('revokes every key, runs the purgers, frees the project key and keeps a tombstone', function () {
        $server = make_credential($this->p);
        $personal = make_credential($this->p, [], CredentialType::Personal);
        $purged = new ArrayObject;
        app(ProjectPurgers::class)->register(RecordingPurger::class);
        app()->instance(RecordingPurger::class, new RecordingPurger($purged));
        $key = $this->p['project']->key;

        app(ScheduleProjectDeletion::class)->handle($this->p['actor'], life($this->p, $key));

        expect(app(PurgeProject::class)->handle($this->p['organization']->id, $this->p['project']->id))->toBeFalse();

        $this->travel(8)->days();
        expect(app(DueDeletions::class)->projects())->toBe([['organizationId' => $this->p['organization']->id, 'projectId' => $this->p['project']->id]]);
        expect(app(PurgeProject::class)->handle($this->p['organization']->id, $this->p['project']->id))->toBeTrue();

        $row = project_row($this->p);

        expect($row->status->value)->toBe('deleted')
            ->and($row->key)->toBe('deleted-'.strtolower($row->id))
            ->and($row->deleted_at)->not->toBeNull()
            ->and(fn () => app(CredentialResolver::class)->resolve($server['secret']))->toThrow(RevokedCredential::class)
            ->and(fn () => app(CredentialResolver::class)->resolve($personal['secret']))->toThrow(RevokedCredential::class)
            ->and(credential_now($server['credential'])->revoke_reason?->value)->toBe('project_deleted')
            ->and($purged->getArrayCopy())->toBe([$this->p['project']->id])
            ->and(audit_count('project.deleted'))->toBe(1)
            ->and(app(PurgeProject::class)->handle($this->p['organization']->id, $this->p['project']->id))->toBeFalse();

        // The key can be used again.
        $again = Project::factory()->create(['organization_id' => $this->p['organization']->id, 'key' => $key]);
        expect($again->key)->toBe($key);
    });
});

describe('organization deletion', function () {
    function org_owner_world(): array
    {
        $p = person(OrgRole::Owner, ProjectRole::Owner);
        $second = Project::factory()->create(['organization_id' => $p['organization']->id, 'key' => 'second']);

        return [...$p, 'second' => $second];
    }

    function org_data(array $p, ?string $slug = null): OrganizationDeletionData
    {
        return new OrganizationDeletionData($p['organization']->id, $slug);
    }

    it('needs the URL name, an Org Owner and no paid subscription, then schedules every project', function () {
        $p = org_owner_world();
        $member = person(OrgRole::Member, ProjectRole::Owner);

        expect(fn () => app(ScheduleOrganizationDeletion::class)->handle($member['actor'], org_data($member, $member['organization']->slug)))->toThrow(AuthorizationDenied::class)
            ->and(fn () => app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, 'nope')))->toThrow(ValidationException::class);

        app()->bind(BillingStatus::class, fn () => new class implements BillingStatus
        {
            public function hasActivePaidSubscription(string $organizationId): bool
            {
                return true;
            }
        });
        expect(fn () => app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, $p['organization']->slug)))->toThrow(ValidationException::class);
        app()->bind(BillingStatus::class, NoBilling::class);

        app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, $p['organization']->slug));

        $statuses = Tenancy::crossTenant('inspect', fn () => Project::query()->where('organization_id', $p['organization']->id)->pluck('deletion_scheduled_with_org', 'key')->all());
        expect(Organization::query()->find($p['organization']->id)->status->value)->toBe('deletion_scheduled')
            ->and($statuses)->toHaveCount(2)->and(array_values($statuses))->toBe([true, true])
            ->and(audit_count('organization.deletion_scheduled'))->toBe(1);
    });

    it('keeps a project that was scheduled on its own on its earlier date, and restores only what the organization scheduled', function () {
        $p = org_owner_world();
        $own = life($p, $p['project']->key);
        app(ScheduleProjectDeletion::class)->handle($p['actor'], $own);
        $earlier = project_row($p)->deletion_due_at;
        $this->travel(2)->days();

        app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, $p['organization']->slug));

        expect(project_row($p)->deletion_due_at->equalTo($earlier))->toBeTrue()
            ->and(project_row($p)->deletion_scheduled_with_org)->toBeFalse();

        app(CancelOrganizationDeletion::class)->handle($p['actor'], org_data($p));

        $rows = Tenancy::crossTenant('inspect', fn () => Project::query()->where('organization_id', $p['organization']->id)->pluck('status', 'key')->map(fn ($s) => $s->value)->all());
        expect($rows[$p['project']->key])->toBe('deletion_scheduled')->and($rows['second'])->toBe('active')
            ->and(Organization::query()->find($p['organization']->id)->status->value)->toBe('active');
    });

    it('leaves a project scheduled when restoring it would go over the project limit', function () {
        $p = org_owner_world();
        app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, $p['organization']->slug));
        app()->bind(EnforcePlanLimit::class, fn () => new class implements EnforcePlanLimit
        {
            public function handle(string $organizationId, LimitKey $limit, ?string $projectId = null, int $adding = 1): void
            {
                throw new PlanLimitReached($limit);
            }
        });

        $blocked = app(CancelOrganizationDeletion::class)->handle($p['actor'], org_data($p));

        expect($blocked)->toHaveCount(2)->and(project_row($p)->status->value)->toBe('deletion_scheduled')->and(project_row($p)->deletion_scheduled_with_org)->toBeFalse();
    });

    it('purges the projects and the organization after the grace period and removes memberships', function () {
        $p = org_owner_world();
        $key = make_credential($p);
        app(ScheduleOrganizationDeletion::class)->handle($p['actor'], org_data($p, $p['organization']->slug));

        expect(app(PurgeOrganization::class)->handle($p['organization']->id))->toBeFalse()
            ->and(app(CredentialResolver::class)->resolve($key['secret'])->status)->toBe(CredentialStatus::Active);

        $this->travel(8)->days();
        expect(app(PurgeOrganization::class)->handle($p['organization']->id))->toBeTrue();

        expect(Organization::query()->find($p['organization']->id)->status->value)->toBe('deleted')
            ->and(project_row($p)->status->value)->toBe('deleted')
            ->and(fn () => app(CredentialResolver::class)->resolve($key['secret']))->toThrow(RevokedCredential::class)
            ->and(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $p['organization']->id)->count()))->toBe(0)
            ->and(audit_count('organization.deleted'))->toBe(1);
    });
});

final class RecordingPurger implements ProjectPurger
{
    public function __construct(private readonly ArrayObject $purged) {}

    public function name(): string
    {
        return 'recording';
    }

    public function purge(string $organizationId, string $projectId): void
    {
        $this->purged[] = $projectId;
    }
}
