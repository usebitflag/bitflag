<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\ChangeOrganizationRole;
use App\Modules\Organizations\Data\ChangeOrganizationRoleData;
use App\Modules\Organizations\Domain\Exceptions\LastOwner;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Two connections stand in for two requests that demote the two Org Owners of an organization at the same moment. The
// organization must keep one owner whichever finishes first.

function owners_connection(string $name): Connection
{
    $connection = DB::getConnections()[$name] ?? DB::connectUsing($name, config('database.connections.mysql'));
    assert($connection instanceof Connection);

    return $connection;
}

it('makes the second demotion wait for the first, and then refuses it', function () {
    $organization = Organization::factory()->create();
    $users = User::factory()->count(2)->create();
    $memberships = $users->map(fn (User $user) => Tenancy::crossTenant('set up', fn () => OrganizationMembership::query()->create([
        'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrgRole::Owner,
    ])));
    $actors = $users->map(fn (User $user) => Actor::user($user->id, $user->name, Channel::Dashboard));

    // The first request has started demoting the second owner: it holds a lock on the organization's owner rows.
    $first = owners_connection('request_first');
    $first->beginTransaction();
    $first->select('select id from organization_memberships where organization_id = ? and role = ? for update', [$organization->id, 'owner']);

    // The second request, run on the default connection, cannot take the same rows and gives up after a second.
    DB::statement('set session innodb_lock_wait_timeout = 1');
    $demoteFirst = fn () => app(ChangeOrganizationRole::class)->handle($actors[1], new ChangeOrganizationRoleData($organization->id, $memberships[0]->id, OrgRole::Member));

    expect($demoteFirst)->toThrow(QueryException::class);

    // The first request commits its demotion of the second owner.
    $first->update('update organization_memberships set role = ? where organization_id = ? and id = ?', ['member', $organization->id, $memberships[1]->id]);
    $first->commit();

    // The second request, authorized earlier and run again now, finds the first owner is the only one left.
    DB::statement('set session innodb_lock_wait_timeout = 50');
    expect($demoteFirst)->toThrow(LastOwner::class);

    expect(Tenancy::crossTenant('inspect', fn () => OrganizationMembership::query()->where('organization_id', $organization->id)->where('role', 'owner')->count()))->toBe(1);
});
