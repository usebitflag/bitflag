<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\IssueCredentialData;
use App\Modules\Credentials\Data\IssuedCredential;
use App\Modules\Credentials\Data\PlaintextSecret;
use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialScope;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\EnvironmentTag;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Environments\Queries\EnvironmentLookup;
use App\Modules\Identity\Queries\EmailVerification;
use App\Modules\Projects\Actions\LockProjectKey;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Modules\Projects\Queries\ProjectWritability;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\EnvironmentScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Issues a server, browser or management key for one environment. The secret is returned once and only its peppered hash is
 * stored. Needs `credentials.manage`, a confirmed email, and an active project and environment. Issuing the first
 * credential of a project fixes the project key.
 */
final class IssueCredential
{
    /** The expiry choices, in days. Null (never) is also allowed. */
    public const TTL_DAYS = [30, 90, 365];

    public function __construct(
        private readonly TokenGenerator $generator,
        private readonly SecretHasher $hasher,
        private readonly EnvironmentLookup $environments,
        private readonly ProjectLookup $projects,
        private readonly ProjectWritability $writability,
        private readonly EmailVerification $verification,
        private readonly LockProjectKey $lockProjectKey,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, IssueCredentialData $data): IssuedCredential
    {
        $userId = $actor->id();

        if ($userId === null || ! $actor->can(Capability::CredentialsManage, new EnvironmentScope($data->organizationId, $data->projectId, $data->environmentId))) {
            throw new AuthorizationDenied;
        }

        $this->verification->assertVerified($userId);

        $type = CredentialType::tryFrom($data->type);
        $label = trim($data->label);

        if ($type === null || ! $type->isEnvironmentBound()) {
            throw ValidationException::withMessages(['type' => 'Choose a server, browser or management key.']);
        }

        $scopes = $this->scopes($type, $data->scopes);

        $problems = array_filter([
            'label' => $label === '' || mb_strlen($label) > 60 ? 'Enter a name of up to 60 characters.' : null,
            'ttl_days' => $data->ttlDays === null || in_array($data->ttlDays, self::TTL_DAYS, true) ? null : 'Choose never, 30 days, 90 days or 1 year.',
            'scopes' => $scopes === [] ? 'Choose at least one permission.' : null,
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $problems));
        }

        $project = $this->projects->byId($data->organizationId, $data->projectId);
        $environment = $this->environments->byId($data->organizationId, $data->projectId, $data->environmentId);

        if ($project === null || $environment === null) {
            throw new AuthorizationDenied;
        }

        $this->tenant->run($data->organizationId, fn () => $this->writability->assertWritable($data->projectId));

        if (! $environment->active) {
            throw ValidationException::withMessages(['environment' => 'An archived environment cannot get new keys.']);
        }

        $generated = $this->generator->generate($type, EnvironmentTag::for($environment->key));

        return $this->tenant->run($data->organizationId, fn (): IssuedCredential => DB::transaction(function () use ($actor, $userId, $data, $type, $label, $scopes, $generated): IssuedCredential {
            $now = $this->clock->now();

            $credential = Credential::query()->create([
                'organization_id' => $data->organizationId,
                'project_id' => $data->projectId,
                'environment_id' => $data->environmentId,
                'type' => $type,
                'label' => $label,
                'display_prefix' => $generated->displayPrefix,
                'last4' => $generated->last4,
                'secret_hash' => $this->hasher->hash($generated->secret),
                'pepper_id' => $this->hasher->currentPepperId(),
                'scopes' => $scopes,
                'ttl_days' => $data->ttlDays,
                'expires_at' => $data->ttlDays === null ? null : $now->modify("+{$data->ttlDays} days"),
                'status' => CredentialStatus::Active,
                'audience' => CredentialAudience::Api,
                'created_by' => $userId,
            ]);

            $this->lockProjectKey->handle($data->projectId);

            $this->audit->record(
                $actor,
                'key.created',
                new AuditTarget('key', $credential->id, $label),
                ['type' => $type->value, 'label' => $label, 'scopes' => $scopes, 'prefix' => $generated->displayPrefix, 'last4' => $generated->last4],
                projectId: $data->projectId,
                environmentId: $data->environmentId,
            );

            return new IssuedCredential($credential->id, new PlaintextSecret($generated->secret));
        }));
    }

    /**
     * The scopes the type may hold, in catalog order, without repeats or ones it may not have.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function scopes(CredentialType $type, array $requested): array
    {
        $allowed = array_map(static fn (CredentialScope $scope): string => $scope->value, CredentialScope::catalog($type));
        $unknown = array_diff($requested, $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['scopes' => 'This kind of key cannot have those permissions.']);
        }

        return array_values(array_filter($allowed, static fn (string $scope): bool => in_array($scope, $requested, true)));
    }
}
