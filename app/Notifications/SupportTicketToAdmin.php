<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail pra plataforma: chamado novo, ou a loja respondeu num chamado. */
class SupportTicketToAdmin extends Notification
{
    public function __construct(private SupportTicket $ticket, private SupportMessage $message, private bool $isNew) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->ticket;
        $company = $t->company?->name ?? 'loja';
        $who = $t->user ? "{$t->user->name} ({$t->user->email})" : 'Alguém da loja';

        $mail = (new MailMessage)
            ->subject(($this->isNew ? "Chamado novo #{$t->id}" : "Resposta no chamado #{$t->id}")." — {$company}: {$t->subject}")
            ->greeting($this->isNew ? 'Chamado novo de suporte' : 'A loja respondeu um chamado')
            ->line("**Loja:** {$company}")
            ->line("**Quem:** {$who}")
            ->line("**Tipo:** {$t->categoryLabel()}")
            ->line("**Assunto:** {$t->subject}")
            ->line('**Mensagem:**');

        foreach (preg_split("/\r?\n/", trim($this->message->body)) as $line) {
            if (trim($line) !== '') {
                $mail->line($line);
            }
        }

        if ($this->isNew && $t->page_url) {
            $mail->line("**Tela:** {$t->page_url}");
        }

        return $mail
            ->action('Abrir na área admin', rtrim(config('services.frontend_url'), '/')."/admin/suporte/{$t->id}")
            ->salutation('Meu Custo Total');
    }
}
