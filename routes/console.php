<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mantém a tabela de visitas do catálogo enxuta (só roda se o scheduler estiver ativo no servidor)
Schedule::command('catalog:prune-visits')->weeklyOn(1, '03:00');

// Rede de segurança: promove para Pro quem pagou mas ficou preso em "free" por um
// webhook da Stripe perdido/falho. Nunca rebaixa (--downgrade não é usado aqui).
Schedule::command('plan:sync')->everyFifteenMinutes();

// Cashback: o saldo já ignora crédito vencido; isto só registra o vencimento no extrato do cliente
Schedule::command('cashback:expire')->dailyAt('03:30');

// LGPD: apaga de vez as contas com exclusão pedida cujo prazo pra desistir já passou
Schedule::command('account:purge-deleted')->dailyAt('04:00');

// A trava do withoutOverlapping fica no cache (banco) e sobrevive a um deploy: se o container cai no meio de um
// comando, ela segura o comando por 24h (padrão). Nos comandos de minuto, a trava vence em poucos minutos.

// Avisos de cashback em massa (crédito retroativo): um por loja a cada minuto, pra não arriscar o WhatsApp
Schedule::command('cashback:send-notifications')->everyMinute()->withoutOverlapping(5);

// Ofertas (Pro): a agenda põe os produtos do rodízio na fila; o envio sai uma entrega por loja por minuto;
// e a cada 15 min abre o próximo grupo quando o atual lota
Schedule::command('offers:schedule')->everyMinute()->withoutOverlapping(5);
Schedule::command('offers:send')->everyMinute()->withoutOverlapping(5);
Schedule::command('offers:check-groups')->everyFifteenMinutes()->withoutOverlapping(14);

// Sessões de login sem uso há 60 dias e tokens vencidos (ex: "Entrar como") são apagados
Schedule::command('tokens:prune')->dailyAt('03:45');

// Área admin: cortesias vencidas voltam pro plano da assinatura
Schedule::command('admin:expire-comps')->dailyAt('03:15');
// Teste grátis do Pro: no fim, a loja volta pro plano da assinatura (ou Grátis)
Schedule::command('trial:expire')->hourly()->withoutOverlapping(10);
// Mensagens do teste (e-mail; "faltam 3 dias" e "acabou" também no WhatsApp, das 9h às 20h)
Schedule::command('trial:notify')->everyFifteenMinutes()->withoutOverlapping(14);
// Indique e ganhe: indicada que pagou a primeira vez → 1 mês de Pro pra quem indicou
Schedule::command('referrals:reward')->hourlyAt(20)->withoutOverlapping(30);
// Agenda: lembrete ao cliente na véspera da entrega (só lojas que ligaram o aviso; das 9h às 19h)
Schedule::command('deliveries:remind')->hourlyAt(5)->withoutOverlapping(30);
// Pagamento online: renova o acesso das contas do Mercado Pago perto de vencer (vale 180 dias)
Schedule::command('mercadopago:refresh-tokens')->dailyAt('04:20')->withoutOverlapping(30);
// Financeiro da área admin: cobranças da Stripe, tarifas, custo da OpenAI e cotação do dólar
Schedule::command('admin:sync-finance')->dailyAt('04:30')->withoutOverlapping(30);

// Cupom de reativação (Pro): gera os do dia às 8h e envia aos poucos (1 por loja por minuto, das 9h às 20h)
Schedule::command('coupons:reactivation')->dailyAt('08:00')->timezone('America/Sao_Paulo')->withoutOverlapping(30);
Schedule::command('coupons:send-automated')->everyMinute()->withoutOverlapping(5);
