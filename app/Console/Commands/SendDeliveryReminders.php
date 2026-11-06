<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Quote;
use App\Services\ProductionStageNotifier;
use App\Support\Delivery;
use Illuminate\Console\Command;

/**
 * Agenda: lembrete ao cliente na véspera da entrega (aviso "Lembrete na véspera da entrega", que a loja liga em
 * Integrações → Avisos ao cliente). Roda de hora em hora e só envia das 9h às 19h (Brasília); até 20 por loja por
 * rodada pra não disparar tudo de uma vez pelo WhatsApp da loja. Cada data marcada lembra uma vez.
 */
class SendDeliveryReminders extends Command
{
    protected $signature = 'deliveries:remind';

    protected $description = 'Manda o lembrete da véspera da entrega para os clientes (lojas que ligaram o aviso)';

    public function handle(ProductionStageNotifier $notifier): int
    {
        $hour = (int) now(Delivery::TZ)->format('G');
        if ($hour < 9 || $hour >= 19) {
            return self::SUCCESS;
        }
        $tomorrow = now(Delivery::TZ)->addDay()->toDateString();

        // Só lojas que ligaram o lembrete (o resto nem consulta pedidos)
        $companies = Company::whereNotNull('whatsapp_session_name')->get()
            ->filter(fn (Company $c) => $c->isPro() && (ProductionStageNotifier::settingsFor($c)['delivery_reminder']['enabled'] ?? false));

        foreach ($companies as $company) {
            $sent = 0;
            $quotes = $company->quotes()
                ->where('status', Quote::STATUS_APPROVED)
                ->whereDate('delivery_date', $tomorrow)
                ->where('production_status', '!=', Quote::PRODUCTION_DELIVERED)
                ->whereNull('paused_at')
                ->whereNotNull('customer_id')
                ->with(['customer', 'company', 'items'])
                ->limit(50)
                ->get();
            foreach ($quotes as $quote) {
                if ($sent >= 20) {
                    break;
                }
                if ($notifier->sendReminder($quote)) {
                    $sent++;
                }
            }
            if ($sent) {
                $this->line("Loja #{$company->id}: {$sent} lembrete(s) de entrega");
            }
        }

        return self::SUCCESS;
    }
}
