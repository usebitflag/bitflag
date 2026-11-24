<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\RevokedCredential;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Identity\Actions\VerifySecondFactor;
use App\Modules\Identity\Data\VerifySecondFactorData;
use App\Modules\Identity\Models\MfaFactor;
use App\Modules\Identity\Models\MfaRecoveryCode;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Organizations\Models\ProjectMembership;
use App\Modules\Organizations\Queries\InvitationDetails;
use App\Modules\Projects\Models\Project;
use App\Modules\Support\Models\StaffUser;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Ids\IdPrefix;
use App\Shared\Ids\PublicId;
use App\Shared\Tenancy\Tenancy;
use Database\Seeders\Demo\DemoIds;
use Database\Seeders\Demo\DemoSecrets;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

function seedDemo(): void
{
    test()->seed(DemoSeeder::class);
}

it('builds the demo world', function () {
    seedDemo();

    Tenancy::crossTenant('demo seed check', function () {
        expect(Organization::query()->orderBy('slug')->pluck('slug')->all())->toBe(['acme', 'globex', 'maya-studio'])
            ->and(Project::query()->orderBy('key')->pluck('key')->all())->toBe(['billing', 'checkout-api', 'portfolio', 'storefront'])
            ->and(Environment::query()->count())->toBe(12)
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
        'maya@acme.dev:portfolio' => 'owner',
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

it('gives Maya a second organization of her own and remembers her choice', function () {
    seedDemo();

    $maya = User::query()->where('email', 'maya@acme.dev')->firstOrFail();
    $organizations = Tenancy::crossTenant('demo seed check', fn () => OrganizationMembership::query()->where('user_id', $maya->id)->with([])->get());

    expect($organizations)->toHaveCount(2)
        ->and($maya->remember_org_choice)->toBeTrue()
        ->and(Tenancy::crossTenant('demo seed check', fn () => Organization::query()->whereKey($organizations->pluck('organization_id'))->orderBy('slug')->pluck('slug')->all()))->toBe(['acme', 'maya-studio']);
});

it('gives Maya and Jon two-factor with a known secret and ten recovery codes, and nobody else', function () {
    seedDemo();

    foreach (['maya@acme.dev', 'jon@acme.dev'] as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
        $factor = MfaFactor::query()->where('user_id', $user->id)->firstOrFail();

        expect($factor->confirmed_at)->not->toBeNull()
            ->and($factor->secret)->toBe(DemoSecrets::TOTP_SECRET)
            ->and(MfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(10);
    }

    expect(MfaFactor::query()->count())->toBe(2);
});

it('hashes the recovery codes so a demo code works once at sign-in', function () {
    seedDemo();
    $maya = User::query()->where('email', 'maya@acme.dev')->firstOrFail();

    $result = app(VerifySecondFactor::class)->handle(new VerifySecondFactorData($maya->id, DemoSecrets::RECOVERY_CODES[0]));
    $again = app(VerifySecondFactor::class)->handle(new VerifySecondFactorData($maya->id, DemoSecrets::RECOVERY_CODES[0]));

    expect($result->accepted())->toBeTrue()->and($again->accepted())->toBeFalse();
});

it('leaves one invitation waiting in Acme with a known link', function () {
    seedDemo();

    $summary = app(InvitationDetails::class)->forToken(DemoSecrets::INVITATION_TOKEN);

    expect($summary->state)->toBe('open')
        ->and($summary->email)->toBe('dev@northwind.studio')
        ->and($summary->organizationName)->toBe('Acme')
        ->and($summary->inviterName)->toBe('Maya Santos')
        ->and($summary->orgRole?->value)->toBe('member')
        ->and($summary->projects)->toBe([['name' => 'Storefront', 'role' => 'developer']])
        ->and(strlen(DemoSecrets::INVITATION_TOKEN))->toBe(43);
});

it('protects production in every demo project and leaves the other environments open', function () {
    seedDemo();

    $protected = Tenancy::crossTenant('demo seed check', fn () => EnvironmentPolicy::query()->where('is_protected', true)->count());
    $environments = Tenancy::crossTenant('demo seed check', fn () => Environment::query()->with([])->get());

    expect($protected)->toBe(4)
        ->and(Tenancy::crossTenant('demo seed check', fn () => EnvironmentPolicy::query()->count()))->toBe(12)
        ->and($environments->every(fn ($environment) => $environment->current_policy_id !== null))->toBeTrue();
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
        ->and(Tenancy::crossTenant('demo seed check', fn () => OrganizationMembership::query()->count()))->toBe(7)
        ->and(Tenancy::crossTenant('demo seed check', fn () => ProjectMembership::query()->count()))->toBe(8);
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

it('seeds keys and tokens whose secrets work against the management API and are stored only as hashes', function () {
    seedDemo();
    $secrets = DemoSecrets::credentials();

    $rows = Tenancy::crossTenant('demo seed check', fn () => Credential::query()->orderBy('id')->get());
    expect($rows)->toHaveCount(7)
        ->and($rows->where('status', CredentialStatus::Revoked)->pluck('label')->all())->toBe(['Legacy mobile app'])
        ->and($rows->where('type', CredentialType::Personal)->pluck('user_id')->unique()->all())->toBe([DemoIds::user(1)]);

    foreach ($secrets as $secret) {
        expect(TokenGenerator::parse($secret))->not->toBeNull();
    }

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets['release_pipeline']])->assertOk()->assertJsonPath('label', 'Release pipeline');
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets['maya_storefront_token']])->assertOk()->assertJsonPath('user.name', 'Maya Santos');
    expect(fn () => app(CredentialResolver::class)->resolve($secrets['legacy_mobile_app']))->toThrow(RevokedCredential::class);
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$secrets['checkout_server_dev']])->assertUnauthorized()->assertJsonPath('error.code', 'WRONG_CREDENTIAL_TYPE');

    $stored = (string) json_encode(Tenancy::crossTenant('demo seed check', fn () => DB::table('credentials')->get()->all()), JSON_INVALID_UTF8_SUBSTITUTE);
    foreach ($secrets as $secret) {
        expect($stored)->not->toContain(substr($secret, -36, 30));
    }
});
