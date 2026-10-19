<?php

declare(strict_types=1);

use App\Modules\Environments\Models\Environment;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Projects\Models\Project;
use App\Modules\Support\Models\StaffUser;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

function seedDemo(): void
{
    test()->seed(DemoSeeder::class);
}

it('builds the demo world', function () {
    seedDemo();

    Tenancy::crossTenant('demo seed check', function () {
        expect(Organization::query()->orderBy('slug')->pluck('slug')->all())->toBe(['acme', 'globex'])
            ->and(Project::query()->orderBy('key')->pluck('key')->all())->toBe(['billing', 'checkout-api', 'storefront'])
            ->and(Environment::query()->count())->toBe(9)
            ->and(User::query()->count())->toBe(6);

        $storefront = Project::query()->where('key', 'storefront')->firstOrFail();
        expect($storefront->description)->toBe('Web checkout, catalog and account pages.')
            ->and(Environment::query()->where('project_id', $storefront->id)->orderBy('position')->pluck('key')->all())
            ->toBe(['development', 'staging', 'production']);
    });
});

it('gives each person the access the demo describes', function () {
    seedDemo();

    $roles = Tenancy::crossTenant('demo seed check', fn () => ProjectMembership::query()
        ->join('users', 'users.id', '=', 'project_memberships.user_id')
        ->join('projects', 'projects.id', '=', 'project_memberships.project_id')
        ->get(['users.email as email', 'projects.key as project', 'project_memberships.role as role'])
        ->mapWithKeys(fn ($row) => ["{$row->email}:{$row->project}" => $row->role instanceof ProjectRole ? $row->role->value : $row->role])
        ->all());

    // The join has no ORDER BY, so compare as a map and ignore the order MySQL returns the rows in.
    expect($roles)->toEqual([
        'maya@acme.dev:storefront' => 'owner',
        'maya@acme.dev:checkout-api' => 'owner',
        'jon@acme.dev:storefront' => 'publisher',
        'jon@acme.dev:checkout-api' => 'developer',
        'priya@acme.dev:storefront' => 'developer',
        'ana@acme.dev:storefront' => 'viewer',
        'gina@globex.dev:billing' => 'owner',
    ]);

    $leo = User::query()->where('email', 'leo@acme.dev')->firstOrFail();
    expect(Tenancy::crossTenant('demo seed check', fn () => ProjectMembership::query()->where('user_id', $leo->id)->count()))->toBe(0)
        ->and(Tenancy::crossTenant('demo seed check', fn () => OrganizationMembership::query()->where('user_id', $leo->id)->count()))->toBe(1);
});

it('remembers Maya on Storefront in production and nobody else', function () {
    seedDemo();

    $maya = User::query()->where('email', 'maya@acme.dev')->firstOrFail();
    $production = Tenancy::crossTenant('demo seed check', fn () => Environment::query()->where('project_id', $maya->last_project_id)->where('key', 'production')->firstOrFail());

    expect($maya->last_environment_id)->toBe($production->id)
        ->and(User::query()->whereNotNull('last_project_id')->count())->toBe(1);
});

it('lets the demo people and the staff user sign in with the demo password', function () {
    config(['bitflag.demo_password' => 'demo-secret-1']);
    seedDemo();

    expect(Hash::check('demo-secret-1', User::query()->where('email', 'maya@acme.dev')->firstOrFail()->password))->toBeTrue()
        ->and(Hash::check('demo-secret-1', StaffUser::query()->where('email', 'support@bitflag.test')->firstOrFail()->password))->toBeTrue();
});

it('is safe to run again and keeps the same identifiers', function () {
    seedDemo();
    $first = Tenancy::crossTenant('demo seed check', fn () => [
        Project::query()->orderBy('id')->pluck('id')->all(),
        Environment::query()->orderBy('id')->pluck('id')->all(),
        User::query()->orderBy('id')->pluck('id')->all(),
    ]);

    seedDemo();
    $second = Tenancy::crossTenant('demo seed check', fn () => [
        Project::query()->orderBy('id')->pluck('id')->all(),
        Environment::query()->orderBy('id')->pluck('id')->all(),
        User::query()->orderBy('id')->pluck('id')->all(),
    ]);

    expect($second)->toBe($first)
        ->and(Tenancy::crossTenant('demo seed check', fn () => OrganizationMembership::query()->count()))->toBe(6)
        ->and(Tenancy::crossTenant('demo seed check', fn () => ProjectMembership::query()->count()))->toBe(7);
});

it('uses identifiers that public IDs accept', function () {
    seedDemo();

    $id = Tenancy::crossTenant('demo seed check', fn () => Project::query()->where('key', 'storefront')->firstOrFail()->id);

    expect(PublicId::encode(IdPrefix::Project, $id))->toStartWith('prj_');
});

it('refuses to run in production', function () {
    $this->app['env'] = 'production';

    // Run the seeder directly: db:seed itself asks for confirmation in production and would not reach it.
    expect(fn () => app(DemoSeeder::class)->run())->toThrow(LogicException::class, 'never runs in production');
});
