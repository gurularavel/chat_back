<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Billing emails around one invoice. Stages:
 *  issued   – renewal invoice created ("your monthly/yearly payment is due")
 *  reminder – N days left before the due date
 *  due      – due today
 *  failed   – automatic charge failed / no card, service will stop after the grace period
 *  paid     – receipt
 */
class InvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const STAGES = ['issued', 'reminder', 'due', 'failed', 'paid'];

    public function __construct(
        public Invoice $invoice,
        public string $stage,
        public ?string $cardMask = null,
        public ?Carbon $graceEndsAt = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;
        $line = $invoice->lines[0] ?? [];
        $interval = __('mail.billing.interval.'.($line['kind'] ?? 'month'));
        $amount = $invoice->total.' '.$invoice->currency;
        $dueDate = $invoice->due_at?->format('d.m.Y');
        $params = [
            'number' => $invoice->number,
            'interval' => $interval,
            'plan' => $line['description'] ?? '',
            'workspace' => $invoice->buyer['name'] ?? '',
            'amount' => $amount,
            'date' => $dueDate,
            'days' => max(0, (int) ceil(now()->diffInDays($invoice->due_at, false))),
            'card' => $this->cardMask,
            'grace' => $this->graceEndsAt?->format('d.m.Y'),
        ];

        $mail = (new MailMessage)
            ->subject(__('mail.billing.'.$this->stage.'.subject', $params))
            ->greeting(__('mail.billing.greeting', ['name' => $invoice->buyer['name'] ?? '']));

        if ($this->stage === 'failed') {
            $mail->error();
        }

        $mail->line(__('mail.billing.'.$this->stage.'.intro', $params));

        // Invoice summary
        $mail->line('**'.__('mail.billing.invoice').':** '.$invoice->number)
            ->line('**'.__('mail.billing.service').':** '.($line['description'] ?? ''));
        if (! empty($line['period_start'])) {
            $mail->line('**'.__('mail.billing.period').':** '.Carbon::parse($line['period_start'])->format('d.m.Y').' – '.Carbon::parse($line['period_end'])->format('d.m.Y'));
        }
        if (! empty($line['seats'])) {
            $mail->line('**'.__('mail.billing.seats').':** '.$line['seats'].' × '.$line['unit_price'].' '.$invoice->currency);
        }
        $mail->line('**'.__('mail.billing.amount').':** '.$amount);
        if ($this->stage !== 'paid' && $dueDate) {
            $mail->line('**'.__('mail.billing.due').':** '.$dueDate);
        }

        if (in_array($this->stage, ['issued', 'reminder', 'due'], true)) {
            $mail->line($this->cardMask ? __('mail.billing.autocharge', $params) : __('mail.billing.pay_manually', $params));
        }

        return $mail
            ->action(__('mail.billing.'.($this->stage === 'paid' ? 'view' : 'pay')), $invoice->payUrl())
            ->line(__('mail.billing.footer'));
    }
}
