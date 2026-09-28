<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->workspace()->withoutGlobalScopes()->first();
        $url = rtrim(config('chat.frontend_url'), '/').'/invite/'.$this->token;

        return (new MailMessage)
            ->subject(__('mail.invitation.subject', ['workspace' => $workspace->name]))
            ->line(__('mail.invitation.line', ['workspace' => $workspace->name, 'role' => $this->invitation->role->value]))
            ->action(__('mail.invitation.action'), $url)
            ->line(__('mail.invitation.expires', ['date' => $this->invitation->expires_at->toDateString()]));
    }
}
