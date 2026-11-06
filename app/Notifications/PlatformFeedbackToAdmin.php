<?php

namespace App\Notifications;

use App\Models\PlatformFeedback;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail pra plataforma: nota baixa no NPS, resposta com comentário ou "por que não assinou" no fim do teste. */
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

        $mail = (new MailMessage)
            ->subject($f->kind === PlatformFeedback::KIND_TRIAL_EXIT
                ? "Teste acabou sem assinar — {$shop}: {$f->reasonLabel()}"
                : "NPS {$f->score}/10 — {$shop}")
            ->greeting($f->kind === PlatformFeedback::KIND_TRIAL_EXIT ? 'Uma loja disse por que não assinou' : 'Nova resposta no NPS')
            ->line("**Loja:** {$shop}")
            ->line("**Quem:** {$who}");

        if ($f->kind === PlatformFeedback::KIND_TRIAL_EXIT) {
            $mail->line("**Motivo:** {$f->reasonLabel()}");
        } else {
            $mail->line("**Nota:** {$f->score} de 10");
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
            ->action('Ver na área admin', rtrim((string) config('services.frontend_url'), '/').'/admin/opinioes')
            ->salutation('Meu Custo Total');
    }
}
