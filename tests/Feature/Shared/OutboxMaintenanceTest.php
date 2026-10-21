<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Outbox\OutboxReceipt;
use App\Shared\Outbox\OutboxScope;
use App\Shared\Outbox\ReceiptStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 10:00:00.000000');
    $this->organization = Organization::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** A message stored directly, so a test can place it at any point in its life. */
function storedMessage(?string $dispatchedAt = null, ?string $availableAt = null): OutboxMessageRecord
{
    $message = new OutboxMessageRecord;
    $message->forceFill([
        'id' => strtolower((string) Str::ulid()),
        'scope' => OutboxScope::Organization,
        'organization_id' => test()->organization->id,
        'type' => 'internal.test_happened',
        'aggregate_type' => 'thing',
        'aggregate_id' => '01j9x3k7q2m5n8p0r4s6t8v0wy',
        'payload' => ['version' => 1],
        'occurred_at' => '2026-09-01 00:00:00.000000',
        'available_at' => $availableAt ?? '2026-09-01 00:00:00.000000',
        'dispatched_at' => $dispatchedAt,
    ])->save();

    return $message;
}

function storedReceipt(OutboxMessageRecord $message, ReceiptStatus $status, ?string $processedAt = null, ?string $nextAttemptAt = null, ?string $leaseUntil = null, string $consumer = 'tests.recording'): OutboxReceipt
{
    $receipt = new OutboxReceipt;
    $receipt->forceFill([
        'id' => strtolower((string) Str::ulid()),
        'outbox_message_id' => $message->id,
        'scope' => OutboxScope::Organization,
        'organization_id' => test()->organization->id,
        'consumer' => $consumer,
        'status' => $status,
        'processed_at' => $processedAt,
        'next_attempt_at' => $nextAttemptAt,
        'lease_until' => $leaseUntil,
    ])->save();

    return $receipt;
}

const OLD = '2026-09-01 12:00:00.000000';
const RECENT = '2026-10-15 12:00:00.000000';

describe('outbox:prune', function () {
    it('deletes a delivered message and its receipts once the retention period has passed', function () {
        $old = storedMessage(OLD);
        storedReceipt($old, ReceiptStatus::Succeeded, OLD);
        storedReceipt($old, ReceiptStatus::Succeeded, OLD, consumer: 'tests.other');
        $recent = storedMessage(RECENT);
        storedReceipt($recent, ReceiptStatus::Succeeded, RECENT);

        Artisan::call('outbox:prune');

        expect(OutboxMessageRecord::query()->pluck('id')->all())->toBe([$recent->id])
            ->and(OutboxReceipt::query()->count())->toBe(1);
    });

    it('keeps a message that still has work outstanding, however old', function (ReceiptStatus $status) {
        $message = storedMessage(OLD);
        storedReceipt($message, ReceiptStatus::Succeeded, OLD);
        storedReceipt($message, $status, null, '2026-09-02 00:00:00.000000', consumer: 'tests.other');

        Artisan::call('outbox:prune');

        expect(OutboxMessageRecord::query()->count())->toBe(1)->and(OutboxReceipt::query()->count())->toBe(2);
    })->with([ReceiptStatus::Pending, ReceiptStatus::Processing, ReceiptStatus::Failed, ReceiptStatus::Exhausted]);

    it('keeps a message whose last receipt succeeded inside the retention period', function () {
        $message = storedMessage(OLD);
        storedReceipt($message, ReceiptStatus::Succeeded, RECENT);

        Artisan::call('outbox:prune');

        expect(OutboxMessageRecord::query()->count())->toBe(1);
    });

    it('deletes an old dispatched message that no consumer wanted, and never an undispatched one', function () {
        $unwanted = storedMessage(OLD);
        $waiting = storedMessage();

        Artisan::call('outbox:prune');

        expect(OutboxMessageRecord::query()->pluck('id')->all())->toBe([$waiting->id])->and($unwanted->id)->not->toBe($waiting->id);
    });

    it('reports without deleting on a dry run', function () {
        storedReceipt(storedMessage(OLD), ReceiptStatus::Succeeded, OLD);

        $this->artisan('outbox:prune --dry-run')->expectsOutputToContain('1 message(s)')->assertSuccessful();

        expect(OutboxMessageRecord::query()->count())->toBe(1);
    });

    it('takes the retention period from the option, and refuses less than a day', function () {
        storedReceipt(storedMessage(RECENT), ReceiptStatus::Succeeded, RECENT);

        Artisan::call('outbox:prune', ['--days' => 30]);
        expect(OutboxMessageRecord::query()->count())->toBe(1);

        Artisan::call('outbox:prune', ['--days' => 1]);
        expect(OutboxMessageRecord::query()->count())->toBe(0);

        $this->artisan('outbox:prune --days=0')->assertExitCode(2);
    });

    it('works through more messages than one batch', function () {
        config(['outbox.prune_batch' => 2]);
        foreach (range(1, 5) as $ignored) {
            storedReceipt(storedMessage(OLD), ReceiptStatus::Succeeded, OLD);
        }

        Artisan::call('outbox:prune');

        expect(OutboxMessageRecord::query()->count())->toBe(0)->and(OutboxReceipt::query()->count())->toBe(0);
    });
});

describe('outbox:status', function () {
    it('counts messages and receipts by state', function () {
        $message = storedMessage('2026-10-19 09:00:00.000000');
        storedReceipt($message, ReceiptStatus::Succeeded, '2026-10-19 09:01:00.000000');
        storedReceipt($message, ReceiptStatus::Exhausted, consumer: 'tests.other');
        storedMessage();

        Artisan::call('outbox:status', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($report['messages'])->toBe(['waiting' => 1, 'dispatched' => 1])
            ->and($report['receipts'])->toMatchArray(['succeeded' => 1, 'exhausted' => 1, 'pending' => 0, 'failed' => 0, 'processing' => 0]);
    });

    it('measures how long the oldest overdue work has waited', function () {
        storedMessage(null, '2026-10-19 09:58:30.000000');
        storedReceipt(storedMessage('2026-10-19 09:00:00.000000'), ReceiptStatus::Failed, nextAttemptAt: '2026-10-19 09:59:00.000000');

        Artisan::call('outbox:status', ['--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['oldest_overdue_seconds'])->toBe(90);
    });

    it('counts a receipt whose worker lost its lease as overdue', function () {
        storedReceipt(storedMessage('2026-10-19 09:00:00.000000'), ReceiptStatus::Processing, leaseUntil: '2026-10-19 09:55:00.000000');

        Artisan::call('outbox:status', ['--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['oldest_overdue_seconds'])->toBe(300);
    });

    it('does not count work that is not due yet', function () {
        storedMessage(null, '2026-10-19 10:05:00.000000');
        storedReceipt(storedMessage('2026-10-19 09:00:00.000000'), ReceiptStatus::Failed, nextAttemptAt: '2026-10-19 10:30:00.000000');

        Artisan::call('outbox:status', ['--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['oldest_overdue_seconds'])->toBeNull();
    });

    it('fails when overdue work has waited longer than the allowed lag', function () {
        storedMessage(null, '2026-10-19 09:58:30.000000');

        $this->artisan('outbox:status --max-lag=120')->assertSuccessful();
        $this->artisan('outbox:status --max-lag=60')->expectsOutputToContain('waited 90 second(s)')->assertFailed();
    });

    it('fails on exhausted receipts only when asked to', function () {
        storedReceipt(storedMessage('2026-10-19 09:00:00.000000'), ReceiptStatus::Exhausted);

        $this->artisan('outbox:status')->assertSuccessful();
        $this->artisan('outbox:status --fail-on-exhausted')->assertFailed();
    });

    it('reports an empty outbox as healthy', function () {
        Artisan::call('outbox:status', ['--json' => true]);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['ok' => true, 'problems' => [], 'oldest_overdue_seconds' => null]);
    });
});
