<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Organizations\Queries\OnboardingState;
use App\Modules\Projects\Models\Project;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ip|127.0.0.1');
    $this->user = User::factory()->create(['email' => 'nia@startup.dev', 'password' => 'correct horse battery']);
});

function org_form(array $change = []): array
{
    return ['name' => 'Startup Inc', 'slug' => 'startup-inc', 'team_size' => 'small', ...$change];
}

function project_form(array $change = []): array
{
    return ['name' => 'Storefront', 'key' => 'storefront', 'environments' => [
        ['name' => 'Development', 'key' => 'development'],
        ['name' => 'Staging', 'key' => 'staging'],
        ['name' => 'Production', 'key' => 'production'],
    ], ...$change];
}

/** Walks a new person to the given step. */
function walk(string $to): void
{
    $steps = ['organization', 'project', 'team', 'ready'];
    $user = test()->user;

    test()->actingAs($user)->post('/onboarding/organization', org_form());
    if ($to === 'project') {
        return;
    }
    test()->actingAs($user)->post('/onboarding/startup-inc/project', project_form());
    if ($to === 'team') {
        return;
    }
    test()->actingAs($user)->post('/onboarding/startup-inc/team/skip');
}

function onboarding_org(): Organization
{
    return Tenancy::crossTenant('inspect', fn () => Organization::query()->where('slug', 'startup-inc')->firstOrFail());
}

describe('where a person is sent', function () {
    it('sends a person with no organization to the first step from the site root and from /onboarding', function () {
        $this->actingAs($this->user)->get('/')->assertRedirect('/onboarding/organization');
        $this->actingAs($this->user)->get('/onboarding')->assertRedirect('/onboarding/organization');
    });

    it('sends them to the step the facts say, whichever step they ask for', function () {
        walk('project');

        foreach (['/onboarding/startup-inc/team', '/onboarding/startup-inc/ready', '/onboarding/organization'] as $uri) {
            $this->actingAs($this->user)->get($uri)->assertRedirect('/onboarding/startup-inc/project');
        }
    });

    it('resumes where the person left off, however many times they come back', function () {
        walk('team');

        $this->actingAs($this->user)->get('/')->assertRedirect('/onboarding/startup-inc/team');
        $this->actingAs($this->user)->get('/onboarding')->assertRedirect('/onboarding/startup-inc/team');
    });

    it('refuses another organization\'s wizard address', function () {
        walk('project');
        $other = person(OrgRole::Owner, ProjectRole::Owner);

        $this->actingAs($this->user)->get("/onboarding/{$other['organization']->slug}/project")->assertRedirect('/onboarding/startup-inc/project');
    });

    it('is for signed-in people only', function () {
        $this->get('/onboarding')->assertRedirect('/signin');
        $this->post('/onboarding/organization', org_form())->assertRedirect('/signin');
    });

    it('never takes someone who joined an organization through an invitation', function () {
        $p = person(OrgRole::Member, ProjectRole::Developer);

        $this->actingAs($p['user'])->get('/onboarding')->assertRedirect('/');
        $this->actingAs($p['user'])->get('/onboarding/organization')->assertRedirect('/');
        expect(app(OnboardingState::class)->for($p['user']->id)->step)->toBeNull();
    });

    it('leaves existing organizations alone', function () {
        $owner = person(OrgRole::Owner, ProjectRole::Owner);

        expect(app(OnboardingState::class)->for($owner['user']->id)->step)->toBeNull();
    });
});

describe('the organization step', function () {
    it('shows the form with the team sizes', function () {
        $this->actingAs($this->user)->get('/onboarding/organization')->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Organization')->has('teamSizes', 4)->where('teamSizes.0.label', 'Just me'));
    });

    it('creates the organization with the person as Org Owner and moves on to the project', function () {
        $this->actingAs($this->user)->post('/onboarding/organization', org_form())->assertRedirect('/onboarding/startup-inc/project');

        $organization = onboarding_org();

        expect($organization->created_by)->toBe($this->user->id)
            ->and($organization->team_size_hint?->value)->toBe('small')
            ->and(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $organization->id)->firstOrFail()->role))->toBe(OrgRole::Owner)
            ->and($this->user->fresh()->last_organization_id)->toBe($organization->id);
    });

    it('takes the team size as optional', function () {
        $this->actingAs($this->user)->post('/onboarding/organization', org_form(['team_size' => null]))->assertRedirect('/onboarding/startup-inc/project');

        expect(onboarding_org()->team_size_hint)->toBeNull();
    });

    it('refuses what cannot be used, creating nothing', function (array $change, string $field) {
        $this->actingAs($this->user)->from('/onboarding/organization')->post('/onboarding/organization', org_form($change))->assertSessionHasErrors($field);

        expect(Organization::query()->count())->toBe(0);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'reserved' => [['slug' => 'signup'], 'slug'],
        'short' => [['slug' => 'ab'], 'slug'],
        'bad size' => [['team_size' => 'huge'], 'team_size'],
    ]);

    it('refuses a name already taken', function () {
        Organization::factory()->create(['slug' => 'startup-inc']);

        $this->actingAs($this->user)->from('/onboarding/organization')->post('/onboarding/organization', org_form())->assertSessionHasErrors(['slug' => 'That name is taken.']);
    });

    it('answers whether a URL name is free, for the live check', function () {
        Organization::factory()->create(['slug' => 'taken-name']);

        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=fresh-name')->assertOk()->assertExactJson(['available' => true, 'reason' => null]);
        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=taken-name')->assertExactJson(['available' => false, 'reason' => 'That name is taken.']);
        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=signup')->assertExactJson(['available' => false, 'reason' => 'That name is reserved.']);
        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=')->assertExactJson(['available' => false, 'reason' => null]);
        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=Fresh-Name')->assertJson(['available' => true]);
    });

    it('limits how fast the live check can be used', function () {
        RateLimiter::clear('onboarding-slug|'.$this->user->id);

        foreach (range(1, 30) as $n) {
            $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=name-'.$n)->assertOk();
        }

        $this->actingAs($this->user)->getJson('/onboarding/slug-availability?slug=one-more')->assertStatus(429);
    });

    it('limits how many organizations one person can make through it', function () {
        config()->set('bitflag.organizations.max_per_user', 1);
        walk('project');
        // A second organization by the same person is refused even though the wizard would not offer it.
        $this->actingAs($this->user)->from('/x')->post('/onboarding/organization', org_form(['slug' => 'second-org']))->assertRedirect();

        expect(Organization::query()->where('created_by', $this->user->id)->count())->toBe(1);
    });
});

describe('the project step', function () {
    beforeEach(fn () => walk('project'));

    it('shows the default environments', function () {
        $this->actingAs($this->user)->get('/onboarding/startup-inc/project')->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Project')->where('organization.name', 'Startup Inc')
            ->where('environments', [['name' => 'Development', 'key' => 'development'], ['name' => 'Staging', 'key' => 'staging'], ['name' => 'Production', 'key' => 'production']]));
    });

    it('creates the project and moves on to the team', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/project', project_form())->assertRedirect('/onboarding/startup-inc/team');

        $project = Tenancy::crossTenant('inspect', fn () => Project::query()->where('key', 'storefront')->firstOrFail());

        expect($project->organization_id)->toBe(onboarding_org()->id)
            ->and(Tenancy::crossTenant('inspect', fn () => ProjectMembership::query()->where('project_id', $project->id)->firstOrFail()->role))->toBe(ProjectRole::Owner);
    });

    it('uses an edited list of environments', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/project', project_form(['environments' => [['name' => 'QA', 'key' => 'qa'], ['name' => 'Prod', 'key' => 'prod']]]));

        $keys = Tenancy::crossTenant('inspect', fn () => Environment::query()->orderBy('position')->pluck('key')->all());

        expect($keys)->toBe(['qa', 'prod']);
    });

    it('refuses what cannot be used', function (array $change, string $field) {
        $this->actingAs($this->user)->from('/onboarding/startup-inc/project')->post('/onboarding/startup-inc/project', project_form($change))->assertSessionHasErrors($field);

        expect(Tenancy::crossTenant('inspect', fn () => Project::query()->count()))->toBe(0);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'reserved key' => [['key' => 'settings'], 'key'],
        'bad key' => [['key' => '1abc'], 'key'],
        'no environments' => [['environments' => []], 'environments'],
        'repeated key' => [['environments' => [['name' => 'A', 'key' => 'dup'], ['name' => 'B', 'key' => 'dup']]], 'environments.1.key'],
    ]);
});

describe('the team step', function () {
    beforeEach(fn () => walk('team'));

    it('shows the project and whether invitations will wait', function () {
        $this->actingAs($this->user)->get('/onboarding/startup-inc/team')->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Team')->where('projectName', 'Storefront')->where('emailVerified', true)->has('roles', 4));

        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->user)->get('/onboarding/startup-inc/team')->assertInertia(fn (Assert $page) => $page->where('emailVerified', false));
    });

    it('invites the people to the project with the chosen role and moves on', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/team', ['emails' => "a@startup.dev, b@startup.dev\nc@startup.dev", 'role' => 'publisher'])
            ->assertRedirect('/onboarding/startup-inc/ready');

        $invitations = Tenancy::crossTenant('inspect', fn () => Invitation::query()->with('grants')->get());

        expect($invitations)->toHaveCount(3)
            ->and($invitations->first()->grants->first()->role)->toBe(ProjectRole::Publisher)
            ->and($invitations->pluck('status')->map->value->unique()->all())->toBe(['pending'])
            ->and(OutboxMessageRecord::query()->where('payload->template', 'invitation')->count())->toBe(3);
    });

    it('holds invitations until the email is confirmed, saying nothing is sent yet', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->user)->post('/onboarding/startup-inc/team', ['emails' => 'a@startup.dev', 'role' => 'developer']);

        expect(Tenancy::crossTenant('inspect', fn () => Invitation::query()->firstOrFail()->status->value))->toBe('queued')
            ->and(OutboxMessageRecord::query()->where('payload->template', 'invitation')->count())->toBe(0);
    });

    it('can be skipped', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/team/skip')->assertRedirect('/onboarding/startup-inc/ready');

        expect(Tenancy::crossTenant('inspect', fn () => Invitation::query()->count()))->toBe(0)
            ->and(onboarding_org()->onboarding)->toHaveKey('team_step_done_at');
    });

    it('stays on the step when no address can be invited', function () {
        $this->actingAs($this->user)->from('/onboarding/startup-inc/team')->post('/onboarding/startup-inc/team', ['emails' => 'not-an-email', 'role' => 'developer'])->assertSessionHasErrors('emails');

        $this->actingAs($this->user)->get('/')->assertRedirect('/onboarding/startup-inc/team');
    });

    it('goes on when some addresses are fine and some are not', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/team', ['emails' => 'good@startup.dev, bad', 'role' => 'developer'])->assertRedirect('/onboarding/startup-inc/ready');

        expect(Tenancy::crossTenant('inspect', fn () => Invitation::query()->count()))->toBe(1);
    });

    it('refuses a role that is not one of the four, and an empty list', function () {
        $this->actingAs($this->user)->from('/x')->post('/onboarding/startup-inc/team', ['emails' => 'a@startup.dev', 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->actingAs($this->user)->from('/x')->post('/onboarding/startup-inc/team', ['emails' => '', 'role' => 'developer'])->assertSessionHasErrors('emails');
    });
});

describe('the ready step', function () {
    beforeEach(fn () => walk('ready'));

    it('shows the four steps', function () {
        $this->actingAs($this->user)->get('/onboarding/startup-inc/ready')->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Ready')->has('steps', 4)->where('steps.0', 'Create a flag'));
    });

    it('is shown once, and then the overview opens', function () {
        $this->actingAs($this->user)->get('/')->assertRedirect('/onboarding/startup-inc/ready');

        $this->actingAs($this->user)->post('/onboarding/startup-inc/ready')->assertRedirect(route('organization.home', ['organization' => 'startup-inc']));

        $this->actingAs($this->user)->get('/')->assertRedirectContains('/startup-inc/storefront/');
        $this->actingAs($this->user)->get('/onboarding/startup-inc/ready')->assertRedirect('/');
        expect(onboarding_org()->onboarding)->toHaveKey('ready_seen_at');
    });

    it('lands on the overview of the project just made', function () {
        $this->actingAs($this->user)->post('/onboarding/startup-inc/ready');

        $this->actingAs($this->user)->get('/startup-inc')->assertRedirectContains('/startup-inc/storefront/');
    });
});

it('only the person who made the organization can record its progress', function () {
    walk('team');
    $member = User::factory()->create();
    OrganizationMembership::factory()->create(['organization_id' => onboarding_org()->id, 'user_id' => $member->id, 'role' => OrgRole::Member]);

    expect(app(OnboardingState::class)->for($member->id)->step)->toBeNull();
});
