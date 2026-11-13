<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use App\Support\Delivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Conta de teste da certificação da skill da Alexa: a Amazon testa dias depois do envio, então a conta precisa ter
 * sempre entregas "de hoje". Apaga só as vendas que este comando criou (external_reference) e cria de novo em volta
 * da data de hoje. Clientes sem telefone: o lembrete de véspera pelo WhatsApp nunca sai pra eles.
 */
class RefreshAlexaDemoDeliveries extends Command
{
    public const REFERENCE = 'alexa-demo';

    protected $signature = 'alexa:demo-deliveries {email? : conta de teste (padrão: ALEXA_DEMO_EMAIL)}';

    protected $description = 'Renova as entregas de demonstração da conta de teste da skill da Alexa';

    /** [dias a partir de hoje, hora, venda, cliente, forma de entrega, status de produção] */
    private const DEMO = [
        [0, '10:00', 'Kit 10 chaveiros personalizados', 'Ana Souza', Delivery::METHOD_PICKUP, Quote::PRODUCTION_FINISHED],
        [0, '15:30', 'Vaso geométrico branco', 'Bruno Lima', Delivery::METHOD_LOCAL, Quote::PRODUCTION_IN_PRODUCTION],
        [1, '09:00', 'Topo de bolo de aniversário', 'Carla Mendes', Delivery::METHOD_LOCAL, Quote::PRODUCTION_PENDING],
        [3, null, 'Caixa de lembrancinhas', 'Diego Rocha', Delivery::METHOD_SHIPPING, Quote::PRODUCTION_PENDING],
        [-1, '16:00', 'Luminária lua', 'Elisa Prado', Delivery::METHOD_LOCAL, Quote::PRODUCTION_FINISHED],
    ];

    public function handle(): int
    {
        $email = $this->argument('email') ?: config('services.alexa.demo_email');
        if (! $email) {
            $this->info('Sem conta de teste configurada (ALEXA_DEMO_EMAIL): nada a fazer.');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();
        $company = $user?->company;
        if (! $company) {
            $this->error("Conta {$email} não encontrada.");

            return self::FAILURE;
        }
        if (! $company->isPro()) {
            $this->warn("A loja {$company->name} não está no plano Pro: a Alexa vai responder sobre o plano.");
        }

        $today = now(Delivery::TZ)->startOfDay();

        DB::transaction(function () use ($company, $today) {
            $company->quotes()->where('external_reference', self::REFERENCE)->get()->each->delete();

            foreach (self::DEMO as [$days, $time, $name, $customerName, $method, $stage]) {
                $customer = Customer::firstOrCreate(['company_id' => $company->id, 'name' => $customerName]);

                $quote = new Quote;
                $quote->forceFill([
                    'company_id' => $company->id,
                    'customer_id' => $customer->id,
                    'name' => $name,
                    'quantity' => 1,
                    'print_time_minutes' => 0,
                    'material_weight_g' => 0,
                    'final_price' => 0,
                    'status' => Quote::STATUS_APPROVED,
                    'approved_at' => now(),
                    'production_status' => $stage,
                    'delivery_date' => $today->copy()->addDays($days)->toDateString(),
                    'delivery_time' => $time,
                    'delivery_method' => $method,
                    'external_reference' => self::REFERENCE,
                ])->save();
            }
        });

        $this->info('Entregas de demonstração renovadas para '.$company->name.'.');

        return self::SUCCESS;
    }
}
