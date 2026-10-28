<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Actions;

use App\Modules\Credentials\Data\IssuedCredential;
use App\Modules\Credentials\Data\IssuePersonalAccessTokenData;
use App\Modules\Credentials\Data\PlaintextSecret;
use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialScope;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\PersonalAccessLevel;
use App\Modules\Credentials\Domain\TokenGenerator;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Identity\Queries\EmailVerification;
use App\Modules\Projects\Actions\LockProjectKey;
use App\Modules\Projects\Queries\ProjectLookup;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Makes a personal access token for the person acting, bound to one project. The token acts with the person's own permissions
 * and can only narrow them, so the access level it offers is limited by their role: drafts need the right to edit drafts, and
 * publishing needs the right to publish (protected environments still need a review). The caller confirms the password before
 * this runs. At most 20 tokens per person and project are active at a time.
 */
final class IssuePersonalAccessToken
{
    public const TTL_DAYS = [30, 90, 365];

    public const MAX_ACTIVE = 20;

    public function __construct(
        private readonly TokenGenerator $generator,
        private readonly SecretHasher $hasher,
        private readonly ProjectLookup $projects,
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
    public function handle(Actor $actor, IssuePersonalAccessTokenData $data): IssuedCredential
    {
        $userId = $actor->id();
        $scope = new ProjectScope($data->organizationId, $data->projectId);

        // A token cannot make another token: it takes a signed-in person.
        if ($userId === null || $actor->type() !== ActorType::User || $actor->viaTokenId() !== null || ! $actor->can(Capability::ProjectRead, $scope)) {
            throw new AuthorizationDenied;
        }

        $this->verification->assertVerified($userId);

        $level = PersonalAccessLevel::tryFrom($data->accessLevel);
        $label = trim($data->label);

        $problems = array_filter([
            'label' => $label === '' || mb_strlen($label) > 60 ? 'Enter a name of up to 60 characters.' : null,
            'access' => $level === null ? 'Choose read only, read and edit drafts, or read, edit and publish.' : null,
            'ttl_days' => $data->ttlDays === null || in_array($data->ttlDays, self::TTL_DAYS, true) ? null : 'Choose 30 days, 90 days, 1 year or never.',
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $problems));
        }

        if ($level === PersonalAccessLevel::Draft && ! $actor->can(Capability::FlagsDraftWrite, $scope)) {
            throw ValidationException::withMessages(['access' => 'Your role cannot edit drafts, so a token cannot either.']);
        }

        if ($level === PersonalAccessLevel::Publish && ! ($actor->can(Capability::FlagsDraftWrite, $scope) && $actor->can(Capability::EnvironmentPublish, $scope))) {
            throw ValidationException::withMessages(['access' => 'Your role cannot publish, so a token cannot either.']);
        }

        $project = $this->projects->byId($data->organizationId, $data->projectId);

        if ($project === null || ! $project->active) {
            throw ValidationException::withMessages(['access' => 'This project is read-only, so it cannot get new tokens.']);
        }

        $scopes = array_map(static fn (CredentialScope $item): string => $item->value, ($level ?? PersonalAccessLevel::Read)->scopes());
        $generated = $this->generator->generate(CredentialType::Personal);

        return $this->tenant->run($data->organizationId, fn (): IssuedCredential => DB::transaction(function () use ($actor, $userId, $data, $label, $scopes, $generated): IssuedCredential {
            $active = Credential::query()->where('user_id', $userId)->where('project_id', $data->projectId)
                ->where('type', CredentialType::Personal->value)
                ->whereIn('status', [CredentialStatus::Active->value, CredentialStatus::Rotating->value])
                ->lockForUpdate()->count();

            if ($active >= self::MAX_ACTIVE) {
                throw ValidationException::withMessages(['label' => 'You have 20 active tokens for this project. Revoke one first.']);
            }

            $credential = Credential::query()->create([
                'organization_id' => $data->organizationId,
                'project_id' => $data->projectId,
                'environment_id' => null,
                'user_id' => $userId,
                'type' => CredentialType::Personal,
                'label' => $label,
                'display_prefix' => $generated->displayPrefix,
                'last4' => $generated->last4,
                'secret_hash' => $this->hasher->hash($generated->secret),
                'pepper_id' => $this->hasher->currentPepperId(),
                'scopes' => $scopes,
                'ttl_days' => $data->ttlDays,
                'expires_at' => $data->ttlDays === null ? null : $this->clock->now()->modify("+{$data->ttlDays} days"),
                'status' => CredentialStatus::Active,
                'audience' => CredentialAudience::Api,
                'created_by' => $userId,
            ]);

            $this->lockProjectKey->handle($data->projectId);

            $this->audit->record(
                $actor,
                'token.created',
                new AuditTarget('token', $credential->id, $label),
                ['label' => $label, 'scopes' => $scopes, 'prefix' => $generated->displayPrefix, 'last4' => $generated->last4],
                projectId: $data->projectId,
            );

            return new IssuedCredential($credential->id, new PlaintextSecret($generated->secret));
        }));
    }
}
