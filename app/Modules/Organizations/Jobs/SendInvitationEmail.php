<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Jobs;

use App\Modules\Organizations\Actions\IssueInvitationLink;
use App\Modules\Organizations\Notifications\InvitationEmail;
use App\Modules\Organizations\Queries\InvitationDetails;
use App\Shared\Outbox\OutboxConsumer;
use App\Shared\Outbox\OutboxMessage;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the invitation emails asked for by `internal.email.requested` with the template `invitation`. It makes the link when
 * it sends, so the token is never in the outbox, and a message already sent is not sent twice. Messages for other templates
 * belong to other consumers and are ignored here.
 */
final class SendInvitationEmail implements OutboxConsumer
{
    public const TEMPLATE = 'invitation';

    private const SENT_TTL_SECONDS = 2_592_000;

    public function __construct(
        private readonly IssueInvitationLink $links,
        private readonly InvitationDetails $details,
        private readonly Repository $cache,
    ) {}

    public function name(): string
    {
        return 'organizations.send_invitation_email';
    }

    public function handles(): array
    {
        return ['internal.email.requested'];
    }

    public function queue(): string
    {
        return 'notifications';
    }

    public function timeoutSeconds(): int
    {
        return 60;
    }

    public function consume(OutboxMessage $message): void
    {
        $invitationId = $message->payload['invitation_id'] ?? null;

        if (($message->payload['template'] ?? null) !== self::TEMPLATE || ! is_string($invitationId) || $message->organizationId === null) {
            return;
        }

        $sentKey = "invitation-email-sent:{$message->id}";

        if ($this->cache->has($sentKey)) {
            return;
        }

        $token = $this->links->handle($message->organizationId, $invitationId);

        // Withdrawn or used while the email waited its turn.
        if ($token === null) {
            return;
        }

        $summary = $this->details->forId($message->organizationId, $invitationId);

        if ($summary === null || $summary->email === null) {
            return;
        }

        Notification::route('mail', $summary->email)->notify(new InvitationEmail($summary, route('invitations.show', ['token' => $token])));

        $this->cache->put($sentKey, true, self::SENT_TTL_SECONDS);
    }
}
