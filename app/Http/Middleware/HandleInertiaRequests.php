<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. They carry identifiers, names and booleans only, never models.
     * The TypeScript shape is resources/js/types/shared.ts.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => null],
            'scope' => [
                'organization' => null,
                'project' => null,
                'environment' => null,
                'projectRole' => null,
                'orgRole' => null,
            ],
            'environments' => [],
            'navigation' => [],
            'can' => (object) [],
            'flash' => ['toast' => null],
            'requestId' => null,
        ];
    }
}
