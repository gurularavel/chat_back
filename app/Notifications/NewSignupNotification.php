<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the platform team that a new account was registered. */
class NewSignupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $user, public Workspace $workspace)
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
        return (new MailMessage)
            ->subject(__('mail.signup.subject', ['workspace' => $this->workspace->name]))
            ->line(__('mail.signup.intro'))
            ->line('**'.__('mail.signup.name').':** '.$this->user->name)
            ->line('**'.__('mail.signup.email').':** '.$this->user->email)
            ->line('**'.__('mail.signup.workspace').':** '.$this->workspace->name)
            ->action(__('mail.signup.action'), rtrim(config('chat.frontend_url'), '/').'/admin/workspaces/'.$this->workspace->id);
    }
}
