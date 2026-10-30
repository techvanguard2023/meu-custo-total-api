<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail do convite pra equipe: o link leva à tela onde a pessoa cria a própria senha. */
class TeamInvitation extends Notification
{
    public function __construct(private string $token, private User $invitedBy) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('services.frontend_url'), '/')
            .'/convite?token='.$this->token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        $company = $notifiable->company?->name;

        return (new MailMessage)
            ->subject("Convite para a equipe de {$company} — Meu Custo Total")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("{$this->invitedBy->name} convidou você para a equipe de {$company} no Meu Custo Total.")
            ->action('Aceitar convite e criar senha', $url)
            ->line('O convite vale por 7 dias.')
            ->line('Se você não esperava este convite, é só ignorar este e-mail.')
            ->salutation('Equipe Meu Custo Total');
    }
}
