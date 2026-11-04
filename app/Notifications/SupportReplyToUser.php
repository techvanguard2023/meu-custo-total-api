<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** E-mail pra quem abriu o chamado: o suporte respondeu. */
class SupportReplyToUser extends Notification
{
    public function __construct(private SupportTicket $ticket, private SupportMessage $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->ticket;
        $resolved = $t->status === SupportTicket::STATUS_RESOLVED;

        $mail = (new MailMessage)
            ->subject("Resposta do suporte — chamado #{$t->id}: {$t->subject}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("O suporte do Meu Custo Total respondeu o seu chamado **#{$t->id} — {$t->subject}**:");

        foreach (preg_split("/\r?\n/", trim(Str::limit($this->message->body, 2000))) as $line) {
            if (trim($line) !== '') {
                $mail->line($line);
            }
        }

        return $mail
            ->line($resolved
                ? 'Marcamos o chamado como resolvido. Se ainda precisar de ajuda, é só responder por lá que ele volta a ficar aberto.'
                : 'Para responder, abra o chamado no app (por e-mail a resposta não chega até nós).')
            ->action('Ver o chamado', rtrim(config('services.frontend_url'), '/')."/suporte/{$t->id}")
            ->salutation('Equipe Meu Custo Total');
    }
}
