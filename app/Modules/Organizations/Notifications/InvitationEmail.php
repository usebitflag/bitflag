<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Notifications;

use App\Modules\Organizations\Data\InvitationSummary;
use App\Shared\Mail\EmailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email, drawn by the shared email layout. The person has no account yet, so it carries no greeting.
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
        return $this->message()->toMailMessage();
    }

    public function message(): EmailMessage
    {
        $inviter = $this->invitation->inviterName ?? 'Someone';
        $organization = $this->invitation->organizationName ?? 'an organization';
        $role = $this->invitation->orgRole?->value === 'owner' ? 'Org Owner' : 'Member';
        $projects = array_map(static fn (array $project): string => "{$project['name']} · {$project['role']}", $this->invitation->projects);
        $expires = $this->invitation->expiresAt !== null ? gmdate('j M Y', $this->invitation->expiresAt) : null;

        $facts = [['label' => 'Organization', 'value' => $organization]];

        if ($projects !== []) {
            $facts[] = ['label' => count($projects) === 1 ? 'Project' : 'Projects', 'value' => implode(', ', $projects)];
        }

        $facts[] = ['label' => 'Your role', 'value' => $role];
        $facts[] = ['label' => 'Invited by', 'value' => $inviter];

        if ($expires !== null) {
            $facts[] = ['label' => 'Expires', 'value' => $expires];
        }

        return new EmailMessage(
            "{$inviter} invited you to {$organization} on BitFlag",
            "Join {$organization} on BitFlag.",
            EmailMessage::KICKER_TEAM,
            "Join {$organization} on BitFlag",
            null,
            ["{$inviter} invited you to join the {$organization} organization on BitFlag."],
            $facts,
            button: ['label' => 'Accept invitation', 'url' => $this->link],
            note: "This link works for 7 days. You'll need to sign in or create an account with this email address. If you weren't expecting this, you can ignore it.",
            reason: 'You get this email because someone invited you to BitFlag. Invitations always send.',
            recipient: $this->invitation->email,
        );
    }
}
