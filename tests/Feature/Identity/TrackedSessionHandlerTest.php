<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\GeoLocator;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\TrackedDatabaseSessionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

function session_handler(?GeoLocator $geo = null): TrackedDatabaseSessionHandler
{
    return new TrackedDatabaseSessionHandler(
        DB::connection(),
        'sessions',
        120,
        app(),
        $geo ?? app(GeoLocator::class),
    );
}

function bind_request(string $ip = '203.0.113.9', string $userAgent = CHROME_MAC): void
{
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]));
}

it('records when a session began, its device and an unknown location', function () {
    Carbon::setTestNow('2026-10-25 10:00:00');
    bind_request();

    session_handler()->write('abc', 'payload');

    $row = DB::table('sessions')->where('id', 'abc')->first();

    expect($row->created_at)->toBe(Carbon::now()->getTimestamp())
        ->and($row->device_label)->toBe('Chrome on macOS')
        ->and($row->location_estimate)->toBeNull()
        ->and($row->ip_address)->toBe('203.0.113.9')
        ->and(base64_decode($row->payload))->toBe('payload');
});

it('keeps the start time and details on later writes and moves only the activity time', function () {
    Carbon::setTestNow('2026-10-25 10:00:00');
    bind_request();
    $handler = session_handler();
    $handler->write('abc', 'one');
    $began = Carbon::now()->getTimestamp();

    Carbon::setTestNow('2026-10-25 10:30:00');
    bind_request(userAgent: 'curl/8.7.1');
    session_handler()->write('abc', 'two');

    $row = DB::table('sessions')->where('id', 'abc')->first();

    expect($row->created_at)->toBe($began)
        ->and($row->device_label)->toBe('Chrome on macOS')
        ->and($row->last_activity)->toBe(Carbon::now()->getTimestamp())
        ->and(base64_decode($row->payload))->toBe('two');
});

it('asks the locator once, when the session is created', function () {
    bind_request();
    $geo = new class implements GeoLocator
    {
        public int $calls = 0;

        public function locate(string $ipAddress): ?string
        {
            $this->calls++;

            return 'Berlin, Germany';
        }
    };
    $handler = session_handler($geo);

    $handler->write('abc', 'one');
    $handler->write('abc', 'two');

    expect($geo->calls)->toBe(1)
        ->and(DB::table('sessions')->where('id', 'abc')->value('location_estimate'))->toBe('Berlin, Germany');
});

it('records the signed-in person', function () {
    bind_request();
    $user = User::factory()->create();
    $this->actingAs($user);

    session_handler()->write('abc', 'payload');

    expect(DB::table('sessions')->where('id', 'abc')->value('user_id'))->toBe($user->id);
});

it('deletes the row when a session is revoked', function () {
    bind_request();
    session_handler()->write('abc', 'payload');

    session_handler()->destroy('abc');

    expect(DB::table('sessions')->where('id', 'abc')->exists())->toBeFalse();
});

it('replaces the database session driver', function () {
    config()->set('session.driver', 'database');

    $handler = Session::driver()->getHandler();

    expect($handler)->toBeInstanceOf(TrackedDatabaseSessionHandler::class);
});
