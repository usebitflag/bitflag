<?php

declare(strict_types=1);

it('responds on the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('serves the portal and the admin console on different hosts', function () {
    expect(config('bitflag.hosts.app'))->not->toBe(config('bitflag.hosts.admin'));
});

it('keeps the end-to-end routes off by default', function () {
    expect(config('testing.e2e'))->toBeFalse();
});
