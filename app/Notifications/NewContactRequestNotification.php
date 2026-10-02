<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the platform team that someone filled in the contact form. */
class NewContactRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContactRequest $contact)
    {
        $this->afterCommit();
        $this->locale('az');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = $this->contact;
        $name = trim($contact->first_name.' '.$contact->last_name);

        return (new MailMessage)
            ->subject(__('mail.contact.subject', ['name' => $name]))
            ->replyTo($contact->email, $name)
            ->line(__('mail.contact.intro'))
            ->line('**'.__('mail.contact.name').':** '.$name)
            ->line('**'.__('mail.contact.email').':** '.$contact->email)
            ->line('**'.__('mail.contact.phone').':** '.$contact->phone.' ('.$contact->country.')')
            ->action(__('mail.contact.action'), rtrim(config('chat.frontend_url'), '/').'/admin/contact-requests');
    }
}
