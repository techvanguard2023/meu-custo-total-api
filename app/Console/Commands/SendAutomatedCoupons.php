<?php

namespace App\Console\Commands;

use App\Services\CouponAutomations;
use Illuminate\Console\Command;

/** Envia os cupons automáticos pendentes — um por loja a cada minuto, das 9h às 20h no horário da loja. */
class SendAutomatedCoupons extends Command
{
    protected $signature = 'coupons:send-automated';

    protected $description = 'Envia aos poucos, pelo WhatsApp da loja, os cupons automáticos gerados';

    public function handle(CouponAutomations $automations): int
    {
        $sent = $automations->sendDue();
        if ($sent > 0) {
            $this->info("{$sent} cupom(ns) enviado(s).");
        }

        return self::SUCCESS;
    }
}
