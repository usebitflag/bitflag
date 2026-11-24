<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\UpdateProjectSettingsData;
use App\Modules\Projects\Domain\FlagKeyFormat;
use App\Modules\Projects\Domain\ProjectKeyRules;
use App\Modules\Projects\Domain\ProjectSettingsRules;
use App\Modules\Projects\Models\Project;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Capabilities\Capability;
use App\Shared\Capabilities\ProjectScope;
use App\Shared\Concurrency\VersionedUpdate;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a project's General settings: name, key, description, time zone and the defaults new flags start with. The key can
 * change only until it is locked by the first credential or publication. Returns the new version.
 */
final class UpdateProjectSettings
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @throws AuthorizationDenied
     * @throws ValidationException
     */
    public function handle(Actor $actor, UpdateProjectSettingsData $data): int
    {
        if (! $actor->can(Capability::ProjectManage, new ProjectScope($data->organizationId, $data->projectId))) {
            throw new AuthorizationDenied;
        }

        $name = trim($data->name);
        $key = strtolower(trim($data->key));
        $description = $data->description === null || trim($data->description) === '' ? null : trim($data->description);

        $flagKeyFormat = FlagKeyFormat::tryFrom($data->flagKeyFormat);

        $problems = array_filter([
            'flag_key_format' => $flagKeyFormat === null ? ProjectSettingsRules::FLAG_KEY_FORMAT : null,
            'name' => ProjectSettingsRules::name($name),
            'description' => ProjectSettingsRules::description($description),
            'time_zone' => ProjectSettingsRules::timeZone($data->timeZone),
            'stale_after_days' => ProjectSettingsRules::staleAfterDays($data->staleAfterDays),
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $problems));
        }

        try {
            return $this->tenant->run($data->organizationId, fn (): int => DB::transaction(
                fn (): int => $this->save($actor, $data, $name, $key, $description, $flagKeyFormat ?? FlagKeyFormat::Kebab),
            ));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['key' => ProjectKeyRules::TAKEN]);
        }
    }

    private function save(Actor $actor, UpdateProjectSettingsData $data, string $name, string $key, ?string $description, FlagKeyFormat $flagKeyFormat): int
    {
        $project = Project::query()->lockForUpdate()->findOrFail($data->projectId);

        if ($key !== $project->key) {
            if ($project->key_locked_at !== null) {
                throw ValidationException::withMessages(['key' => ProjectSettingsRules::KEY_LOCKED]);
            }

            $problem = ProjectKeyRules::problem($key);

            if ($problem !== null) {
                throw ValidationException::withMessages(['key' => $problem]);
            }
        }

        $after = [
            'name' => $name,
            'key' => $key,
            'description' => $description,
            'time_zone' => $data->timeZone,
            'flag_key_format' => $flagKeyFormat->value,
            'stale_after_days' => $data->staleAfterDays,
            'require_flag_description' => $data->requireFlagDescription,
            'require_flag_owner' => $data->requireFlagOwner,
            'client_visible_by_default' => $data->clientVisibleByDefault,
        ];
        $before = array_intersect_key([
            'name' => $project->name,
            'key' => $project->key,
            'description' => $project->description,
            'time_zone' => $project->time_zone,
            'flag_key_format' => $project->flag_key_format->value,
            'stale_after_days' => $project->stale_after_days,
            'require_flag_description' => $project->require_flag_description,
            'require_flag_owner' => $project->require_flag_owner,
            'client_visible_by_default' => $project->client_visible_by_default,
        ], $after);

        $version = VersionedUpdate::apply(Project::query()->whereKey($project->id), $data->expectedVersion, $after);

        $changed = array_keys(array_diff_assoc($after, $before));
        $this->audit->record(
            $actor,
            'project.updated',
            new AuditTarget('project', $project->id, $name),
            [
                'changed' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'version' => $version,
            ],
            projectId: $project->id,
        );

        return $version;
    }
}
