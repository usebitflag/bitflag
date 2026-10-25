<?php

declare(strict_types=1);

use App\Modules\Identity\Jobs\SendAccountEmail;
use App\Modules\Organizations\Actions\ExpireInvitations;
use App\Modules\Organizations\Actions\IssueInvitationLink;
use App\Modules\Organizations\Actions\ResendInvitation;
use App\Modules\Organizations\Actions\RevokeInvitation;
use App\Modules\Organizations\Data\ChangeInvitationData;
use App\Modules\Organizations\Domain\Exceptions\InvitationUnavailable;
use App\Modules\Organizations\Jobs\SendInvitationEmail;
use App\Modules\Organizations\Models\Invitation;
use App\Modules\Organizations\Notifications\InvitationEmail;
use App\Modules\Organizations\Queries\InvitationDetails;
use App\Shared\Audit\AuditEvent;
use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use App\Shared\Errors\AuthorizationDenied;
use App\Shared\Outbox\OutboxMessage;
use App\Shared\Outbox\OutboxMessageRecord;
use App\Shared\Outbox\OutboxScope;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    $this->owner = person(OrgRole::Owner, ProjectRole::Owner);
});

function change(array $p, string $id): ChangeInvitationData
{
    return new ChangeInvitationData($p['organization']->id, $id);
}

function fresh_invitation(string $id): Invitation
{
    return Tenancy::crossTenant('inspect', fn () => Invitation::query()->findOrFail($id));
}

function request_message(array $p, string $invitationId): OutboxMessage
{
    $record = OutboxMessageRecord::query()->where('payload->invitation_id', $invitationId)->latest('id')->firstOrFail();

    return OutboxMessage::restore($record->id, OutboxScope::Organization, $p['organization']->id, null, null, null, $record->type, $record->aggregate_type, $record->aggregate_id, $record->payload, null);
}

describe('making the link', function () {
    it('stores only a hash of a new token and starts seven days from now', function () {
        $this->freezeTime();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];

        $token = app(IssueInvitationLink::class)->handle($this->owner['organization']->id, $id);
        $invitation = fresh_invitation($id);

        expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and(strlen($invitation->token_hash))->toBe(32)
            ->and(bin2hex($invitation->token_hash))->not->toContain(bin2hex($token))
            ->and($invitation->sent_at->equalTo(now()))->toBeTrue()
            ->and($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
    });

    it('replaces the link on every send, so an earlier link stops working', function () {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $first = app(IssueInvitationLink::class)->handle($this->owner['organization']->id, $id);
        $second = app(IssueInvitationLink::class)->handle($this->owner['organization']->id, $id);

        expect($second)->not->toBe($first)
            ->and(app(InvitationDetails::class)->forToken($first)->state)->toBe('unknown')
            ->and(app(InvitationDetails::class)->forToken($second)->state)->toBe('open');
    });

    it('makes no link for an invitation that is not pending', function () {
        $this->owner['user']->forceFill(['email_verified_at' => null])->save();
        $queued = invite_people($this->owner, ['a@northwind.studio'])->created[0]['invitationId'];

        expect(app(IssueInvitationLink::class)->handle($this->owner['organization']->id, $queued))->toBeNull();
    });
});

describe('the email', function () {
    it('sends the invitation to the invited address with a link made now', function () {
        Notification::fake();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];

        app(SendInvitationEmail::class)->consume(request_message($this->owner, $id));

        Notification::assertSentOnDemand(InvitationEmail::class, function (InvitationEmail $notification, array $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);
            $body = view($mail->view['text'], $mail->viewData)->render();

            return $notifiable->routes['mail'] === 'dev@northwind.studio'
                && str_contains($mail->subject, $this->owner['organization']->name)
                && preg_match('#/invitations/[A-Za-z0-9_-]{43}#', $body) === 1
                && str_contains($body, '7 days');
        });
        expect(fresh_invitation($id)->token_hash)->not->toBeNull();
    });

    it('names the projects and roles in the email', function () {
        Notification::fake();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];

        app(SendInvitationEmail::class)->consume(request_message($this->owner, $id));

        Notification::assertSentOnDemand(InvitationEmail::class, function (InvitationEmail $notification, array $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);

            return str_contains(view($mail->view['text'], $mail->viewData)->render(), $this->owner['project']->name.' (developer)');
        });
    });

    it('sends a message once, even if delivered twice', function () {
        Notification::fake();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $message = request_message($this->owner, $id);

        app(SendInvitationEmail::class)->consume($message);
        app(SendInvitationEmail::class)->consume($message);

        Notification::assertSentOnDemandTimes(InvitationEmail::class, 1);
    });

    it('sends nothing for an invitation withdrawn before the email went out', function () {
        Notification::fake();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $message = request_message($this->owner, $id);
        app(RevokeInvitation::class)->handle($this->owner['actor'], change($this->owner, $id));

        app(SendInvitationEmail::class)->consume($message);

        Notification::assertNothingSent();
        expect(fresh_invitation($id)->token_hash)->toBeNull();
    });

    it('ignores other templates, and the account email consumer ignores this one', function () {
        Notification::fake();
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $message = request_message($this->owner, $id);

        app(SendAccountEmail::class)->consume($message);
        $other = OutboxMessage::restore($message->id, OutboxScope::Account, null, $this->owner['user']->id, null, null, $message->type, 'user', $this->owner['user']->id, ['template' => 'mfa_enabled', 'user_id' => $this->owner['user']->id, 'details' => []], null);
        app(SendInvitationEmail::class)->consume($other);

        Notification::assertNothingSent();
    });
});

describe('sending again', function () {
    it('sends a pending invitation again, with a new link and a fresh seven days', function () {
        $link = invitation_link($this->owner);
        $this->travel(3)->days();

        app(ResendInvitation::class)->handle($this->owner['actor'], change($this->owner, $link['invitation']->id));
        $second = app(IssueInvitationLink::class)->handle($this->owner['organization']->id, $link['invitation']->id);

        expect(OutboxMessageRecord::query()->where('payload->invitation_id', $link['invitation']->id)->count())->toBe(2)
            ->and(app(InvitationDetails::class)->forToken($link['token'])->state)->toBe('unknown')
            ->and(app(InvitationDetails::class)->forToken($second)->state)->toBe('open')
            ->and(fresh_invitation($link['invitation']->id)->expires_at->greaterThan(now()->addDays(6)))->toBeTrue()
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'invitation.resent')->count()))->toBe(1);
    });

    it('does not send again one that is not pending', function (string $status) {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        Tenancy::crossTenant('set up', fn () => Invitation::query()->whereKey($id)->update(['status' => $status]));

        expect(fn () => app(ResendInvitation::class)->handle($this->owner['actor'], change($this->owner, $id)))
            ->toThrow(InvitationUnavailable::class);
    })->with(['queued', 'accepted', 'revoked', 'expired']);

    it('is for people who manage the members', function () {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $member = person(OrgRole::Member, ProjectRole::Developer);

        expect(fn () => app(ResendInvitation::class)->handle($member['actor'], new ChangeInvitationData($this->owner['organization']->id, $id)))
            ->toThrow(AuthorizationDenied::class);
    });

    it('lets a project Owner send again an invitation that only adds people to their project', function () {
        $projectOwner = person(OrgRole::Member, ProjectRole::Owner);
        $id = invite_people($projectOwner, ['dev@northwind.studio'])->created[0]['invitationId'];

        app(ResendInvitation::class)->handle($projectOwner['actor'], change($projectOwner, $id));

        expect(OutboxMessageRecord::query()->where('payload->invitation_id', $id)->count())->toBe(2);
    });

    it('does not let a project Owner touch an invitation to another project', function () {
        $projectOwner = person(OrgRole::Member, ProjectRole::Owner);
        $theirs = person(OrgRole::Owner, ProjectRole::Owner);
        $id = invite_people($theirs, ['dev@northwind.studio'])->created[0]['invitationId'];

        expect(fn () => app(ResendInvitation::class)->handle($projectOwner['actor'], new ChangeInvitationData($theirs['organization']->id, $id)))
            ->toThrow(AuthorizationDenied::class);
    });
});

describe('withdrawing', function () {
    it('withdraws a pending or queued invitation and kills its link', function () {
        $link = invitation_link($this->owner);

        app(RevokeInvitation::class)->handle($this->owner['actor'], change($this->owner, $link['invitation']->id));

        $after = fresh_invitation($link['invitation']->id);
        expect($after->status->value)->toBe('revoked')
            ->and($after->revoked_by)->toBe($this->owner['user']->id)
            ->and($after->revoked_at)->not->toBeNull()
            ->and(app(InvitationDetails::class)->forToken($link['token'])->state)->toBe('revoked')
            ->and(Tenancy::crossTenant('inspect', fn () => AuditEvent::query()->where('action', 'invitation.revoked')->count()))->toBe(1);
    });

    it('refuses to withdraw one that is already used, withdrawn or expired', function (string $status) {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        Tenancy::crossTenant('set up', fn () => Invitation::query()->whereKey($id)->update(['status' => $status]));

        expect(fn () => app(RevokeInvitation::class)->handle($this->owner['actor'], change($this->owner, $id)))
            ->toThrow(InvitationUnavailable::class);
    })->with(['accepted', 'revoked', 'expired']);

    it('is for people who manage the members', function () {
        $id = invite_people($this->owner, ['dev@northwind.studio'])->created[0]['invitationId'];
        $member = person(OrgRole::Member, ProjectRole::Developer);

        expect(fn () => app(RevokeInvitation::class)->handle($member['actor'], new ChangeInvitationData($this->owner['organization']->id, $id)))
            ->toThrow(AuthorizationDenied::class);
    });
});

describe('expiring', function () {
    it('marks pending invitations whose seven days are up, and no others', function () {
        $old = invitation_link($this->owner, 'old@northwind.studio');
        $this->travel(6)->days();
        $recent = invitation_link($this->owner, 'recent@northwind.studio');
        $this->travel(2)->days();

        $count = app(ExpireInvitations::class)->handle();

        expect($count)->toBe(1)
            ->and(fresh_invitation($old['invitation']->id)->status->value)->toBe('expired')
            ->and(fresh_invitation($recent['invitation']->id)->status->value)->toBe('pending');
    });

    it('leaves queued and used invitations alone', function () {
        $queued = invite_people($this->owner, ['q@northwind.studio'])->created[0]['invitationId'];
        Tenancy::crossTenant('set up', fn () => Invitation::query()->whereKey($queued)->update(['status' => 'queued', 'expires_at' => now()->subDay()]));
        $this->travel(8)->days();

        expect(app(ExpireInvitations::class)->handle())->toBe(0)
            ->and(fresh_invitation($queued)->status->value)->toBe('queued');
    });

    it('works across organizations, and is scheduled every 15 minutes', function () {
        $other = person(OrgRole::Owner, ProjectRole::Owner);
        $a = invitation_link($this->owner, 'a@northwind.studio');
        $b = invitation_link($other, 'b@northwind.studio');
        $this->travel(8)->days();

        $this->artisan('invitations:expire')->assertSuccessful();

        expect(fresh_invitation($a['invitation']->id)->status->value)->toBe('expired')
            ->and(fresh_invitation($b['invitation']->id)->status->value)->toBe('expired');
        $scheduled = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'invitations:expire'));
        expect($scheduled->expression)->toBe('*/15 * * * *');
    });
});
