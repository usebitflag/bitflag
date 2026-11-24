<?php

declare(strict_types=1);

use App\Modules\Credentials\Domain\CredentialType;
use App\Modules\Environments\Models\EnvironmentPolicy;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Projects\Actions\ExpireProjectExport;
use App\Modules\Projects\Actions\ExportProject;
use App\Modules\Projects\Data\ExportProjectData;
use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExport;
use App\Modules\Projects\Queries\ProjectExports;
use App\Shared\Actors\Actor;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Lifecycle\ProjectExportSection;
use App\Shared\Lifecycle\ProjectExportSections;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    Redis::flushdb();
    $this->p = person(OrgRole::Member, ProjectRole::Owner);
    Tenancy::crossTenant('inspect', function () {
        $this->p['environment']->forceFill(['key' => 'production', 'name' => 'Production'])->save();
        $policy = EnvironmentPolicy::query()->create(['organization_id' => $this->p['organization']->id, 'project_id' => $this->p['project']->id, 'environment_id' => $this->p['environment']->id, 'version' => 1, 'is_protected' => true]);
        $this->p['environment']->forceFill(['current_policy_id' => $policy->id])->save();
    });
});

function request_export(array $p, ?Actor $actor = null): string
{
    return app(ExportProject::class)->handle($actor ?? $p['actor'], new ExportProjectData($p['organization']->id, $p['project']->id));
}

function export_row(string $id): ProjectExport
{
    return Tenancy::crossTenant('inspect', fn () => ProjectExport::query()->findOrFail($id));
}

function export_json(string $id): array
{
    return json_decode((string) Storage::disk('local')->get((string) export_row($id)->file_path), true, 512, JSON_THROW_ON_ERROR);
}

describe('exporting a project', function () {
    it('builds a JSON file with the registered sections and records the file\'s size and checksum', function () {
        $id = request_export($this->p);
        $row = export_row($id);
        $json = export_json($id);

        expect($row->status)->toBe(ExportStatus::Ready)
            ->and($row->expires_at?->isFuture())->toBeTrue()
            ->and($row->bytes)->toBe(strlen((string) Storage::disk('local')->get($row->file_path)))
            ->and($row->sha256)->toBe(hash('sha256', (string) Storage::disk('local')->get($row->file_path)))
            ->and(collect(array_keys($json))->sort()->values()->all())->toBe(['environments', 'exported_at', 'format_version', 'project'])
            ->and($json['project']['key'])->toBe($this->p['project']->key)
            ->and($json['project']['flag_defaults']['key_format'])->toBe('kebab')
            ->and($json['environments'])->toHaveCount(1)
            ->and($json['environments'][0]['key'])->toBe('production')
            ->and($json['environments'][0]['policy_history'][0]['protected'])->toBeTrue()
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'project.exported')->count()))->toBe(1);
    });

    it('never includes a key or a token, in any field', function () {
        $server = make_credential($this->p);
        $management = make_credential($this->p, [], CredentialType::Management);
        $personal = make_credential($this->p, [], CredentialType::Personal);

        $file = (string) Storage::disk('local')->get((string) export_row(request_export($this->p))->file_path);

        foreach ([$server, $management, $personal] as $key) {
            expect($file)->not->toContain($key['secret'])->not->toContain($key['credential']->last4.'"')->not->toContain(bin2hex($key['credential']->secret_hash));
        }
        expect($file)->not->toContain('bf_srv_')->not->toContain('bf_mgt_')->not->toContain('bf_pat_')->not->toContain('secret_hash');
    });

    it('includes the sections other modules register, and only registered ones', function () {
        app(ProjectExportSections::class)->register(FlagsSection::class);

        expect(export_json(request_export($this->p))['flags'])->toBe([['key' => 'checkout']]);
    });

    it('is for project Owners and one export at a time', function () {
        $viewer = person(OrgRole::Member, ProjectRole::Viewer);

        expect(fn () => request_export($this->p, $viewer['actor']))->toThrow(AuthorizationDenied::class);

        Tenancy::crossTenant('inspect', fn () => ProjectExport::query()->create(['organization_id' => $this->p['organization']->id, 'project_id' => $this->p['project']->id, 'status' => ExportStatus::Running]));
        expect(fn () => request_export($this->p))->toThrow(ValidationException::class);
    });

    it('does not export a deleted project', function () {
        Tenancy::crossTenant('inspect', fn () => Project::query()->whereKey($this->p['project']->id)->update(['status' => 'deleted']));

        expect(fn () => request_export($this->p))->toThrow(AuthorizationDenied::class);
    });

    it('records a failure with a code and no message', function () {
        app(ProjectExportSections::class)->register(BrokenSection::class);

        $row = export_row(request_export($this->p));

        expect($row->status)->toBe(ExportStatus::Failed)->and($row->error_code)->toBe('export_failed')->and($row->file_path)->toBeNull();
    });

    it('deletes the file after 24 hours and marks the export expired', function () {
        $row = export_row(request_export($this->p));
        $this->travel(23)->hours();
        app(ExpireProjectExport::class)->handle($this->p['organization']->id, $row->id);
        expect(export_row($row->id)->status)->toBe(ExportStatus::Ready);

        $this->travel(2)->hours();
        expect(app(ProjectExports::class)->due())->toBe([['organizationId' => $this->p['organization']->id, 'exportId' => $row->id]]);
        app(ExpireProjectExport::class)->handle($this->p['organization']->id, $row->id);

        expect(export_row($row->id)->status)->toBe(ExportStatus::Expired)->and(Storage::disk('local')->exists($row->file_path))->toBeFalse();
    });

    it('is invisible across organizations', function () {
        assertTenantIsolated(ProjectExport::class, function (Organization $organization) {
            $project = Project::factory()->create(['organization_id' => $organization->id]);

            return ProjectExport::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'status' => ExportStatus::Queued]);
        });
    });
});

describe('downloading', function () {
    beforeEach(function () {
        $this->world = portalWorld();
        $this->page = '/acme/storefront/production/settings/danger';
    });

    it('lists the exports with a signed link for owners, none for others', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/export")->assertRedirect();

        $this->actingAs($this->world['maya'])->get($this->page)->assertInertia(fn (Assert $page) => $page
            ->has('exports', 1)->where('exports.0.status', 'ready')->where('exports.0.downloadUrl', fn ($url) => str_contains($url, 'signature=')));
        $this->actingAs($this->world['ana'])->get($this->page)->assertInertia(fn (Assert $page) => $page->has('exports', 0));
    });

    it('serves the file to an owner through the signed link, as a private download', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/export");
        $url = $this->actingAs($this->world['maya'])->get($this->page)->inertiaProps('exports.0.downloadUrl');

        $response = $this->actingAs($this->world['maya'])->get($url)->assertOk()->assertHeader('Cache-Control');
        expect($response->headers->get('Content-Disposition'))->toContain('attachment')->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('refuses an unsigned or expired link, a viewer, and another organization', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/export");
        $url = $this->actingAs($this->world['maya'])->get($this->page)->inertiaProps('exports.0.downloadUrl');
        $id = basename(dirname($url));
        $id = explode('/', parse_url($url, PHP_URL_PATH))[7];

        $this->actingAs($this->world['maya'])->get("{$this->page}/exports/{$id}/download")->assertForbidden();
        $this->actingAs($this->world['ana'])->get($url)->assertForbidden();
        $this->actingAs($this->world['gina'])->get($url)->assertNotFound();

        $this->travel(11)->minutes();
        $this->actingAs($this->world['maya'])->get($url)->assertForbidden();
    });

    it('answers 404 once the export has expired', function () {
        $this->actingAs($this->world['maya'])->post("{$this->page}/export");
        $id = Tenancy::crossTenant('inspect', fn () => ProjectExport::query()->firstOrFail()->id);
        $url = URL::temporarySignedRoute('project.settings.danger.exports.download', now()->addDays(3), ['organization' => 'acme', 'project' => 'storefront', 'environment' => 'production', 'export' => $id]);

        $this->travel(25)->hours();
        $this->actingAs($this->world['maya'])->get($url)->assertNotFound();
    });

    it('does not let a person fetch another project\'s export through their own project', function () {
        $this->actingAs($this->world['maya'])->post('/acme/checkout-api/production/settings/danger/export');
        $id = Tenancy::crossTenant('inspect', fn () => ProjectExport::query()->firstOrFail()->id);
        $url = URL::temporarySignedRoute('project.settings.danger.exports.download', now()->addMinutes(5), ['organization' => 'acme', 'project' => 'storefront', 'environment' => 'production', 'export' => $id]);

        $this->actingAs($this->world['maya'])->get($url)->assertNotFound();
    });
});

final class FlagsSection implements ProjectExportSection
{
    public function name(): string
    {
        return 'flags';
    }

    public function export(string $organizationId, string $projectId): array
    {
        return [['key' => 'checkout']];
    }
}

final class BrokenSection implements ProjectExportSection
{
    public function name(): string
    {
        return 'broken';
    }

    public function export(string $organizationId, string $projectId): array
    {
        throw new RuntimeException('secret detail that must not be stored');
    }
}
