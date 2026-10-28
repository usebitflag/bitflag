<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Modules\Identity\Actions\RequestAccountEmail;
use App\Modules\Identity\Domain\AccountEmailTemplate;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\AccountEmail;
use App\Modules\Identity\Support\AccountEmailLinks;
use App\Shared\Outbox\OutboxConsumer;
use App\Shared\Outbox\OutboxMessage;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Sends the account and security emails asked for by `internal.email.requested`. It builds the link at send time, so no
 * secret is ever stored in the outbox. Delivery is at least once; a record of each sent message makes a repeat send
 * nothing, and if that record is lost the worst case is one duplicate email, never a missing one.
 */
final class SendAccountEmail implements OutboxConsumer
{
    private const SENT_TTL_SECONDS = 2_592_000;

    public function __construct(
        private readonly AccountEmailLinks $links,
        private readonly Repository $cache,
    ) {}

    public function name(): string
    {
        return 'identity.send_account_email';
    }

    public function handles(): array
    {
        return [RequestAccountEmail::TYPE];
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
        $name = $message->payload['template'] ?? null;

        // Invitation emails have their own consumer, which knows the invitation.
        if ($name === 'invitation') {
            return;
        }

        $template = is_string($name) ? AccountEmailTemplate::tryFrom($name) : null;

        if ($template === null) {
            throw new InvalidArgumentException('Unknown account email template.');
        }

        $sentKey = "account-email-sent:{$message->id}";

        if ($this->cache->has($sentKey)) {
            return;
        }

        $userId = $message->payload['user_id'] ?? null;
        $user = is_string($userId) ? User::query()->find($userId) : null;

        // The account is gone, so there is nobody to write to.
        if ($user === null) {
            return;
        }

        $details = $message->payload['details'] ?? [];

        $email = new AccountEmail($template, $this->links->for($template, $user), is_array($details) ? $details : []);
        $address = match ($template) {
            // A new address is asked to confirm itself, and the old one is told it was replaced.
            AccountEmailTemplate::ConfirmNewEmail => $user->pending_email,
            AccountEmailTemplate::EmailChanged => $user->previous_email,
            default => null,
        };

        if ($address !== null) {
            Notification::route('mail', $address)->notify($email);
        } elseif ($template === AccountEmailTemplate::ConfirmNewEmail || $template === AccountEmailTemplate::EmailChanged) {
            // The address is gone: the change was cancelled or the 30 days have passed.
            return;
        } else {
            $user->notify($email);
        }

        $this->cache->put($sentKey, true, self::SENT_TTL_SECONDS);
    }
}
