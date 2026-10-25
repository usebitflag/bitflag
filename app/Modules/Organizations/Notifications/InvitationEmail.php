<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Notifications;

use App\Modules\Organizations\Data\InvitationSummary;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email, as plain text. The wording is interim; the email catalog replaces it.
 */
final class InvitationEmail extends Notification
{
    public function __construct(
        private readonly InvitationSummary $invitation,
        private readonly string $link,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->inviterName ?? 'Someone';
        $organization = $this->invitation->organizationName ?? 'an organization';
        $role = $this->invitation->orgRole?->value === 'owner' ? 'an Org Owner' : 'a member';
        $projects = array_map(static fn (array $project): string => "{$project['name']} ({$project['role']})", $this->invitation->projects);

        $body = "{$inviter} invited you to join {$organization} on BitFlag as {$role}.";

        if ($projects !== []) {
            $body .= ' You will have access to: '.implode(', ', $projects).'.';
        }

        $body .= ' The link works for 7 days. If you were not expecting this, ignore this email.';

        return (new MailMessage)
            ->subject("{$inviter} invited you to {$organization} on BitFlag")
            ->text('mail.account', ['body' => $body, 'link' => $this->link]);
    }
}
