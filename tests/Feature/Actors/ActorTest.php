<?php

declare(strict_types=1);

use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Actors\Channel;

const USER_ULID = '01j9x3k7q2m5n8p0r4s6t8v0wy';

it('describes a person acting from the dashboard', function () {
    $actor = Actor::user(USER_ULID, 'Maya Santos', Channel::Dashboard);

    expect($actor->type())->toBe(ActorType::User)
        ->and($actor->id())->toBe(USER_ULID)
        ->and($actor->publicId())->toBe('usr_'.USER_ULID)
        ->and($actor->displayName())->toBe('Maya Santos')
        ->and($actor->channel())->toBe(Channel::Dashboard)
        ->and($actor->auditAttributes())->toBe([
            'actor_type' => 'user',
            'actor_id' => USER_ULID,
            'actor_display' => 'Maya Santos',
            'via_token_id' => null,
            'token_creator_id' => null,
            'channel' => 'dashboard',
        ]);
});

it('records the token a person acted through', function () {
    $actor = Actor::personalToken(USER_ULID, 'Maya Santos', 'tok_1', Channel::Cli);

    expect($actor->type())->toBe(ActorType::User)
        ->and($actor->auditAttributes()['via_token_id'])->toBe('tok_1')
        ->and($actor->auditAttributes()['channel'])->toBe('cli');
});

it('records the creator of a management token and binds it to a project', function () {
    $actor = Actor::managementToken('tok_2', USER_ULID, 'prj_x', Channel::Api);

    expect($actor->type())->toBe(ActorType::ManagementToken)
        ->and($actor->boundProjectId())->toBe('prj_x')
        ->and($actor->publicId())->toBeNull()
        ->and($actor->auditAttributes())->toMatchArray([
            'actor_type' => 'management_token',
            'actor_id' => 'tok_2',
            'via_token_id' => 'tok_2',
            'token_creator_id' => USER_ULID,
        ]);
});

it('has no identifier for the system and records the process', function () {
    $actor = Actor::system('outbox:work');

    expect($actor->id())->toBeNull()
        ->and($actor->auditAttributes())->toMatchArray([
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_display' => 'outbox:work',
            'channel' => 'system',
        ]);
});

it('records support staff and their ticket', function () {
    $actor = Actor::support('stf_1', 'BitFlag support', 'T-42');

    expect($actor->ticket())->toBe('T-42')
        ->and($actor->auditAttributes())->toMatchArray(['actor_type' => 'support', 'channel' => 'support']);
});
