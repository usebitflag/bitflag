<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Errors\ApplicationException;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Errors\ResourceNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('api')->prefix('api/v1/_probe')->group(function () {
        Route::get('denied', fn () => throw new AuthorizationDenied);
        Route::get('policy', fn () => throw new AuthorizationException('policy says no for tenant 42'));
        Route::get('missing', fn () => throw new ResourceNotFound);
        Route::get('model', fn () => throw (new ModelNotFoundException)->setModel(User::class, [42]));
        Route::get('unauthenticated', fn () => throw new AuthenticationException);
        Route::get('csrf', fn () => throw new TokenMismatchException);
        Route::get('throttle', fn () => throw new ThrottleRequestsException('slow down', null, ['Retry-After' => '7']));
        Route::get('boom', fn () => throw new RuntimeException('SQLSTATE[HY000] password=hunter2'));
        Route::get('conflict', fn () => throw new class('The draft changed.') extends ApplicationException
        {
            public function errorCode(): string
            {
                return 'VERSION_CONFLICT';
            }

            public function httpStatus(): int
            {
                return 409;
            }

            public function details(): array
            {
                return ['expectedVersion' => 17, 'currentVersion' => 18];
            }
        });
        Route::post('validate', fn (Request $request) => $request->validate([
            'name' => 'required|string',
            'age' => 'integer|min:18',
        ]));
    });

    Route::middleware('web')->prefix('_probe')->group(function () {
        Route::get('denied', fn () => throw new AuthorizationDenied);
        Route::get('boom', fn () => throw new RuntimeException('SQLSTATE[HY000] password=hunter2'));
        Route::post('validate', fn (Request $request) => $request->validate(['name' => 'required']));
    });
});

it('maps each exception to its status and code', function (string $path, int $status, string $code) {
    $response = $this->getJson("/api/v1/_probe/{$path}");

    $response->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    'application: forbidden' => ['denied', 403, 'FORBIDDEN'],
    'authorization exception' => ['policy', 403, 'FORBIDDEN'],
    'application: not found' => ['missing', 404, 'NOT_FOUND'],
    'model not found' => ['model', 404, 'NOT_FOUND'],
    'unknown route' => ['no-such-route', 404, 'NOT_FOUND'],
    'unauthenticated' => ['unauthenticated', 401, 'UNAUTHENTICATED'],
    'token mismatch' => ['csrf', 419, 'SESSION_EXPIRED'],
    'throttled' => ['throttle', 429, 'RATE_LIMITED'],
    'application: conflict' => ['conflict', 409, 'VERSION_CONFLICT'],
    'anything else' => ['boom', 500, 'INTERNAL_ERROR'],
]);

it('returns the envelope with a request ID that matches the response header', function () {
    $response = $this->getJson('/api/v1/_probe/conflict');

    $response->assertExactJson([
        'error' => [
            'code' => 'VERSION_CONFLICT',
            'message' => 'The draft changed.',
            'request_id' => $response->headers->get('X-Request-Id'),
            'details' => ['expectedVersion' => 17, 'currentVersion' => 18],
        ],
    ]);
});

it('serializes empty details as an object', function () {
    $this->getJson('/api/v1/_probe/denied');

    expect($this->getJson('/api/v1/_probe/denied')->getContent())->toContain('"details":{}');
});

it('describes validation failures field by field', function () {
    $response = $this->postJson('/api/v1/_probe/validate', ['age' => 12]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect($response->json('error.details.fields'))->toBe([
        ['path' => 'name', 'code' => 'required', 'message' => 'The name field is required.'],
        ['path' => 'age', 'code' => 'min', 'message' => 'The age field must be at least 18.'],
    ]);
});

it('sends Retry-After with a rate limit', function () {
    $this->getJson('/api/v1/_probe/throttle')->assertStatus(429)->assertHeader('Retry-After', '7');
});

it('never exposes internals in an error', function () {
    foreach (['boom', 'model', 'policy'] as $path) {
        $body = $this->getJson("/api/v1/_probe/{$path}")->getContent();

        expect($body)->not->toContain('hunter2')
            ->not->toContain('SQLSTATE')
            ->not->toContain('App\\Models')
            ->not->toContain('tenant 42')
            ->not->toContain('"trace"')
            ->not->toContain('"file"')
            ->not->toContain('"exception"');
    }
});

it('returns the envelope for SDK routes', function () {
    $this->getJson('/sdk/v1/anything')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
});

describe('the portal', function () {
    it('shows the error page with the request ID for a forbidden request', function () {
        $response = $this->get('/_probe/denied');

        $response->assertForbidden()->assertInertia(fn (Assert $page) => $page
            ->component('errors/Error')
            ->where('status', 403)
            ->where('requestId', $response->headers->get('X-Request-Id')));
    });

    it('shows the error page for an unknown page', function () {
        $this->get('/no-such-page')->assertNotFound()->assertInertia(fn (Assert $page) => $page
            ->component('errors/Error')->where('status', 404));
    });

    it('shows the error page for an unexpected error when debugging is off', function () {
        $response = $this->get('/_probe/boom');

        $response->assertStatus(500)->assertInertia(fn (Assert $page) => $page
            ->component('errors/Error')->where('status', 500));
        expect($response->getContent())->not->toContain('hunter2');
    });

    it('keeps the detailed debug page for unexpected errors when debugging is on', function () {
        config(['app.debug' => true]);

        $this->get('/_probe/boom', ['Accept' => 'text/html'])->assertStatus(500)->assertSee('hunter2', false);
    });

    it('keeps normal form validation behavior', function () {
        $this->from('/form')->post('/_probe/validate', [])->assertRedirect('/form')->assertSessionHasErrors('name');
    });
});
