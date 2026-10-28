<?php

declare(strict_types=1);

namespace App\Shared\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * What a BitFlag email says, apart from how it looks. The layout (`resources/views/mail/message*.blade.php`) draws it as
 * HTML and as plain text, so every email has the same shape: a heading, a few paragraphs, an optional list of facts or a
 * box, one button, a small note and an optional callout.
 *
 * Nothing here is trusted as markup: every string is escaped when it is drawn.
 */
final readonly class EmailMessage
{
    public const KICKER_ACCOUNT = 'Account';

    public const KICKER_SECURITY = 'Security alert';

    public const KICKER_TEAM = 'Team';

    /**
     * @param  list<string>  $paragraphs  The text between the heading and the facts, after the greeting.
     * @param  list<array{label: string, value: string}>  $facts
     * @param  array{heading: string, items: list<string>}|null  $panel  A boxed list, such as what a confirmed email unlocks.
     * @param  array{label: string, url: string}|null  $button
     * @param  array{tone: 'info'|'warning', text: string}|null  $callout
     */
    public function __construct(
        public string $subject,
        public string $preheader,
        public string $kicker,
        public string $title,
        public ?string $greeting,
        public array $paragraphs,
        public array $facts = [],
        public ?array $panel = null,
        public ?array $button = null,
        public ?string $note = null,
        public ?array $callout = null,
        public string $reason = "You get this email because it concerns your BitFlag account. It can't be turned off.",
        public ?string $recipient = null,
    ) {}

    public function withRecipient(string $address): self
    {
        return new self(
            $this->subject, $this->preheader, $this->kicker, $this->title, $this->greeting, $this->paragraphs, $this->facts,
            $this->panel, $this->button, $this->note, $this->callout, $this->reason, $address,
        );
    }

    public function toMailMessage(): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->view(['html' => 'mail.message', 'text' => 'mail.message-text'], ['email' => $this]);
    }
}
