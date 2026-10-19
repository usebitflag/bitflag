<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Outbox\ConsumerRegistry;
use App\Shared\Outbox\DeliverOutboxMessage;
use App\Shared\Outbox\Dispatcher;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Outbox\OutboxReceipt;
use App\Shared\Outbox\OutboxScope;
use App\Shared\Outbox\ReceiptProcessor;
use App\Shared\Outbox\ReceiptRecovery;
use App\Shared\Outbox\ReceiptStatus;
use App\Shared\Outbox\RetrySchedule;
use App\Shared\Persistence\TransactionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Outbox\RecordingConsumer;

const OUTBOX_AGGREGATE = '01j9x3k7q2m5n8p0r4s6t8v0wy';

beforeEach(function () {
    RecordingConsumer::reset();
    app(ConsumerRegistry::class)->register(RecordingConsumer::class);
    Queue::fake();
    Carbon::setTestNow('2026-10-19 10:00:00.000000');
    $this->organization = Organization::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function pushed(?string $organizationId = null, string $type = 'internal.test_happened'): OutboxMessage
{
    $message = OutboxMessage::forOrganization($organizationId ?? test()->organization->id, $type, 'thing', OUTBOX_AGGREGATE, ['version' => 3]);

    DB::transaction(fn () => app(Outbox::class)->push($message));

    return $message;
}

function dispatchAll(): int
{
    return app(Dispatcher::class)->dispatchBatch('test-worker');
}

function theReceipt(): OutboxReceipt
{
    return OutboxReceipt::query()->firstOrFail();
}

describe('pushing a message', function () {
    it('stores the message with its scope and payload', function () {
        $message = pushed();

        $record = OutboxMessageRecord::query()->findOrFail($message->id);

        expect($record->scope)->toBe(OutboxScope::Organization)
            ->and($record->organization_id)->toBe($this->organization->id)
            ->and($record->type)->toBe('internal.test_happened')
            ->and($record->aggregate_id)->toBe(OUTBOX_AGGREGATE)
            ->and($record->payload)->toBe(['version' => 3])
            ->and($record->dispatched_at)->toBeNull();
        expect($message->eventId())->toBe('evt_'.$message->id);
    });

    it('refuses to write outside a transaction', function () {
        $plain = DB::connectUsing('plain', config('database.connections.mysql'));
        $outbox = app()->make(Outbox::class, ['transactions' => new TransactionGuard($plain)]);

        $outbox->push(OutboxMessage::forOrganization($this->organization->id, 'internal.test_happened', 'thing', OUTBOX_AGGREGATE));
    })->throws(LogicException::class);

    it('leaves no message when the transaction rolls back', function () {
        try {
            DB::transaction(function () {
                app(Outbox::class)->push(OutboxMessage::forOrganization($this->organization->id, 'internal.test_happened', 'thing', OUTBOX_AGGREGATE));

                throw new RuntimeException('the change failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(OutboxMessageRecord::query()->count())->toBe(0);
    });

    it('rejects a payload that could carry secrets or subject data', function (array $payload) {
        DB::transaction(fn () => app(Outbox::class)->push(OutboxMessage::forOrganization($this->organization->id, 'internal.test_happened', 'thing', OUTBOX_AGGREGATE, $payload)));
    })->with([
        'token' => [['api_token' => 'x']],
        'context' => [['context' => ['kind' => 'user']]],
        'long text' => [['note' => str_repeat('x', 600)]],
    ])->throws(InvalidArgumentException::class);

    it('accepts webhook event names and internal types only', function () {
        expect(fn () => OutboxMessage::forSystem('revision.published', 'environment', OUTBOX_AGGREGATE))->not->toThrow(InvalidArgumentException::class)
            ->and(fn () => OutboxMessage::forSystem('flag.updated', 'flag', OUTBOX_AGGREGATE))->toThrow(InvalidArgumentException::class);
    });
});

describe('scope', function () {
    it('accepts an account message without an organization', function () {
        $message = OutboxMessage::forAccount(OUTBOX_AGGREGATE, 'internal.account_test', 'user', OUTBOX_AGGREGATE);

        DB::transaction(fn () => app(Outbox::class)->push($message));

        expect(OutboxMessageRecord::query()->findOrFail($message->id)->organization_id)->toBeNull();
    });

    it('rejects an organization message without an organization at the database', function () {
        $row = ['id' => OUTBOX_AGGREGATE, 'scope' => 'organization', 'type' => 'internal.x', 'aggregate_type' => 't', 'aggregate_id' => OUTBOX_AGGREGATE, 'payload' => '{}', 'occurred_at' => now(), 'available_at' => now()];

        DB::table('outbox_messages')->insert($row);
    })->throws(QueryException::class);

    it('rejects an account message without a user at the database', function () {
        $row = ['id' => OUTBOX_AGGREGATE, 'scope' => 'account', 'type' => 'internal.x', 'aggregate_type' => 't', 'aggregate_id' => OUTBOX_AGGREGATE, 'payload' => '{}', 'occurred_at' => now(), 'available_at' => now()];

        DB::table('outbox_messages')->insert($row);
    })->throws(QueryException::class);
});

describe('dispatching', function () {
    it('creates one receipt per interested consumer, marks the message dispatched and signals the job', function () {
        $message = pushed();

        expect(dispatchAll())->toBe(1);

        $receipt = theReceipt();

        expect($receipt->consumer)->toBe('tests.recording')
            ->and($receipt->status)->toBe(ReceiptStatus::Pending)
            ->and($receipt->organization_id)->toBe($this->organization->id)
            ->and($receipt->last_signalled_at)->not->toBeNull()
            ->and(OutboxMessageRecord::query()->findOrFail($message->id)->dispatched_at)->not->toBeNull();

        Queue::assertPushedOn('default', DeliverOutboxMessage::class, fn (DeliverOutboxMessage $job) => $job->receiptId === $receipt->id);
    });

    it('marks a message with no interested consumer dispatched without receipts', function () {
        pushed(type: 'revision.published');

        dispatchAll();

        expect(OutboxReceipt::query()->count())->toBe(0)
            ->and(OutboxMessageRecord::query()->whereNull('dispatched_at')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('does not dispatch a message twice', function () {
        pushed();

        expect(dispatchAll())->toBe(1)->and(dispatchAll())->toBe(0);
        expect(OutboxReceipt::query()->count())->toBe(1);
    });

    it('treats a repeated fan-out as harmless', function () {
        pushed();
        $dispatcher = app(Dispatcher::class);
        $connection = DB::connection();

        $claimed = $dispatcher->claim('w', 10, $connection);
        $dispatcher->fanOut($claimed[0], 'w', $connection);
        $again = $dispatcher->fanOut($claimed[0], 'w', $connection);

        expect($again)->toBe([])->and(OutboxReceipt::query()->count())->toBe(1);
    });

    it('creates receipts for an account message with the person, not an organization', function () {
        DB::transaction(fn () => app(Outbox::class)->push(OutboxMessage::forAccount(OUTBOX_AGGREGATE, 'internal.account_test', 'user', OUTBOX_AGGREGATE)));

        dispatchAll();

        expect(theReceipt()->scope)->toBe(OutboxScope::Account)
            ->and(theReceipt()->user_id)->toBe(OUTBOX_AGGREGATE)
            ->and(theReceipt()->organization_id)->toBeNull();
    });

    it('does not take a message that is not yet available', function () {
        $message = pushed();
        OutboxMessageRecord::query()->whereKey($message->id)->update(['available_at' => '2026-10-19 11:00:00.000000']);

        expect(dispatchAll())->toBe(0);
    });
});

describe('consuming', function () {
    it('runs the consumer once and marks the receipt succeeded', function () {
        $message = pushed();
        dispatchAll();

        app(ReceiptProcessor::class)->process(theReceipt()->id);
        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(RecordingConsumer::$calls)->toBe([$message->id])
            ->and(theReceipt()->status)->toBe(ReceiptStatus::Succeeded)
            ->and(theReceipt()->attempts)->toBe(1)
            ->and(theReceipt()->processed_at)->not->toBeNull();
    });

    it('sets the organization context for an organization message and none for an account message', function () {
        pushed();
        DB::transaction(fn () => app(Outbox::class)->push(OutboxMessage::forAccount(OUTBOX_AGGREGATE, 'internal.account_test', 'user', OUTBOX_AGGREGATE)));
        dispatchAll();

        foreach (OutboxReceipt::query()->orderBy('scope')->get() as $receipt) {
            app(ReceiptProcessor::class)->process($receipt->id);
        }

        expect(RecordingConsumer::$contexts)->toEqualCanonicalizing([null, $this->organization->id]);
    });

    it('backs off after a failure and does not run before the retry is due', function () {
        pushed();
        dispatchAll();
        RecordingConsumer::$failures = 1;
        Queue::fake();

        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(theReceipt()->status)->toBe(ReceiptStatus::Failed)
            ->and(theReceipt()->attempts)->toBe(1)
            ->and(theReceipt()->last_error)->toContain('the consumer failed')
            ->and(theReceipt()->next_attempt_at?->format('H:i:s'))->toBe('10:00:10');
        Queue::assertPushed(DeliverOutboxMessage::class);

        app(ReceiptProcessor::class)->process(theReceipt()->id);
        expect(RecordingConsumer::$calls)->toHaveCount(1);

        Carbon::setTestNow('2026-10-19 10:00:11');
        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(RecordingConsumer::$calls)->toHaveCount(2)->and(theReceipt()->status)->toBe(ReceiptStatus::Succeeded);
    });

    it('follows the documented backoff and gives up after twelve attempts', function () {
        pushed();
        dispatchAll();
        RecordingConsumer::$failures = 99;
        $expected = [10, 30, 120, 600, 1800, 3600, 3600, 3600, 3600, 3600, 3600];

        foreach ($expected as $index => $delay) {
            app(ReceiptProcessor::class)->process(theReceipt()->id);

            expect(theReceipt()->attempts)->toBe($index + 1)
                ->and(theReceipt()->status)->toBe(ReceiptStatus::Failed)
                ->and(RetrySchedule::delayAfter($index + 1))->toBe($delay);

            Carbon::setTestNow(Carbon::now()->addSeconds($delay + 1));
        }

        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(theReceipt()->status)->toBe(ReceiptStatus::Exhausted)
            ->and(theReceipt()->attempts)->toBe(RetrySchedule::MAX_ATTEMPTS)
            ->and(theReceipt()->next_attempt_at)->toBeNull();

        app(ReceiptProcessor::class)->process(theReceipt()->id);
        expect(RecordingConsumer::$calls)->toHaveCount(RetrySchedule::MAX_ATTEMPTS);
    });

    it('does nothing when another worker holds the receipt', function () {
        pushed();
        dispatchAll();
        OutboxReceipt::query()->update(['status' => 'processing', 'lease_owner' => 'other', 'lease_until' => '2026-10-19 10:05:00.000000']);

        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(RecordingConsumer::$calls)->toBe([]);
    });

    it('marks a receipt failed, visibly, when its consumer is no longer registered', function () {
        pushed();
        dispatchAll();
        OutboxReceipt::query()->update(['consumer' => 'tests.removed']);

        app(ReceiptProcessor::class)->process(theReceipt()->id);

        expect(theReceipt()->status)->toBe(ReceiptStatus::Failed)->and(theReceipt()->last_error)->toContain('tests.removed');
    });
});

describe('recovery', function () {
    it('returns a receipt whose worker died to failed, with the attempt counted', function () {
        pushed();
        dispatchAll();
        OutboxReceipt::query()->update(['status' => 'processing', 'attempts' => 1, 'lease_owner' => 'dead', 'lease_until' => '2026-10-19 09:59:00.000000']);

        $result = app(ReceiptRecovery::class)->recover();

        expect($result['released'])->toBe(1)
            ->and(theReceipt()->status)->toBe(ReceiptStatus::Failed)
            ->and(theReceipt()->lease_owner)->toBeNull()
            ->and(theReceipt()->next_attempt_at?->format('H:i:s'))->toBe('10:00:10');
    });

    it('exhausts a receipt whose last attempt lost its worker', function () {
        pushed();
        dispatchAll();
        OutboxReceipt::query()->update(['status' => 'processing', 'attempts' => RetrySchedule::MAX_ATTEMPTS, 'lease_until' => '2026-10-19 09:59:00.000000']);

        app(ReceiptRecovery::class)->recover();

        expect(theReceipt()->status)->toBe(ReceiptStatus::Exhausted);
    });

    it('signals a due receipt that was never signalled', function () {
        pushed();
        $dispatcher = app(Dispatcher::class);
        $claimed = $dispatcher->claim('w', 10, DB::connection());
        $dispatcher->fanOut($claimed[0], 'w', DB::connection());
        Queue::assertNothingPushed();

        $result = app(ReceiptRecovery::class)->recover();

        expect($result['signalled'])->toBe(1);
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    });

    it('leaves a recently signalled receipt alone and signals it again once the signal looks lost', function () {
        pushed();
        dispatchAll();
        Queue::fake();

        expect(app(ReceiptRecovery::class)->recover()['signalled'])->toBe(0);

        Carbon::setTestNow('2026-10-19 10:02:01');
        expect(app(ReceiptRecovery::class)->recover()['signalled'])->toBe(1);
        Queue::assertPushed(DeliverOutboxMessage::class, 1);
    });
});

describe('claims', function () {
    it('releases a claim whose worker stopped, so another worker can take the message', function () {
        $message = pushed();
        app(Dispatcher::class)->claim('dead-worker', 10, DB::connection());
        Carbon::setTestNow('2026-10-19 10:02:00');

        expect(dispatchAll())->toBe(1);

        $this->artisan('outbox:reclaim')->assertSuccessful();
        expect(OutboxMessageRecord::query()->findOrFail($message->id)->dispatched_at)->not->toBeNull();
    });

    it('clears expired claims on undispatched messages', function () {
        $message = pushed();
        app(Dispatcher::class)->claim('dead-worker', 10, DB::connection());
        Carbon::setTestNow('2026-10-19 10:02:00');

        $this->artisan('outbox:reclaim')->assertSuccessful();

        expect(OutboxMessageRecord::query()->findOrFail($message->id)->claimed_by)->toBeNull();
    });
});

it('runs one batch from the worker command', function () {
    pushed();

    $this->artisan('outbox:work', ['--once' => true])->assertSuccessful();

    expect(OutboxReceipt::query()->count())->toBe(1);
});
