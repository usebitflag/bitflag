<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

function session_secure_setting(string $env, array $extra = []): string
{
    $result = Process::path(base_path())
        ->env(['APP_ENV' => $env, 'APP_DEBUG' => 'false', ...$extra])
        ->run(['php', 'artisan', 'config:show', 'session.secure']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return strtolower(trim($result->output()));
}

it('sends the session cookie over HTTPS only in production', function () {
    expect(session_secure_setting('production'))->toContain('true');
});

it('lets a deployment without HTTPS opt out, and leaves local development alone', function () {
    expect(session_secure_setting('production', ['SESSION_SECURE_COOKIE' => 'false']))->toContain('false')
        ->and(session_secure_setting('local'))->toContain('false');
});

it('asks search engines to stay away from the whole application', function () {
    expect(file_get_contents(public_path('robots.txt')))->toContain("User-agent: *\nDisallow: /\n");
});
