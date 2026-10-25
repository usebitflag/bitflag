<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Actions\UpdateOrganization;
use App\Modules\Organizations\Actions\UpdateOrganizationSecurity;
use App\Modules\Organizations\Data\CreateOrganizationData;
use App\Modules\Organizations\Data\UpdateOrganizationData;
use App\Modules\Organizations\Data\UpdateOrganizationSecurityData;
use App\Modules\Organizations\Domain\TeamSize;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Concurrency\VersionConflict;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function creator(): array
{
    $user = User::factory()->create();

    return ['user' => $user, 'actor' => Actor::user($user->id, $user->name, Channel::Dashboard)];
}

function create_org(Actor $actor, string $name = 'Acme', string $slug = 'acme', ?TeamSize $size = null)
{
    return app(CreateOrganization::class)->handle($actor, new CreateOrganizationData($name, $slug, $size));
}

function audit_actions(): array
{
    return Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->orderBy('id')->pluck('action')->all());
}

describe('creating an organization', function () {
    it('creates it with the person as its Org Owner and records it', function () {
        $c = creator();

        $result = create_org($c['actor'], 'Acme Corp', 'acme-corp', TeamSize::Small);

        $organization = Organization::query()->findOrFail($result->organizationId);
        $membership = Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $organization->id)->firstOrFail());

        expect($organization->slug)->toBe('acme-corp')
            ->and($organization->name)->toBe('Acme Corp')
            ->and($organization->team_size_hint)->toBe(TeamSize::Small)
            ->and($organization->created_by)->toBe($c['user']->id)
            ->and($organization->version)->toBe(1)
            ->and($organization->require_mfa)->toBeFalse()
            ->and($organization->allowed_email_domains)->toBe([])
            ->and($organization->session_idle_timeout_minutes)->toBeNull()
            ->and($membership->user_id)->toBe($c['user']->id)
            ->and($membership->role)->toBe(OrgRole::Owner)
            ->and(audit_actions())->toBe(['organization.created']);
    });

    it('trims and lowercases the URL name', function () {
        $result = create_org(creator()['actor'], '  Acme ', '  ACME-Corp ');

        expect($result->slug)->toBe('acme-corp')
            ->and(Organization::query()->findOrFail($result->organizationId)->name)->toBe('Acme');
    });

    it('refuses a name that cannot be used', function (string $slug, string $message) {
        $c = creator();
        create_org($c['actor'], 'First', 'taken-name');

        try {
            create_org($c['actor'], 'Second', $slug);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors()['slug'][0])->toBe($message);
        }

        expect(Organization::query()->count())->toBe(1);
    })->with([
        'too short' => ['ab', 'Use 3 to 40 characters.'],
        'bad characters' => ['1acme', 'Start with a letter, then use letters, numbers and dashes.'],
        'reserved' => ['signup', 'That name is reserved.'],
        'taken' => ['taken-name', 'That name is taken.'],
    ]);

    it('refuses an empty or very long organization name', function (string $name) {
        expect(fn () => create_org(creator()['actor'], $name))->toThrow(ValidationException::class);
    })->with(['', '   ', str_repeat('a', 121)]);

    it('limits how many organizations one person may create', function () {
        $c = creator();
        config()->set('bitflag.organizations.max_per_user', 2);
        create_org($c['actor'], 'One', 'org-one');
        create_org($c['actor'], 'Two', 'org-two');

        expect(fn () => create_org($c['actor'], 'Three', 'org-three'))->toThrow(ValidationException::class, 'up to 2');

        create_org(creator()['actor'], 'Four', 'org-four');
        expect(Organization::query()->count())->toBe(3);
    });

    it('is for people only', function () {
        expect(fn () => create_org(Actor::system('test')))->toThrow(AuthorizationDenied::class);
    });
});

describe('updating an organization', function () {
    it('renames it and changes its URL name, bumping the version and recording before and after', function () {
        $p = person(OrgRole::Owner, null);

        $version = app(UpdateOrganization::class)->handle($p['actor'], new UpdateOrganizationData($p['organization']->id, 'New Name', 'new-name', 1));

        $organization = $p['organization']->fresh();
        $event = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->firstOrFail());

        expect($version)->toBe(2)
            ->and($organization->name)->toBe('New Name')
            ->and($organization->slug)->toBe('new-name')
            ->and($event->action)->toBe('organization.updated')
            ->and($event->details['after'])->toBe(['name' => 'New Name', 'slug' => 'new-name']);
    });

    it('is for Org Owners only', function () {
        $member = person(OrgRole::Member, ProjectRole::Owner);

        expect(fn () => app(UpdateOrganization::class)->handle($member['actor'], new UpdateOrganizationData($member['organization']->id, 'X', 'xyz', 1)))
            ->toThrow(AuthorizationDenied::class);
    });

    it('is not available across organizations', function () {
        $mine = person(OrgRole::Owner, null);
        $theirs = person(OrgRole::Owner, null);

        expect(fn () => app(UpdateOrganization::class)->handle($mine['actor'], new UpdateOrganizationData($theirs['organization']->id, 'Hijacked', 'hijacked', 1)))
            ->toThrow(AuthorizationDenied::class);
        expect($theirs['organization']->fresh()->name)->toBe($theirs['organization']->name);
    });

    it('reports a version conflict and changes nothing', function () {
        $p = person(OrgRole::Owner, null);
        app(UpdateOrganization::class)->handle($p['actor'], new UpdateOrganizationData($p['organization']->id, 'First', 'first-one', 1));

        expect(fn () => app(UpdateOrganization::class)->handle($p['actor'], new UpdateOrganizationData($p['organization']->id, 'Second', 'second-one', 1)))
            ->toThrow(VersionConflict::class);
        expect($p['organization']->fresh()->name)->toBe('First');
    });

    it('keeps its own URL name and refuses another organization\'s', function () {
        $p = person(OrgRole::Owner, null);
        $other = Organization::factory()->create(['slug' => 'other-org']);

        app(UpdateOrganization::class)->handle($p['actor'], new UpdateOrganizationData($p['organization']->id, 'Renamed', $p['organization']->slug, 1));

        expect(fn () => app(UpdateOrganization::class)->handle($p['actor'], new UpdateOrganizationData($p['organization']->id, 'Renamed', $other->slug, 2)))
            ->toThrow(ValidationException::class, 'taken');
    });
});

describe('changing an organization\'s security settings', function () {
    function security(array $p, array $change = [], int $version = 1): int
    {
        return app(UpdateOrganizationSecurity::class)->handle($p['actor'], new UpdateOrganizationSecurityData(...[
            'organizationId' => $p['organization']->id,
            'requireMfa' => true,
            'idleTimeoutMinutes' => 720,
            'allowedEmailDomains' => ['Acme.dev', '@example.com'],
            'expectedVersion' => $version,
            ...$change,
        ]));
    }

    it('saves the settings and records before and after', function () {
        $p = person(OrgRole::Owner, null);

        expect(security($p))->toBe(2);

        $organization = $p['organization']->fresh();
        $event = Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->firstOrFail());

        expect($organization->require_mfa)->toBeTrue()
            ->and($organization->session_idle_timeout_minutes)->toBe(720)
            ->and($organization->allowed_email_domains)->toBe(['acme.dev', 'example.com'])
            ->and($event->action)->toBe('organization.security_changed')
            ->and($event->details['before']['require_mfa'])->toBeFalse()
            ->and($event->details['after']['require_mfa'])->toBeTrue();
    });

    it('accepts only the idle limits on offer, or none', function (?int $minutes, bool $valid) {
        $p = person(OrgRole::Owner, null);

        if ($valid) {
            expect(security($p, ['idleTimeoutMinutes' => $minutes]))->toBe(2);
        } else {
            expect(fn () => security($p, ['idleTimeoutMinutes' => $minutes]))->toThrow(ValidationException::class);
        }
    })->with([[null, true], [60, true], [720, true], [1440, true], [30, false], [10080, false], [0, false]]);

    it('rejects values that are not domains', function () {
        expect(fn () => security(person(OrgRole::Owner, null), ['allowedEmailDomains' => ['acme.dev', 'not a domain']]))->toThrow(ValidationException::class, 'not a domain');
    });

    it('allows at most twenty domains', function () {
        $domains = array_map(fn ($n) => "d{$n}.dev", range(1, 21));

        expect(fn () => security(person(OrgRole::Owner, null), ['allowedEmailDomains' => $domains]))->toThrow(ValidationException::class, 'up to 20');
        expect(security(person(OrgRole::Owner, null), ['allowedEmailDomains' => array_slice($domains, 0, 20)]))->toBe(2);
    });

    it('is for Org Owners only, and checks the version', function () {
        $member = person(OrgRole::Member, null);
        expect(fn () => security($member))->toThrow(AuthorizationDenied::class);

        $owner = person(OrgRole::Owner, null);
        security($owner);
        expect(fn () => security($owner))->toThrow(VersionConflict::class);
    });

    it('signs nobody out when two-factor is turned on', function () {
        $p = person(OrgRole::Owner, null);
        $other = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $p['organization']->id, 'user_id' => $other->id, 'role' => OrgRole::Member]);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        security($p);

        expect(DB::table('sessions')->where('id', 'abc')->exists())->toBeTrue();
    });
});
