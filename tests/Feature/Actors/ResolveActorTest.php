<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_t/whoami', fn (Actor $actor) => [
        'id' => $actor->id(),
        'name' => $actor->displayName(),
        'channel' => $actor->channel()->value,
    ]);
});

it('hands a signed-in person to controllers as an Actor on the dashboard channel', function () {
    $user = User::factory()->create(['name' => 'Maya Santos']);

    $this->actingAs($user)->getJson('/_t/whoami')->assertOk()->assertExactJson([
        'id' => $user->id,
        'name' => 'Maya Santos',
        'channel' => Channel::Dashboard->value,
    ]);
});

it('answers 401 with the envelope when nobody is signed in', function () {
    $this->getJson('/_t/whoami')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
