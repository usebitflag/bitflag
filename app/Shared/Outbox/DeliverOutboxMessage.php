<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Tenancy\RestoreTenantContext;
use App\Shared\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A signal that a receipt has work to do. The job holds no state of its own: it asks ReceiptProcessor to claim the
 * receipt and run the consumer, so a duplicate or lost job never runs a consumer twice or drops work.
 */
final class DeliverOutboxMessage implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Retries are scheduled by the receipt, not by the queue. */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $receiptId, private readonly ?string $tenantOrganizationId = null) {}

    public function organizationId(): ?string
    {
        return $this->tenantOrganizationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext];
    }

    public function handle(ReceiptProcessor $processor): void
    {
        $processor->process($this->receiptId);
    }
}
