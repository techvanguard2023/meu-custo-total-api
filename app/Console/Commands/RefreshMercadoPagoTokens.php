<?php

namespace App\Console\Commands;

use App\Models\PaymentAccount;
use App\Services\MercadoPagoAccounts;
use App\Services\PagBankAccounts;
use Illuminate\Console\Command;

/** Renova o acesso das contas do Mercado Pago que vencem em menos de 30 dias (o acesso vale 180). */
class RefreshMercadoPagoTokens extends Command
{
    protected $signature = 'mercadopago:refresh-tokens';

    protected $description = 'Renova o acesso das contas de pagamento online (Mercado Pago e PagBank) perto de vencer';

    public function handle(MercadoPagoAccounts $accounts, PagBankAccounts $pagbank): int
    {
        $due = PaymentAccount::whereNull('revoked_at')->whereNotNull('refresh_token')->where('expires_at', '<', now()->addDays(30))->get();
        foreach ($due as $account) {
            $account->provider === PagBankAccounts::PROVIDER ? $pagbank->refresh($account) : $accounts->refresh($account);
        }
        $this->info("{$due->count()} conta(s) verificada(s).");

        return self::SUCCESS;
    }
}
