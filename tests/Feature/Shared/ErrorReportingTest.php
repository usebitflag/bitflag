<?php

declare(strict_types=1);

use App\Shared\Errors\ResourceNotFound;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_probe/missing', fn () => throw new ResourceNotFound);
    Route::get('/_probe/broken', fn () => throw new RuntimeException('Something is actually wrong.'));
    Exceptions::fake();
});

it('answers an expected client error without reporting it', function () {
    $this->getJson('/_probe/missing')->assertNotFound();

    Exceptions::assertNothingReported();
});

it('still reports a server error', function () {
    $this->getJson('/_probe/broken')->assertStatus(500);

    Exceptions::assertReported(RuntimeException::class);
});
