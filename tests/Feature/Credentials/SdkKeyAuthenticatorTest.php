<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialScope;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\ScopeMissing;
use App\Modules\Credentials\Domain\Exceptions\WrongCredentialType;
use App\Modules\Credentials\Queries\SdkKeyAuthenticator;
use App\Modules\Environments\Domain\EnvironmentStatus;
use App\Modules\Environments\Domain\Exceptions\OriginNotAllowed;
use App\Modules\Environments\Jobs\ForgetEnvironmentDeliverySettings;
use App\Modules\Environments\Models\Environment;
use App\Modules\Environments\Queries\BrowserOriginPolicy;
use App\Modules\Environments\Queries\EnvironmentDeliverySettings;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Cache::flush();
    Redis::flushdb();
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
});

it('authenticates server and browser keys with a fixed environment and projection', function () {
    $server = make_credential($this->p, ['scopes' => ['snapshot:read', 'evaluate']]);
    $browser = make_credential($this->p, ['scopes' => ['snapshot:read', 'events:write']], CredentialType::Browser);
    $authenticate = app(SdkKeyAuthenticator::class);

    $resolvedServer = $authenticate->authenticate($server['secret']);
    $resolvedBrowser = $authenticate->authenticate($browser['secret']);

    expect($resolvedServer->environmentId)->toBe($this->p['environment']->id)
        ->and($resolvedServer->projection)->toBe('server')
        ->and($resolvedServer->isBrowser())->toBeFalse()
        ->and($resolvedBrowser->projection)->toBe('public')
        ->and($resolvedBrowser->isBrowser())->toBeTrue();

    $resolvedServer->requireScope(CredentialScope::Evaluate);
    $resolvedBrowser->requireScope(CredentialScope::EventsWrite);
});

it('refuses management credentials and missing or browser-only scopes', function () {
    $management = make_credential($this->p, ['scopes' => ['flags:read']], CredentialType::Management);
    $browser = make_credential($this->p, ['scopes' => ['snapshot:read', 'evaluate']], CredentialType::Browser);
    $resolved = app(SdkKeyAuthenticator::class)->authenticate($browser['secret']);

    expect(fn () => app(SdkKeyAuthenticator::class)->authenticate($management['secret']))
        ->toThrow(WrongCredentialType::class)
        ->and(fn () => $resolved->requireScope(CredentialScope::EventsWrite))
        ->toThrow(ScopeMissing::class)
        ->and(fn () => $resolved->requireScope(CredentialScope::Evaluate))
        ->toThrow(ScopeMissing::class);
});

it('caches delivery settings, invalidates them from the outbox, and enforces browser origins', function () {
    Tenancy::crossTenant('arrange', fn () => Environment::query()->whereKey($this->p['environment']->id)->update([
        'allowed_origins' => json_encode(['https://app.example.com'], JSON_THROW_ON_ERROR),
        'default_max_snapshot_age_seconds' => 3600,
    ]));

    $settings = app(EnvironmentDeliverySettings::class);
    $first = $settings->find($this->p['organization']->id, $this->p['project']->id, $this->p['environment']->id)
        ?? throw new RuntimeException('The environment should exist.');
    expect($first->allowedOrigins)->toBe(['https://app.example.com'])
        ->and($first->defaultMaxSnapshotAgeSeconds)->toBe(3600)
        ->and($first->status)->toBe(EnvironmentStatus::Active);

    app(BrowserOriginPolicy::class)->check($first, 'https://APP.example.com/');
    expect(fn () => app(BrowserOriginPolicy::class)->check($first, null))->toThrow(OriginNotAllowed::class)
        ->and(fn () => app(BrowserOriginPolicy::class)->check($first, 'https://other.example.com'))->toThrow(OriginNotAllowed::class);

    Tenancy::crossTenant('arrange', fn () => Environment::query()->whereKey($this->p['environment']->id)->update([
        'allowed_origins' => json_encode(['https://new.example.com'], JSON_THROW_ON_ERROR),
    ]));
    expect($settings->find($this->p['organization']->id, $this->p['project']->id, $this->p['environment']->id)?->allowedOrigins)
        ->toBe(['https://app.example.com']);

    app(ForgetEnvironmentDeliverySettings::class)->consume(OutboxMessage::forOrganization(
        $this->p['organization']->id,
        'internal.environment.delivery_settings_changed',
        'environment',
        $this->p['environment']->id,
        ['environment_id' => $this->p['environment']->id],
        projectId: $this->p['project']->id,
        environmentId: $this->p['environment']->id,
    ));

    expect($settings->find($this->p['organization']->id, $this->p['project']->id, $this->p['environment']->id)?->allowedOrigins)
        ->toBe(['https://new.example.com']);
});
