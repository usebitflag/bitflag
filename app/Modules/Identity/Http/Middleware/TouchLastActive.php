<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Actions\RecordActivity;
use App\Modules\Identity\Queries\CurrentPerson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that a signed-in person is active, for "Last active" on the members list. It writes at most every five minutes.
 */
final class TouchLastActive
{
    public function __construct(
        private readonly CurrentPerson $current,
        private readonly RecordActivity $activity,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $person = $this->current->find();

        if ($person !== null) {
            $this->activity->handle($person->id);
        }

        return $next($request);
    }
}
