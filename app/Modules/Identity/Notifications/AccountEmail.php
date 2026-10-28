<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Shared\Mail\EmailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An account or security email, already written (see `AccountEmailContent`) and drawn by the shared email layout.
 */
final class AccountEmail extends Notification
{
    public function __construct(private readonly EmailMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message->toMailMessage();
    }
}
