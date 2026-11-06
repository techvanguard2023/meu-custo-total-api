<?php

namespace App\Notifications;

use App\Models\PlatformFeedback;
use App\Services\PlatformFeedbackService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail pra plataforma quando uma opinião pede atenção: fim do teste sem assinar, cancelamento, NPS 0–6,
 * atendimento 1–2 ou qualquer resposta com comentário.
 */
class PlatformFeedbackToAdmin extends Notification
{
    public function __construct(private PlatformFeedback $feedback) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->feedback;
        $shop = $f->company?->name ?? 'loja';
        $who = $f->user ? "{$f->user->name} ({$f->user->email})" : 'Dono da loja';
        $title = PlatformFeedbackService::alertTitle($f);
        $app = rtrim((string) config('services.frontend_url'), '/');

        $mail = (new MailMessage)
            ->subject(trim(preg_replace('/^\X\s/u', '', $title)).($f->reason ? ': '.$f->reasonLabel() : ''))
            ->greeting(match ($f->kind) {
                PlatformFeedback::KIND_TRIAL_EXIT => 'Uma loja disse por que não assinou',
                PlatformFeedback::KIND_CANCEL_EXIT => 'Uma loja cancelou a assinatura',
                PlatformFeedback::KIND_SUPPORT_CSAT => 'Avaliação de atendimento',
                default => 'Nova resposta no NPS',
            })
            ->line("**Loja:** {$shop}")
            ->line("**Quem:** {$who}");

        if ($f->supportTicket) {
            $mail->line("**Chamado:** #{$f->supportTicket->id} — {$f->supportTicket->subject}");
        }
        if ($f->reason) {
            $mail->line("**Motivo:** {$f->reasonLabel()}");
        }
        if ($f->score !== null) {
            $mail->line('**Nota:** '.$f->score.' de '.(PlatformFeedback::scoreRange($f->kind)[1] ?? 10));
        }
        if ($f->comment) {
            $mail->line('**Comentário:**');
            foreach (preg_split("/\r?\n/", $f->comment) as $line) {
                if (trim($line) !== '') {
                    $mail->line($line);
                }
            }
        }

        return $mail
            ->action($f->supportTicket ? 'Abrir o chamado' : 'Ver na área admin', $app.($f->supportTicket ? "/admin/suporte/{$f->supportTicket->id}" : '/admin/opinioes'))
            ->salutation('Meu Custo Total');
    }
}
