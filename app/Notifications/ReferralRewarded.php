<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Indique e ganhe: a loja indicada assinou e a que indicou ganhou 1 mês (crédito na mensalidade ou cortesia Pro). */
class ReferralRewarded extends Notification
{
    /** @param array{type: string, amount?: float, until?: string} $reward */
    public function __construct(private string $shopName, private string $referredName, private array $reward) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = rtrim((string) config('services.frontend_url'), '/');
        $what = $this->reward['type'] === 'credit'
            ? 'um **crédito de R$ '.number_format((float) $this->reward['amount'], 2, ',', '.').'** que abate da sua próxima mensalidade'
            : '**1 mês de Pro grátis**, até '.Carbon::parse($this->reward['until'])->format('d/m/Y');

        return (new MailMessage)
            ->subject('Sua indicação deu certo: você ganhou 1 mês 🎉')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A **{$this->referredName}** assinou o Meu Custo Total pela indicação da **{$this->shopName}**.")
            ->line("Como agradecimento, você ganhou {$what}.")
            ->line('Seu link de indicação continua valendo — cada loja que assinar por ele vale mais 1 mês.')
            ->action('Ver minhas indicações', $app.'/indique')
            ->salutation('Equipe Meu Custo Total');
    }
}
