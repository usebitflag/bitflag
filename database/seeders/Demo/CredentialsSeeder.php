<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Credentials\Domain\CredentialAudience;
use App\Modules\Credentials\Domain\CredentialStatus;
use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Credentials\Domain\RevokeReason;
use App\Modules\Credentials\Models\Credential;
use App\Modules\Projects\Models\Project;
use App\Shared\Security\SecretHasher;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Keys and tokens for the demo world: server keys for Checkout API, a browser key for the Storefront web app, a management key
 * for its release pipeline, a revoked key of a legacy mobile app, and Maya's two personal tokens. Only their hashes are stored;
 * the secrets are the fixed test values in DemoSecrets.
 */
final class CredentialsSeeder extends Seeder
{
    public function run(): void
    {
        $secrets = DemoSecrets::credentials();
        $acme = DemoIds::organization(OrganizationsSeeder::ACME);
        $maya = DemoIds::user(1);

        $rows = $this->rows($maya);

        app(TenantContext::class)->run($acme, function () use ($rows, $secrets, $acme, $maya): void {
            $hasher = app(SecretHasher::class);

            foreach ($rows as $row) {
                ['number' => $number, 'project' => $project, 'position' => $position, 'type' => $type, 'label' => $label, 'scopes' => $scopes, 'status' => $status, 'user' => $user] = $row;
                $secret = $secrets[$row['secret']];
                $revoked = $status === CredentialStatus::Revoked;
                $credential = Credential::query()->find(DemoIds::credential($number)) ?? new Credential;

                $credential->forceFill([
                    'id' => DemoIds::credential($number),
                    'organization_id' => $acme,
                    'project_id' => DemoIds::project($project),
                    'environment_id' => $position === null ? null : DemoIds::environment($project, $position),
                    'user_id' => $user,
                    'type' => $type,
                    'label' => $label,
                    'display_prefix' => substr($secret, 0, strlen($secret) - 36),
                    'last4' => substr($secret, -4),
                    'secret_hash' => $hasher->hash($secret),
                    'pepper_id' => $hasher->currentPepperId(),
                    'scopes' => $scopes,
                    'ttl_days' => $type === CredentialType::Personal ? 90 : null,
                    'expires_at' => $type === CredentialType::Personal ? now()->addDays(90) : null,
                    'status' => $status,
                    'revoked_at' => $revoked ? now()->subDays(3) : null,
                    'revoke_reason' => $revoked ? RevokeReason::Manual : null,
                    'audience' => CredentialAudience::Api,
                    'created_by' => $user ?? $maya,
                ])->save();
            }

            // Keys exist, so the projects' keys are fixed.
            Project::query()->whereIn('id', [DemoIds::project(ProjectsSeeder::STOREFRONT), DemoIds::project(ProjectsSeeder::CHECKOUT_API)])->whereNull('key_locked_at')->update(['key_locked_at' => now()]);
        });
    }

    /**
     * @return list<array{number: int, project: int, position: int|null, type: CredentialType, label: string, secret: string, scopes: list<string>, status: CredentialStatus, user: string|null}>
     */
    private function rows(string $maya): array
    {
        return [
            $this->row(1, ProjectsSeeder::CHECKOUT_API, 1, CredentialType::Server, 'Checkout API server', 'checkout_server_dev', ['snapshot:read', 'events:write']),
            $this->row(2, ProjectsSeeder::CHECKOUT_API, 3, CredentialType::Server, 'Checkout API server', 'checkout_server_prod', ['snapshot:read', 'events:write']),
            $this->row(3, ProjectsSeeder::STOREFRONT, 3, CredentialType::Browser, 'Web storefront', 'storefront_browser', ['snapshot:read']),
            $this->row(4, ProjectsSeeder::STOREFRONT, 1, CredentialType::Management, 'Release pipeline', 'release_pipeline', ['flags:read', 'flags:write']),
            $this->row(5, ProjectsSeeder::STOREFRONT, 3, CredentialType::Server, 'Legacy mobile app', 'legacy_mobile_app', ['snapshot:read'], CredentialStatus::Revoked),
            $this->row(6, ProjectsSeeder::STOREFRONT, null, CredentialType::Personal, 'Laptop CLI', 'maya_storefront_token', ['flags:read', 'flags:write'], CredentialStatus::Active, $maya),
            $this->row(7, ProjectsSeeder::CHECKOUT_API, null, CredentialType::Personal, 'Release script', 'maya_checkout_token', ['flags:read'], CredentialStatus::Active, $maya),
        ];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{number: int, project: int, position: int|null, type: CredentialType, label: string, secret: string, scopes: list<string>, status: CredentialStatus, user: string|null}
     */
    private function row(int $number, int $project, ?int $position, CredentialType $type, string $label, string $secret, array $scopes, CredentialStatus $status = CredentialStatus::Active, ?string $user = null): array
    {
        return ['number' => $number, 'project' => $project, 'position' => $position, 'type' => $type, 'label' => $label, 'secret' => $secret, 'scopes' => $scopes, 'status' => $status, 'user' => $user];
    }
}
