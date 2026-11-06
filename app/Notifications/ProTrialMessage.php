<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\PlatformFeedback;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mails do teste grátis do Pro (ver App\Services\ProTrialNotifier): boas-vindas, 3º dia, meio do teste,
 * "faltam 3 dias" e "acabou" (com a oferta de 30%).
 */
class ProTrialMessage extends Notification
{
    /** @param array{until: string, days_left: int, total_days: int, offer_until: ?string, offer_label: ?string, domain: ?string, feedback_url?: string} $info */
    public function __construct(private string $stage, private Company $company, private array $info) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = rtrim((string) config('services.frontend_url'), '/');
        $shop = $this->company->name;
        $until = $this->info['until'];
        $mail = (new MailMessage)->greeting("Olá, {$notifiable->name}!")->salutation('Equipe Meu Custo Total');

        return match ($this->stage) {
            'welcome' => $this->company->pro_trial_source === 'campaign'
                ? $mail->subject("Presente: {$this->info['total_days']} dias do plano Pro para a {$shop} 🎁")
                    ->line("Liberamos o **plano Pro** para a **{$shop}** até **{$until}**, sem custo e sem cartão.")
                    ->line('Aproveite para experimentar o que o Pro faz:')
                    ->line('• **Bot no WhatsApp** que atende, mostra os produtos e fecha pedidos sozinho')
                    ->line('• **Ofertas automáticas** nos seus grupos e canal do WhatsApp')
                    ->line('• **Cupons e cashback** que trazem o cliente de volta')
                    ->line('• **Catálogo completo**, sem limite de produtos e clientes')
                    ->action('Abrir o Meu Custo Total', $app.'/')
                    ->line('No fim do teste a loja volta para o plano Grátis — nada é apagado e nada é cobrado.')
                : $mail->subject('Seu Pro grátis por 14 dias começou 🎉')
                    ->line("A **{$shop}** já está no **plano Pro até {$until}**, sem cartão e sem compromisso.")
                    ->line('Para aproveitar desde o primeiro dia:')
                    ->line('1. **Informe seus custos** (energia, hora de trabalho e margem) em Configurações')
                    ->line('2. **Cadastre seus produtos** e monte o catálogo')
                    ->line('3. **Conecte o WhatsApp** e ligue o bot de atendimento')
                    ->action('Começar agora', $app.'/')
                    ->line('No fim do teste a loja volta para o plano Grátis — nada é apagado e nada é cobrado.'),

            'day3' => $mail->subject('Já calculou o preço certo da sua primeira peça?')
                ->line('Em 2 minutos você descobre quanto cada peça custa de verdade — material, energia, mão de obra, desgaste e taxas — e quanto cobrar para ter lucro.')
                ->line('Muita gente descobre aqui que estava cobrando barato demais.')
                ->action('Fazer meu primeiro orçamento', $app.'/quotes/new')
                ->line("Seu teste do Pro vai até {$until}."),

            'mid' => $mail->subject("O que o Pro faz pela {$shop} (faltam {$this->info['days_left']} dias)")
                ->line('Você está na metade do teste. Já experimentou estes recursos?')
                ->line('• **Bot no WhatsApp**: responde clientes, mostra o catálogo e registra pedidos, até de madrugada')
                ->line('• **Ofertas nos grupos**: seus produtos com foto, preço e link, no horário que você escolher')
                ->line('• **Cupons e cashback**: o cliente ganha crédito e volta a comprar')
                ->line('• **Painel de produção**: cada pedido do orçamento à entrega')
                ->action('Conectar o WhatsApp', $app.'/integrations')
                ->line("Seu teste do Pro vai até {$until}."),

            'ending' => $mail->subject($this->info['days_left'] <= 1 ? 'Hoje é o último dia do seu teste do Pro' : "Seu teste do Pro acaba em {$this->info['days_left']} dias")
                ->line("Em **{$until}** a **{$shop}** volta para o plano Grátis.")
                ->line('Seus dados continuam todos lá, mas param de funcionar: bot do WhatsApp, ofertas nos grupos, cupons e cashback'.($this->info['domain'] ? ", e o domínio próprio ({$this->info['domain']}) é desligado" : '').'.')
                ->line($this->info['days_left'] >= 3
                    ? "Assine agora e **só pague a partir de {$until}** — sem perder os dias que faltam."
                    : 'Assine para continuar com tudo funcionando, sem interrupção.')
                ->action('Ver os planos', $app.'/plans'),

            'ended' => tap($mail->subject($this->info['offer_label']
                    ? 'Seu teste do Pro terminou — 30% de desconto para continuar'
                    : 'Seu teste do Pro terminou')
                ->line("O teste terminou e a **{$shop}** voltou para o plano Grátis. Seus dados continuam todos aqui."), function (MailMessage $m) use ($app) {
                    if ($this->info['offer_label']) {
                        $m->line("Para voltar ao Pro, você tem **{$this->info['offer_label']}** se assinar até **{$this->info['offer_until']}** — já aplicado, sem código.")
                            ->action('Assinar com desconto', $app.'/plans');
                    } else {
                        $m->action('Ver os planos', $app.'/plans');
                    }
                    // "O que pesou?" em 1 clique: cada motivo abre /opiniao/{token} já marcado (confirma lá, com comentário opcional)
                    if (! empty($this->info['feedback_url'])) {
                        $m->line('**Se não for continuar, pode contar o que pesou?** É um clique e nos ajuda muito a melhorar:');
                        foreach (PlatformFeedback::TRIAL_EXIT_REASONS as $reason => $label) {
                            $m->line("• [{$label}]({$this->info['feedback_url']}?r={$reason})");
                        }
                    }
                }),

            default => $mail->subject('Meu Custo Total')->line('Acesse o app para ver as novidades.')->action('Abrir', $app.'/'),
        };
    }
}
