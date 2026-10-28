<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Middleware;

use App\Http\Middleware\ResolveActor;
use App\Modules\Credentials\Actions\CredentialUsageRecorder;
use App\Modules\Credentials\Data\ResolvedCredential;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\Exceptions\InvalidCredential;
use App\Modules\Credentials\Domain\Exceptions\WrongCredentialType;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Queries\CredentialResolver;
use App\Modules\Identity\Queries\PersonLookup;
use App\Modules\Identity\Queries\TwoFactorStatus;
use App\Modules\Identity\Queries\UserStatus;
use App\Modules\Organizations\Queries\MembershipLookup;
use App\Modules\Organizations\Queries\OrganizationSecurityPolicies;
use App\Shared\Actors\Actor;
use App\Shared\Actors\Channel;
use App\Shared\Errors\MfaRequired;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a management API request by its bearer token (a management key or a personal access token). SDK keys are
 * refused with WRONG_CREDENTIAL_TYPE, an unknown or malformed secret with INVALID_CREDENTIAL, and a revoked, expired or
 * rotated-out one with CREDENTIAL_REVOKED. A personal token works only while its owner is active, still belongs to the
 * project, and has met the organization's two-factor rule. On success it sets the Actor and the tenant context and notes the
 * use without a database write.
 */
final class AuthenticateManagementToken
{
    public const CREDENTIAL = 'credential';

    public function __construct(
        private readonly CredentialResolver $resolver,
        private readonly CredentialUsageRecorder $usage,
        private readonly UserStatus $users,
        private readonly PersonLookup $people,
        private readonly MembershipLookup $memberships,
        private readonly OrganizationSecurityPolicies $policies,
        private readonly TwoFactorStatus $twoFactor,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();

        if ($secret === null || $secret === '') {
            throw new AuthenticationException;
        }

        $parsed = TokenGenerator::parse($secret);

        if ($parsed !== null && $parsed->type->isSdkKey()) {
            throw new WrongCredentialType('SDK keys cannot call the management API.');
        }

        $credential = $this->resolver->resolve($secret);

        $this->assertStillValid($credential);

        $channel = match (strtolower(explode('/', (string) $request->header('X-BitFlag-Client'))[0])) {
            'bitflag-cli', 'cli' => Channel::Cli,
            'bitflag-mcp', 'mcp' => Channel::Mcp,
            default => Channel::Api,
        };

        $actor = $credential->type === CredentialType::Personal && $credential->userId !== null
            ? Actor::personalToken($credential->userId, $this->people->nameOf($credential->userId) ?? 'Unknown', $credential->id, $channel)
            : Actor::managementToken($credential->id, $credential->createdBy ?? '', $credential->projectId, $channel);

        $request->attributes->set(ResolveActor::ATTRIBUTE, $actor);
        $request->attributes->set(self::CREDENTIAL, $credential);
        $this->tenant->set($credential->organizationId);

        $this->throttle($credential);

        $this->usage->handle($credential->organizationId, $credential->id, (string) $request->header('X-BitFlag-Client'));

        return $next($request);
    }

    /**
     * 600 requests a minute per credential and 3,000 per organization. Counted here, after the credential is known, because the
     * limit belongs to it and the framework's throttle middleware runs before authentication.
     *
     * @throws ThrottleRequestsException
     */
    private function throttle(ResolvedCredential $credential): void
    {
        foreach (["cred|{$credential->id}" => 600, "org|{$credential->organizationId}" => 3000] as $key => $max) {
            $key = 'management|'.$key;

            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too many requests.', null, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }

            RateLimiter::hit($key, 60);
        }
    }

    private function assertStillValid(ResolvedCredential $credential): void
    {
        if ($credential->type !== CredentialType::Personal) {
            return;
        }

        if ($credential->userId === null || ! $this->users->isActive($credential->userId)) {
            throw new InvalidCredential;
        }

        $access = $this->memberships->organizationById($credential->userId, $credential->organizationId);

        if ($access === null || $this->memberships->projectRole($credential->userId, $credential->organizationId, $credential->projectId) === null) {
            throw new InvalidCredential;
        }

        if ($this->policies->requiresMfa($credential->organizationId) && ! $this->twoFactor->isEnrolled($credential->userId)) {
            throw new MfaRequired($access->organization->name);
        }
    }
}
