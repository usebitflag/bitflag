<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\RequestAccountEmail;
use App\Modules\Identity\Data\RequestAccountEmailData;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Jobs\SendAccountEmail;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountEmail;
use App\Shared\Outbox\ConsumerRegistry;
use App\Shared\Outbox\Dispatcher;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Outbox\OutboxScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    $this->user = User::factory()->create(['email' => 'maya@acme.dev']);
});

function request_email(AccountEmailTemplate $template, User $user, array $details = []): OutboxMessage
{
    DB::transaction(fn () => app(RequestAccountEmail::class)->handle(new RequestAccountEmailData($template, $user->id, $details)));
    $record = OutboxMessageRecord::query()->latest('id')->firstOrFail();

    return OutboxMessage::restore(
        $record->id, OutboxScope::Account, null, $user->id, null, null,
        $record->type, $record->aggregate_type, $record->aggregate_id, $record->payload, null,
    );
}

function mail_for(User $user): string
{
    $sent = Notification::sent($user, AccountEmail::class);
    $mail = $sent->first()->toMail($user);

    return $mail->subject."\n".view($mail->view['text'], $mail->viewData)->render();
}

it('queues the request as an account message that names the template and the person', function () {
    request_email(AccountEmailTemplate::MfaEnabled, $this->user);

    $record = OutboxMessageRecord::query()->firstOrFail();

    expect($record->type)->toBe('internal.email.requested')
        ->and($record->scope->value)->toBe('account')
        ->and($record->organization_id)->toBeNull()
        ->and($record->user_id)->toBe($this->user->id)
        ->and($record->payload)->toEqual(['template' => 'mfa_enabled', 'user_id' => $this->user->id, 'details' => []]);
});

it('leaves no request behind when the change rolls back', function () {
    try {
        DB::transaction(function () {
            app(RequestAccountEmail::class)->handle(new RequestAccountEmailData(AccountEmailTemplate::MfaEnabled, $this->user->id));

            throw new RuntimeException('the change failed');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessageRecord::query()->count())->toBe(0);
});

it('refuses a long detail', function () {
    request_email(AccountEmailTemplate::NewSignIn, $this->user, ['device_label' => str_repeat('x', 121)]);
})->throws(InvalidArgumentException::class);

it('keeps addresses, links and tokens out of the queued message', function (AccountEmailTemplate $template) {
    request_email($template, $this->user, ['device_label' => 'Chrome on macOS']);

    $payload = json_encode(OutboxMessageRecord::query()->firstOrFail()->payload);

    expect($payload)->not->toContain('maya@acme.dev')->not->toContain('http')->not->toContain('token')->not->toContain('signature');
})->with(AccountEmailTemplate::cases());

it('sends every template to the right address', function (AccountEmailTemplate $template) {
    $this->user->forceFill(['pending_email' => 'new@acme.dev', 'previous_email' => 'old@acme.dev'])->save();

    app(SendAccountEmail::class)->consume(request_email($template, $this->user));

    match ($template) {
        // The new address is asked to confirm itself and the old one is told it was replaced.
        AccountEmailTemplate::ConfirmNewEmail => Notification::assertSentOnDemand(AccountEmail::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@acme.dev'),
        AccountEmailTemplate::EmailChanged => Notification::assertSentOnDemand(AccountEmail::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@acme.dev'),
        default => Notification::assertSentTo($this->user, AccountEmail::class),
    };
})->with(AccountEmailTemplate::cases());

it('sends nothing for an email change that is no longer waiting', function (AccountEmailTemplate $template) {
    app(SendAccountEmail::class)->consume(request_email($template, $this->user));

    Notification::assertNothingSent();
})->with([AccountEmailTemplate::ConfirmNewEmail, AccountEmailTemplate::EmailChanged]);

it('puts a link in every email', function (AccountEmailTemplate $template) {
    $this->user->forceFill(['pending_email' => 'new@acme.dev', 'previous_email' => 'old@acme.dev'])->save();
    $body = null;
    $capture = function (AccountEmail $notification, $notifiable) use (&$body): bool {
        $mail = $notification->toMail($notifiable);
        $body = view($mail->view['text'], $mail->viewData)->render();

        return true;
    };

    app(SendAccountEmail::class)->consume(request_email($template, $this->user));

    match ($template) {
        AccountEmailTemplate::ConfirmNewEmail, AccountEmailTemplate::EmailChanged => Notification::assertSentOnDemand(AccountEmail::class, fn ($n, $channels, $notifiable) => $capture($n, $notifiable)),
        default => Notification::assertSentTo($this->user, AccountEmail::class, fn ($n) => $capture($n, $this->user)),
    };

    expect($body)->toContain('http');
})->with(AccountEmailTemplate::cases());

it('puts a signed link that works for 24 hours in the verification email', function () {
    $this->freezeTime();
    app(SendAccountEmail::class)->consume(request_email(AccountEmailTemplate::VerifyEmail, $this->user));

    preg_match('#https?://\S+#', mail_for($this->user), $match);
    $url = $match[0];

    expect($url)->toContain('/email/verify/'.$this->user->id.'/'.sha1('maya@acme.dev'))->toContain('signature=')
        ->and(app('url')->hasValidSignature(Request::create($url)))->toBeTrue();

    $this->travel(25)->hours();

    expect(app('url')->hasValidSignature(Request::create($url)))->toBeFalse();
});

it('puts a fresh reset token in the reset email, made at send time', function () {
    app(SendAccountEmail::class)->consume(request_email(AccountEmailTemplate::PasswordReset, $this->user));

    expect(mail_for($this->user))->toContain('/reset-password/')
        ->and(DB::table('password_reset_tokens')->where('email', 'maya@acme.dev')->exists())->toBeTrue();
});

it('states the facts it was given', function () {
    app(SendAccountEmail::class)->consume(request_email(AccountEmailTemplate::RecoveryCodeUsed, $this->user, ['codes_left' => 2]));

    expect(mail_for($this->user))->toContain('2 recovery codes left');
});

it('sends a repeated message once', function () {
    $message = request_email(AccountEmailTemplate::MfaDisabled, $this->user);

    app(SendAccountEmail::class)->consume($message);
    app(SendAccountEmail::class)->consume($message);

    Notification::assertSentToTimes($this->user, AccountEmail::class, 1);
});

it('sends nothing for an account that no longer exists', function () {
    $message = request_email(AccountEmailTemplate::MfaDisabled, $this->user);
    DB::table('users')->where('id', $this->user->id)->delete();

    app(SendAccountEmail::class)->consume($message);

    Notification::assertNothingSent();
});

it('fails loudly for a template it does not know', function () {
    $message = request_email(AccountEmailTemplate::MfaDisabled, $this->user);
    $unknown = OutboxMessage::restore(
        $message->id, OutboxScope::Account, null, $this->user->id, null, null, $message->type, 'user', $this->user->id,
        ['template' => 'invented', 'user_id' => $this->user->id, 'details' => []], null,
    );

    app(SendAccountEmail::class)->consume($unknown);
})->throws(InvalidArgumentException::class);

it('is registered for the message type and delivers through the worker', function () {
    expect(array_map(fn ($c) => $c::class, app(ConsumerRegistry::class)->consumersFor('internal.email.requested')))
        ->toContain(SendAccountEmail::class);

    request_email(AccountEmailTemplate::MfaEnabled, $this->user);
    app(Dispatcher::class)->dispatchBatch('test-worker');

    Notification::assertSentTo($this->user, AccountEmail::class);
});
