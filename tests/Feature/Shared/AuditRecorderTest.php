<?php

declare(strict_types=1);

use App\Modules\Organizations\Models\Organization;
use App\Shared\Actors\Actor;
use App\Shared\Actors\ActorType;
use App\Shared\Actors\Channel;
use App\Shared\Audit\AuditCatalog;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditEventImmutable;
use App\Shared\Audit\AuditOutsideTransaction;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Audit\AuditTarget;
use App\Shared\Audit\Redactor;
use App\Shared\Audit\RevisionChange;
use App\Shared\Audit\UnknownAuditAction;
use App\Shared\Persistence\TransactionGuard;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextMissing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const AUDIT_USER = '01j9x3k7q2m5n8p0r4s6t8v0wy';
const AUDIT_TARGET = '01j9x3k7q2m5n8p0r4s6t8v0wz';

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    app(TenantContext::class)->set($this->organization->id);
    app(AuditCatalog::class)->register('flag.created', 'revision.published');
    $this->actor = Actor::user(AUDIT_USER, 'Maya Santos', Channel::Dashboard);
});

afterEach(fn () => Carbon::setTestNow());

it('records who did what to which object', function () {
    request()->attributes->set('request_id', 'req_01j9x3k7q2m5n8p0r4s6t8v0wy');
    Carbon::setTestNow('2026-10-19 03:04:05.123456');

    $event = app(AuditRecorder::class)->record(
        $this->actor,
        'revision.published',
        new AuditTarget('environment', AUDIT_TARGET, 'production'),
        ['flags' => 3],
        'Quarterly release',
        new RevisionChange(41, 42),
        projectId: null,
    );

    $stored = AuditEvent::query()->findOrFail($event->id);

    expect($stored->public_id)->toStartWith('aud_')
        ->and($stored->organization_id)->toBe($this->organization->id)
        ->and($stored->actor_type)->toBe(ActorType::User)
        ->and($stored->actor_id)->toBe(AUDIT_USER)
        ->and($stored->actor_display)->toBe('Maya Santos')
        ->and($stored->channel)->toBe(Channel::Dashboard)
        ->and($stored->action)->toBe('revision.published')
        ->and($stored->target_type)->toBe('environment')
        ->and($stored->target_id)->toBe(AUDIT_TARGET)
        ->and($stored->target_display)->toBe('production')
        ->and($stored->from_revision)->toBe(41)
        ->and($stored->to_revision)->toBe(42)
        ->and($stored->reason)->toBe('Quarterly release')
        ->and($stored->request_id)->toBe('req_01j9x3k7q2m5n8p0r4s6t8v0wy')
        ->and($stored->details)->toBe(['flags' => 3])
        ->and($stored->occurred_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-19 03:04:05.123456');
});

it('records the system as an actor without an identifier', function () {
    $event = app(AuditRecorder::class)->record(Actor::system('outbox:work'), 'flag.created', new AuditTarget('flag'));

    expect($event->actor_type)->toBe(ActorType::System)->and($event->actor_id)->toBeNull()->and($event->channel)->toBe(Channel::System);
});

it('refuses to write outside a transaction', function () {
    $plain = DB::connectUsing('plain', config('database.connections.mysql'));
    $recorder = app()->make(AuditRecorder::class, ['transactions' => new TransactionGuard($plain)]);

    $recorder->record($this->actor, 'flag.created', new AuditTarget('flag'));
})->throws(AuditOutsideTransaction::class);

it('rejects an action that no module registered', function () {
    app(AuditRecorder::class)->record($this->actor, 'flag.craeted', new AuditTarget('flag'));
})->throws(UnknownAuditAction::class);

it('needs an organization in context', function () {
    app(TenantContext::class)->clear();

    app(AuditRecorder::class)->record($this->actor, 'flag.created', new AuditTarget('flag'));
})->throws(TenantContextMissing::class);

it('rolls back with the change it describes', function () {
    try {
        DB::transaction(function () {
            app(AuditRecorder::class)->record($this->actor, 'flag.created', new AuditTarget('flag'));

            throw new RuntimeException('the change failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AuditEvent::query()->count())->toBe(0);
});

describe('redaction', function () {
    it('drops keys that can carry secrets or subject data at any depth', function () {
        $clean = (new Redactor)->redact([
            'name' => 'Checkout',
            'password' => 'hunter2',
            'Api_Token' => 'bf_srv_x',
            'client_secret' => 's',
            'plaintext' => 'p',
            'key_value' => 'k',
            'context' => ['kind' => 'user'],
            'attributes' => ['email' => 'a@b.c'],
            'Authorization' => 'Bearer x',
            'nested' => ['ok' => 1, 'refresh_token' => 'r', 'deeper' => ['secret_value' => 's', 'fine' => true]],
        ]);

        expect($clean)->toBe(['name' => 'Checkout', 'nested' => ['ok' => 1, 'deeper' => ['fine' => true]]]);
    });

    it('shortens long strings', function () {
        $clean = (new Redactor)->redact(['note' => str_repeat('é', 800), 'short' => 'ok']);

        expect(mb_strlen($clean['note']))->toBe(Redactor::MAX_STRING_LENGTH)
            ->and($clean['note'])->toEndWith('…')
            ->and($clean['short'])->toBe('ok');
    });

    it('is applied before the entry is stored', function () {
        $event = app(AuditRecorder::class)->record($this->actor, 'flag.created', new AuditTarget('flag'), ['password' => 'x', 'name' => 'n']);

        expect($event->details)->toBe(['name' => 'n']);
    });
});

describe('append-only', function () {
    beforeEach(function () {
        $this->event = app(AuditRecorder::class)->record($this->actor, 'flag.created', new AuditTarget('flag'));
    });

    it('refuses to update an entry', function () {
        $this->event->reason = 'rewritten history';
        $this->event->save();
    })->throws(AuditEventImmutable::class);

    it('refuses to delete an entry', function () {
        $this->event->delete();
    })->throws(AuditEventImmutable::class);

    it('refuses bulk updates and deletes through a query', function (string $operation) {
        match ($operation) {
            'update' => AuditEvent::query()->update(['reason' => 'x']),
            'delete' => AuditEvent::query()->delete(),
            'truncate' => AuditEvent::query()->truncate(),
            'increment' => AuditEvent::query()->increment('to_revision'),
        };
    })->with(['update', 'delete', 'truncate', 'increment'])->throws(AuditEventImmutable::class);

    it('keeps the entry', function () {
        expect(AuditEvent::query()->count())->toBe(1);
    });
});

it('keeps organizations apart', function () {
    app(TenantContext::class)->clear();

    assertTenantIsolated(AuditEvent::class, function (Organization $organization) {
        return app(TenantContext::class)->run($organization->id, fn () => DB::transaction(
            fn () => app(AuditRecorder::class)->record(Actor::system('test'), 'flag.created', new AuditTarget('flag')),
        ));
    }, mutable: false);
});

it('can be read across organizations only through crossTenant', function () {
    app(AuditRecorder::class)->record($this->actor, 'flag.created', new AuditTarget('flag'));

    expect(Tenancy::crossTenant('audit test', fn () => AuditEvent::query()->count()))->toBe(1);
});
