<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Models\ProjectExport;
use App\Shared\Lifecycle\ProjectExportSections;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Builds the file for a queued export: each registered section is written under its own name, the file goes to the private
 * disk, and the export is ready for 24 hours. A failure is recorded with a code, never a message. System-only: the queued job
 * calls it.
 */
final class BuildProjectExport
{
    public const KEEP_HOURS = 24;

    public function __construct(
        private readonly ProjectExportSections $sections,
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $organizationId, string $exportId): void
    {
        $this->tenant->run($organizationId, function () use ($organizationId, $exportId): void {
            $export = ProjectExport::query()->find($exportId);

            if ($export === null || $export->status !== ExportStatus::Queued) {
                return;
            }

            $export->forceFill(['status' => ExportStatus::Running])->save();

            try {
                $document = ['format_version' => 1, 'exported_at' => $this->clock->now()->format(DATE_ATOM)];

                foreach ($this->sections->all() as $section) {
                    $document[$section->name()] = $section->export($organizationId, $export->project_id);
                }

                $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $path = "exports/{$organizationId}/{$export->project_id}/{$export->id}.json";
                Storage::disk(Config::string('bitflag.exports.disk'))->put($path, $json);

                $export->forceFill([
                    'status' => ExportStatus::Ready,
                    'file_path' => $path,
                    'bytes' => strlen($json),
                    'sha256' => hash('sha256', $json),
                    'expires_at' => $this->clock->now()->modify('+'.self::KEEP_HOURS.' hours'),
                ])->save();
            } catch (Throwable $e) {
                Log::error('A project export failed.', ['export' => $export->id, 'exception' => $e::class]);
                $export->forceFill(['status' => ExportStatus::Failed, 'error_code' => 'export_failed'])->save();
            }
        });
    }
}
