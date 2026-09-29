<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A handed-off visitor got no operator reply within the workspace's first-response target. */
class SlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Conversation $conversation)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $conversation = $this->conversation->loadMissing(['workspace', 'visitor', 'department']);
        $visitor = $conversation->visitor;
        $params = [
            'id' => $conversation->id,
            'workspace' => $conversation->workspace->name,
            'minutes' => (int) round($conversation->handoff_at->diffInMinutes($conversation->sla_due_at)),
            'waiting' => (int) $conversation->handoff_at->diffInMinutes(now()),
        ];

        $mail = (new MailMessage)
            ->error()
            ->subject(__('mail.sla.subject', $params))
            ->line(__('mail.sla.intro', $params))
            ->line('**'.__('mail.sla.visitor').':** '.($visitor?->name ?: $visitor?->email ?: '#'.$conversation->visitor_id));

        if ($conversation->department) {
            $mail->line('**'.__('mail.sla.department').':** '.$conversation->department->name);
        }

        return $mail
            ->line('**'.__('mail.sla.waiting').':** '.__('mail.sla.minutes', $params))
            ->action(__('mail.sla.action'), rtrim(config('chat.frontend_url'), '/').'/dashboard/inbox?c='.$conversation->id);
    }
}
