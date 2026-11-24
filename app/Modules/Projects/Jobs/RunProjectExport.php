<?php

declare(strict_types=1);

namespace App\Modules\Projects\Jobs;

use App\Modules\Projects\Actions\BuildProjectExport;
use App\Shared\Tenancy\RestoreTenantContext;
use App\Shared\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds a requested project export in the background, on the `exports` queue.
 */
final class RunProjectExport implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly string $organization, private readonly string $exportId)
    {
        $this->onQueue('exports');
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext];
    }

    public function handle(BuildProjectExport $build): void
    {
        $build->handle($this->organization, $this->exportId);
    }
}
