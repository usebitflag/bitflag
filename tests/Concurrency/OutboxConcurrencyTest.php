<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Outbox\ConsumerRegistry;
use App\Shared\Outbox\DeliverOutboxMessage;
use App\Shared\Outbox\Dispatcher;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Outbox\OutboxReceipt;
use App\Shared\Outbox\ReceiptProcessor;
use App\Shared\Outbox\ReceiptRecovery;
use App\Shared\Outbox\ReceiptStatus;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Outbox\RecordingConsumer;

// These tests commit real rows and use separate database connections, as separate workers would.

const CONCURRENT_AGGREGATE = '01j9x3k7q2m5n8p0r4s6t8v0wy';

beforeEach(function () {
    RecordingConsumer::reset();
    app(ConsumerRegistry::class)->register(RecordingConsumer::class);
    Queue::fake();
    Carbon::setTestNow('2026-10-19 10:00:00.000000');
    $this->organization = Organization::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

function commitMessages(int $count): array
{
    $messages = [];

    for ($i = 0; $i < $count; $i++) {
        $message = OutboxMessage::forOrganization(test()->organization->id, 'internal.test_happened', 'thing', CONCURRENT_AGGREGATE, ['n' => $i]);
        DB::transaction(fn () => app(Outbox::class)->push($message));
        $messages[] = $message;
    }

    return $messages;
}

function workerConnection(string $name): Connection
{
    $connection = DB::getConnections()[$name] ?? DB::connectUsing($name, config('database.connections.mysql'));
    assert($connection instanceof Connection);

    return $connection;
}

/** Runs every queued job the way a worker would, then forgets them. */
function runQueuedJobs(): int
{
    $ran = 0;
    $jobs = Queue::pushedJobs()[DeliverOutboxMessage::class] ?? [];
    Queue::fake();

    foreach ($jobs as $entry) {
        $job = $entry['job'];
        app(ReceiptProcessor::class)->process($job->receiptId);
        $ran++;
    }

    return $ran;
}

it('lets a worker skip messages another worker has locked, and take them once they are free', function () {
    commitMessages(3);
    $dispatcher = app(Dispatcher::class);
    $first = workerConnection('worker_first');
    $second = workerConnection('worker_second');

    $first->beginTransaction();
    $first->table('outbox_messages')->lock('for update')->get();

    expect($dispatcher->claim('second', 100, $second))->toBe([]);

    $first->commit();

    expect($dispatcher->claim('second', 100, $second))->toHaveCount(3)
        ->and($dispatcher->claim('first', 100, $first))->toBe([]);
});

it('dispatches each message once when two workers take turns', function () {
    commitMessages(5);
    $dispatcher = app(Dispatcher::class);

    $a = $dispatcher->dispatchBatch('worker-a', 3, workerConnection('worker_a'));
    $b = $dispatcher->dispatchBatch('worker-b', 3, workerConnection('worker_b'));
    $c = $dispatcher->dispatchBatch('worker-a', 3, workerConnection('worker_a'));

    expect([$a, $b, $c])->toBe([3, 2, 0])
        ->and(OutboxReceipt::query()->count())->toBe(5)
        ->and(OutboxReceipt::query()->distinct('outbox_message_id')->count('outbox_message_id'))->toBe(5);
});

describe('recovery after a failure', function () {
    function assertEveryReceiptDoneOnce(int $messages): void
    {
        $receipts = OutboxReceipt::query()->get();

        expect($receipts)->toHaveCount($messages)
            ->and($receipts->every(fn (OutboxReceipt $r) => $r->status === ReceiptStatus::Succeeded))->toBeTrue()
            ->and(count(RecordingConsumer::$effects))->toBe($messages);
    }

    it('completes receipts when the worker died after creating them and before signalling', function () {
        commitMessages(2);
        $dispatcher = app(Dispatcher::class);
        $connection = DB::connection();

        foreach ($dispatcher->claim('dying-worker', 10, $connection) as $message) {
            $dispatcher->fanOut($message, 'dying-worker', $connection);
        }
        Queue::assertNothingPushed();

        expect(Artisan::call('outbox:recover'))->toBe(0);
        runQueuedJobs();

        assertEveryReceiptDoneOnce(2);
        expect(RecordingConsumer::$calls)->toHaveCount(2);
    });

    it('completes receipts when the jobs were pushed and then lost with the queue', function () {
        commitMessages(2);
        app(Dispatcher::class)->dispatchBatch('worker', 10);
        Queue::fake();

        Carbon::setTestNow('2026-10-19 10:03:00');
        Artisan::call('outbox:recover');
        runQueuedJobs();

        assertEveryReceiptDoneOnce(2);
        expect(RecordingConsumer::$calls)->toHaveCount(2);
    });

    it('runs a consumer once when the lost job and its replacement both arrive', function () {
        commitMessages(1);
        app(Dispatcher::class)->dispatchBatch('worker', 10);
        $original = Queue::pushedJobs()[DeliverOutboxMessage::class];

        Carbon::setTestNow('2026-10-19 10:03:00');
        Artisan::call('outbox:recover');
        $replacement = Queue::pushedJobs()[DeliverOutboxMessage::class];

        expect(count($original))->toBe(1)->and(count($replacement))->toBe(2);

        foreach ($replacement as $entry) {
            app(ReceiptProcessor::class)->process($entry['job']->receiptId);
        }

        assertEveryReceiptDoneOnce(1);
        expect(RecordingConsumer::$calls)->toHaveCount(1);
    });

    it('finishes receipts whose worker died inside the consumer, without repeating the effect', function () {
        $messages = commitMessages(1);
        app(Dispatcher::class)->dispatchBatch('worker', 10);
        Queue::fake();

        // The consumer applied its effect, then the worker died before recording success.
        RecordingConsumer::$effects[$messages[0]->id] = true;
        OutboxReceipt::query()->update(['status' => 'processing', 'attempts' => 1, 'lease_owner' => 'dead', 'lease_until' => '2026-10-19 09:59:00.000000']);

        Artisan::call('outbox:recover');
        Carbon::setTestNow('2026-10-19 10:03:00');
        app(ReceiptRecovery::class)->recover();
        runQueuedJobs();

        expect(OutboxReceipt::query()->firstOrFail()->status)->toBe(ReceiptStatus::Succeeded)
            ->and(count(RecordingConsumer::$effects))->toBe(1);
    });

    it('runs the consumer once when two jobs race for one receipt', function () {
        commitMessages(1);
        app(Dispatcher::class)->dispatchBatch('worker', 10);
        $receiptId = OutboxReceipt::query()->firstOrFail()->id;

        // The first job holds the receipt; the second finds it claimed and exits.
        OutboxReceipt::query()->update(['status' => 'processing', 'lease_owner' => 'first-job', 'lease_until' => '2026-10-19 10:05:00.000000']);
        app(ReceiptProcessor::class)->process($receiptId);

        expect(RecordingConsumer::$calls)->toBe([]);
    });
});
