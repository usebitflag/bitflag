<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Domain\ExportStatus;
use App\Modules\Projects\Models\ProjectExport;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Psr\Clock\ClockInterface;

/**
 * Deletes the file of a ready export whose 24 hours have passed and marks it expired. System-only: the scheduler calls it.
 */
final class ExpireProjectExport
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClockInterface $clock,
    ) {}

    public function handle(string $organizationId, string $exportId): void
    {
        $this->tenant->run($organizationId, function () use ($exportId): void {
            $export = ProjectExport::query()->find($exportId);

            if ($export === null || $export->status !== ExportStatus::Ready || $export->expires_at === null || $export->expires_at > $this->clock->now()) {
                return;
            }

            if ($export->file_path !== null) {
                Storage::disk(Config::string('bitflag.exports.disk'))->delete($export->file_path);
            }

            $export->forceFill(['status' => ExportStatus::Expired, 'file_path' => null])->save();
        });
    }
}
