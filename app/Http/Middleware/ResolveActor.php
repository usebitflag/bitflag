<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Queries\CurrentPerson;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the Actor for a signed-in portal session (channel dashboard) and keeps it on the request, where the container
 * hands it to controllers that ask for an Actor. Actions never read the session themselves.
 */
final class ResolveActor
{
    public const ATTRIBUTE = 'actor';

    public function __construct(private readonly CurrentPerson $currentPerson) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $person = $this->currentPerson->find();

        if ($person !== null) {
            $request->attributes->set(self::ATTRIBUTE, Actor::user($person->id, $person->name, Channel::Dashboard));
        }

        return $next($request);
    }
}
