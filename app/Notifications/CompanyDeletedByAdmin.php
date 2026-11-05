<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao dono quando a equipe do Meu Custo Total exclui a loja pela área admin: agendada (com a data em que
 * será apagada) ou já apagada. Só dados simples — no "excluir agora" a loja já não existe quando o e-mail sai.
 */
class CompanyDeletedByAdmin extends Notification
{
    public function __construct(
        private string $shopName,
        private string $ownerName,
        private bool $immediate,
        private ?string $date = null,
        private ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Olá, {$this->ownerName}!")
            ->salutation('Equipe Meu Custo Total');

        if ($this->immediate) {
            $mail->subject("A conta {$this->shopName} foi excluída")
                ->line("A conta **{$this->shopName}** no Meu Custo Total foi excluída pela nossa equipe, junto com os dados dela.");
        } else {
            $mail->subject("A conta {$this->shopName} será excluída em {$this->date}")
                ->line("A conta **{$this->shopName}** no Meu Custo Total foi desativada pela nossa equipe e será excluída de vez em **{$this->date}**, junto com os dados dela.")
                ->line('Até lá o sistema, o catálogo e o atendimento pelo WhatsApp ficam fora do ar. Ainda dá pra entrar e baixar os seus dados.');
        }
        if ($this->reason) {
            $mail->line("Motivo: {$this->reason}");
        }

        return $mail->line('Se acha que foi um engano, responda este e-mail que a gente verifica.');
    }
}
