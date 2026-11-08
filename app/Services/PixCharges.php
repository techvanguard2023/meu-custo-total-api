<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentCharge;
use App\Models\Quote;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cobranças online pelo Mercado Pago (API de Orders), sempre de vendas aprovadas:
 *  - Pix (QR + copia e cola, 24 h) ou link de pagamento (Checkout Pro: cartão parcelado ou Pix, 24 h);
 *  - o valor todo que falta ou uma parte (sinal); pode quitar mais de uma parte do mesmo pedido de uma vez
 *    (pronta entrega + sob encomenda do bot) — `allocations`;
 *  - quem gera: a loja (app), o bot (pedido do WhatsApp) ou o cliente ("Meus pedidos").
 * Quando o Mercado Pago confirma (aviso no webhook ou consulta), cada parte entra no registro de pagamentos da
 * sua venda e o cliente recebe o aviso de pagamento no WhatsApp. Estorno feito aqui ou no Mercado Pago desfaz.
 */
class PixCharges
{
    public const VALIDITY = 'PT24H';

    public const VALIDITY_HOURS = 24;

    public const MIN_AMOUNT = 1.0;

    /** Pix exige e-mail do pagador; sem o do cliente, vai este (o banco do pagador é quem identifica quem pagou). */
    private const FALLBACK_PAYER_EMAIL = 'pagamentos@meucustototal.com.br';

    /** Consultar o Mercado Pago no máximo a cada N segundos por cobrança (a tela consulta enquanto está aberta). */
    private const SYNC_EVERY_SECONDS = 8;

    public function __construct(private MercadoPago $api, private MercadoPagoAccounts $accounts) {}

    /** Pode cobrar online esta venda? null = pode; senão, o motivo (frase para a tela). */
    public function refusal(Quote $quote): ?string
    {
        return match (true) {
            ! $quote->company->isPro() => 'O pagamento online (Mercado Pago) é do plano Pro.',
            $quote->status !== Quote::STATUS_APPROVED || $quote->cancelled_at !== null => 'Só dá para cobrar online venda aprovada.',
            (bool) $quote->is_courtesy => 'Cortesia não tem valor a receber.',
            $quote->sales_channel_id !== null || $quote->display_id !== null => 'Venda de marketplace ou de expositor é recebida por lá.',
            $quote->amount_due < self::MIN_AMOUNT => 'Esta venda não tem valor a receber.',
            ! $this->accounts->ready($quote->company) => 'Conecte a conta do Mercado Pago em Integrações para cobrar online.',
            default => null,
        };
    }

    /** A loja cobra uma venda: Pix ou link, do valor escolhido (até o que falta). */
    public function create(Quote $quote, float $amount, ?int $userId, string $method = PaymentCharge::METHOD_PIX): PaymentCharge
    {
        $quote->loadMissing(['company', 'customer']);
        if ($why = $this->refusal($quote)) {
            throw new RuntimeException($why);
        }
        $amount = round($amount, 2);
        if ($amount > $quote->amount_due + 0.001) {
            throw new RuntimeException('O valor passa do que falta receber (R$ '.number_format($quote->amount_due, 2, ',', '.').').');
        }

        return $this->createFor($quote->company, [$quote->id => $amount], $method, 'app', $userId);
    }

    /**
     * Cobra várias partes de um pedido de uma vez ([quote_id => valor]). Partes sem valor a receber ficam de fora.
     * Devolve a cobrança aberta igual, se já existir (mesmo método, mesmas partes e valores).
     */
    public function createFor(Company $company, array $parts, string $method, string $origin, ?int $userId = null): PaymentCharge
    {
        $quotes = Quote::where('company_id', $company->id)->whereIn('id', array_keys($parts))->with(['company', 'customer'])->orderBy('id')->get()->keyBy('id');
        $parts = collect($parts)
            ->map(fn ($amount, $id) => isset($quotes[$id]) ? round(min((float) $amount, $quotes[$id]->amount_due), 2) : 0)
            ->filter(fn ($amount, $id) => $amount > 0 && $this->refusal($quotes[$id]) === null)
            ->all();
        if (! $parts) {
            throw new RuntimeException($quotes->isNotEmpty() ? ($this->refusal($quotes->first()) ?? 'Não há valor a receber.') : 'Venda não encontrada.');
        }
        $total = round(array_sum($parts), 2);
        if ($total < self::MIN_AMOUNT) {
            throw new RuntimeException('O valor mínimo é R$ '.number_format(self::MIN_AMOUNT, 2, ',', '.').'.');
        }
        $primary = $quotes[array_key_first($parts)];
        $allocations = count($parts) > 1 ? collect($parts)->map(fn ($amount, $id) => ['quote_id' => (int) $id, 'amount' => $amount])->values()->all() : null;

        $open = PaymentCharge::where('quote_id', $primary->id)->where('method', $method)->where('status', PaymentCharge::STATUS_PENDING)
            ->where('amount', $total)->where('expires_at', '>', now()->addMinutes(30))->latest('id')->get()
            ->first(fn (PaymentCharge $c) => $c->parts() == array_map('floatval', $parts));
        if ($open) {
            return $open;
        }

        $token = $this->accounts->token($company);
        $charge = PaymentCharge::create([
            'company_id' => $company->id,
            'quote_id' => $primary->id,
            'method' => $method,
            'origin' => $origin,
            'amount' => $total,
            'allocations' => $allocations,
            'status' => PaymentCharge::STATUS_PENDING,
            'expires_at' => now()->addHours(self::VALIDITY_HOURS),
            'user_id' => $userId,
        ]);

        $label = collect(array_keys($parts))->map(fn ($id) => "#{$id}")->implode(' + ');
        try {
            if ($method === PaymentCharge::METHOD_LINK) {
                $order = $this->api->createCheckoutOrder($token, $total, "Pedido {$label} — {$company->name}", "mct-{$primary->id}-{$charge->id}", $this->returnUrl($company), "mct-charge-{$charge->id}");
            } else {
                $email = $primary->customer?->email && filter_var($primary->customer->email, FILTER_VALIDATE_EMAIL) ? $primary->customer->email : self::FALLBACK_PAYER_EMAIL;
                $order = $this->api->createPixOrder($token, $total, "mct-{$primary->id}-{$charge->id}", $email, self::VALIDITY, "mct-charge-{$charge->id}");
            }
        } catch (\Throwable $e) {
            $charge->delete();
            $this->handleApiError($company, $primary->id, $e);
            throw new RuntimeException('O Mercado Pago não gerou a cobrança agora. Tente de novo em instantes.');
        }

        $payment = $order['transactions']['payments'][0] ?? [];
        $url = $method === PaymentCharge::METHOD_LINK ? ($order['checkout_url'] ?? null) : ($payment['payment_method']['ticket_url'] ?? null);
        $charge->update([
            'external_id' => $order['id'] ?? null,
            'external_payment_id' => $payment['id'] ?? null,
            'provider_status' => trim(($order['status'] ?? '').'/'.($order['status_detail'] ?? ''), '/'),
            'qr_code' => $payment['payment_method']['qr_code'] ?? null,
            'ticket_url' => $url ? Str::limit($url, 500, '') : null,
            'synced_at' => now(),
        ]);
        if ($method === PaymentCharge::METHOD_PIX && ! $charge->qr_code || $method === PaymentCharge::METHOD_LINK && ! $charge->ticket_url) {
            Log::warning("Mercado Pago: cobrança sem QR/link (venda {$primary->id}, pedido {$charge->external_id})");
        }

        return $charge->fresh();
    }

    /**
     * Cobranças que o bot manda no pedido do WhatsApp, pela regra da loja (Integrações → Mercado Pago): valor total,
     * ou sinal de X% nas partes sob encomenda (pronta entrega sempre inteira), ou nada. Gera o Pix e o link de
     * cartão (crédito ou débito) do mesmo valor; o que o cliente pagar primeiro cancela o outro (ver apply()).
     * Vazio = sem cobrança online (regra desligada, loja sem conta conectada ou o Mercado Pago falhou — o pedido
     * segue normal).
     *
     * @param  Collection<int, Quote>  $quotes
     * @return Collection<int, PaymentCharge>
     */
    public function forBotOrder(Company $company, Collection $quotes): Collection
    {
        $rules = self::rules($company);
        if ($rules['bot_pix'] === 'off' || ! $company->isPro() || ! $this->accounts->ready($company)) {
            return collect();
        }
        $parts = [];
        foreach ($quotes as $quote) {
            $quote->setRelation('company', $company);
            $due = $quote->amount_due;
            $deposit = $rules['bot_pix'] === 'deposit' && $quote->production_status === Quote::PRODUCTION_PENDING;
            $parts[$quote->id] = $deposit ? round($due * $rules['deposit_percent'] / 100, 2) : $due;
        }

        $charges = collect();
        foreach ([PaymentCharge::METHOD_PIX, PaymentCharge::METHOD_LINK] as $method) {
            try {
                $charges->push($this->createFor($company, $parts, $method, 'bot'));
            } catch (\Throwable $e) {
                Log::info("Pedido do bot sem {$method} (loja {$company->id}): ".Str::limit($e->getMessage(), 200));
            }
        }

        return $charges;
    }

    /**
     * Cobranças do bot ainda abertas destas vendas (a mais recente de cada forma).
     *
     * @return Collection<string, PaymentCharge> chaves: pix, link
     */
    public function openBotCharges(Collection $quoteIds): Collection
    {
        return PaymentCharge::whereIn('quote_id', $quoteIds)->where('origin', 'bot')->where('status', PaymentCharge::STATUS_PENDING)
            ->latest('id')->get()
            ->filter(fn (PaymentCharge $c) => $c->isOpen() && ($c->method === PaymentCharge::METHOD_LINK ? $c->ticket_url : $c->qr_code))
            ->unique('method')
            ->keyBy('method');
    }

    /**
     * Resumo do pagamento para o bot escrever a resposta — sem o código Pix nem links: esses vão em mensagens
     * separadas (botMessages), para o cliente copiar só o código. null = sem cobrança online aberta.
     */
    public function botSummary(Collection $charges): ?array
    {
        $first = $charges->first();
        if (! $first) {
            return null;
        }
        $due = round(Quote::whereIn('id', array_keys($first->parts()))->get()->sum(fn (Quote $q) => $q->amount_due), 2);
        $amount = (float) $first->amount;
        $deposit = $amount + 0.009 < $due;
        $fmt = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $ways = collect([$charges->has('pix') ? 'Pix' : null, $charges->has('link') ? 'cartão de crédito ou débito' : null])->filter()->implode(' ou ');
        $sending = collect([$charges->has('pix') ? 'o QR Code e o código Pix copia e cola' : null, $charges->has('link') ? 'o link do cartão' : null])->filter()->implode(' e ');

        return [
            'amount' => $amount,
            'deposit' => $deposit,
            'remaining_after' => $deposit ? round($due - $amount, 2) : 0.0,
            'pix' => $charges->has('pix'),
            'card' => $charges->has('link'),
            'expires_at' => $first->expires_at?->toIso8601String(),
            'message' => ($deposit
                ? "Para confirmar o pedido, pague o sinal de {$fmt($amount)} (o restante, {$fmt($due - $amount)}, fica para depois)"
                : "Para confirmar o pedido, pague {$fmt($amount)}")
                ." por {$ways}. Logo abaixo envio {$sending}. Assim que o pagamento cair, eu te confirmo por aqui.",
            'instructions_for_bot' => 'NÃO escreva o código Pix, o QR Code nem links de pagamento na sua resposta: o sistema envia cada um em mensagem separada logo depois da sua. Use o texto de message para falar do pagamento e não mande as formas de pagamento manuais da loja.',
        ];
    }

    /**
     * Mensagens prontas que o n8n envia depois da resposta do bot, nesta ordem: QR Code (imagem), o código Pix
     * sozinho (fácil de copiar) e o link do cartão.
     *
     * @return list<array{type: string, text?: string, data?: string, mimetype?: string, filename?: string, caption?: string}>
     */
    public function botMessages(Collection $charges): array
    {
        $messages = [];
        $fmt = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
        if ($pix = $charges->get('pix')) {
            $until = $pix->expires_at->copy()->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');
            $messages[] = [
                'type' => 'image',
                'data' => base64_encode($this->qrPng($pix->qr_code)),
                'mimetype' => 'image/png',
                'filename' => 'pix.png',
                'caption' => "Pix de {$fmt($pix->amount)}: aponte a câmera do app do banco para o QR Code ou copie o código da próxima mensagem (Pix → Copia e Cola). Vale até {$until}.",
            ];
            $messages[] = ['type' => 'text', 'text' => $pix->qr_code];
        }
        if ($link = $charges->get('link')) {
            $messages[] = ['type' => 'text', 'text' => "💳 Prefere cartão de crédito ou débito? Pague {$fmt($link->amount)} por este link do Mercado Pago:\n{$link->ticket_url}"];
        }

        return $messages;
    }

    /** Regra do Pix do bot ({bot_pix: total|deposit|off, deposit_percent}). Padrão: valor total. */
    public static function rules(Company $company): array
    {
        $rules = $company->setting?->payment_rules ?? [];

        return [
            'bot_pix' => in_array($rules['bot_pix'] ?? null, ['total', 'deposit', 'off'], true) ? $rules['bot_pix'] : 'total',
            'deposit_percent' => max(10, min(90, (int) ($rules['deposit_percent'] ?? 50))),
        ];
    }

    /** Consulta o Mercado Pago e aplica a situação. $force ignora o intervalo mínimo (aviso do webhook). */
    public function sync(PaymentCharge $charge, bool $force = false): PaymentCharge
    {
        if (! $charge->external_id || in_array($charge->status, [PaymentCharge::STATUS_CANCELED, PaymentCharge::STATUS_FAILED, PaymentCharge::STATUS_REFUNDED], true)) {
            return $charge;
        }
        if (! $force && $charge->synced_at && $charge->synced_at->gt(now()->subSeconds(self::SYNC_EVERY_SECONDS))) {
            return $charge;
        }
        $company = Company::find($charge->company_id);
        try {
            $order = $this->api->getOrder($this->accounts->token($company), $charge->external_id);
        } catch (\Throwable $e) {
            $charge->update(['synced_at' => now()]);
            $this->handleApiError($company, $charge->quote_id, $e);

            return $charge;
        }

        return $this->apply($charge, $order);
    }

    /** Aplica a situação do pedido do Mercado Pago (com trava: aviso e consulta ao mesmo tempo não pagam duas vezes). */
    public function apply(PaymentCharge $charge, array $order): PaymentCharge
    {
        $status = (string) ($order['status'] ?? '');
        $detail = (string) ($order['status_detail'] ?? '');

        $becamePaid = false;
        DB::transaction(function () use ($charge, $order, $status, $detail, &$becamePaid) {
            $locked = PaymentCharge::lockForUpdate()->find($charge->id);
            $payment = $order['transactions']['payments'][0] ?? [];
            $paymentId = (string) ($payment['id'] ?? $locked->external_payment_id ?? $locked->external_id);
            $changes = ['provider_status' => trim("{$status}/{$detail}", '/'), 'synced_at' => now()];
            if (! empty($payment['id'])) {
                $changes['external_payment_id'] = (string) $payment['id'];
            }

            $refunded = $status === 'refunded' || ($status === 'processed' && $detail === 'refunded') || $status === 'charged_back';
            $paid = ! $refunded && $status === 'processed';
            $parts = $locked->parts();
            $multi = count($parts) > 1;

            if ($paid && $locked->status !== PaymentCharge::STATUS_PAID) {
                $method = $this->paymentMethod($payment, $locked->method);
                $label = $locked->method === PaymentCharge::METHOD_LINK ? 'Link do Mercado Pago' : 'Pix pelo Mercado Pago';
                foreach ($parts as $quoteId => $amount) {
                    if ($quote = Quote::find($quoteId)) {
                        app(QuotePayments::class)->addOnline($quote, $amount, $method, $paymentId.($multi ? ":{$quoteId}" : ''), "{$label} (#{$locked->id})");
                    }
                }
                $changes += ['status' => PaymentCharge::STATUS_PAID, 'paid_at' => now()];
                $becamePaid = true;
            } elseif ($refunded && $locked->status === PaymentCharge::STATUS_PAID) {
                foreach ($parts as $quoteId => $amount) {
                    if ($quote = Quote::find($quoteId)) {
                        app(QuotePayments::class)->removeOnline($quote, $amount, "refund:{$paymentId}".($multi ? ":{$quoteId}" : ''), "Estornado no Mercado Pago (#{$locked->id})");
                    }
                }
                $changes['status'] = PaymentCharge::STATUS_REFUNDED;
            } elseif (! $paid && ! $refunded && $locked->status === PaymentCharge::STATUS_PENDING && in_array($status, ['canceled', 'failed', 'expired'], true)) {
                // Link: tentativa de cartão recusada não encerra o link (o cliente pode tentar de novo); só o cancelamento/validade
                if (! ($locked->method === PaymentCharge::METHOD_LINK && $status === 'failed')) {
                    $changes['status'] = $detail === 'expired' || $status === 'expired'
                        ? PaymentCharge::STATUS_EXPIRED
                        : ($status === 'failed' ? PaymentCharge::STATUS_FAILED : PaymentCharge::STATUS_CANCELED);
                }
            }
            $locked->update($changes);
        });

        if ($becamePaid) {
            $this->cancelSiblings($charge->fresh());
        }

        return $charge->fresh();
    }

    /**
     * Pagou por um caminho (ex.: Pix do bot): as outras cobranças abertas das mesmas vendas (ex.: o link do cartão
     * mandado junto) são canceladas no Mercado Pago, para o cliente não pagar duas vezes.
     */
    private function cancelSiblings(PaymentCharge $paid): void
    {
        $quoteIds = array_keys($paid->parts());
        $others = PaymentCharge::where('company_id', $paid->company_id)->where('id', '!=', $paid->id)
            ->where('status', PaymentCharge::STATUS_PENDING)->get()
            ->filter(fn (PaymentCharge $c) => array_intersect(array_keys($c->parts()), $quoteIds));
        if ($others->isEmpty()) {
            return;
        }
        $company = Company::find($paid->company_id);
        foreach ($others as $other) {
            try {
                if ($other->external_id) {
                    $this->api->cancelOrder($this->accounts->token($company), $other->external_id);
                }
                $other->update(['status' => PaymentCharge::STATUS_CANCELED]);
            } catch (\Throwable $e) {
                Log::warning("Mercado Pago: cobrança #{$other->id} não cancelada depois do pagamento da #{$paid->id}: ".Str::limit($e->getMessage(), 200));
            }
        }
    }

    /** Estorno pelo sistema (só cobrança paga): pede ao Mercado Pago e aplica a resposta na hora. */
    public function refund(PaymentCharge $charge): PaymentCharge
    {
        if ($charge->status !== PaymentCharge::STATUS_PAID || ! $charge->external_id) {
            throw new RuntimeException('Só dá para estornar um pagamento já recebido pelo Mercado Pago.');
        }
        $company = Company::find($charge->company_id);
        try {
            $this->api->refundOrder($this->accounts->token($company), $charge->external_id);
            $order = $this->api->getOrder($this->accounts->token($company), $charge->external_id);
        } catch (\Throwable $e) {
            $this->handleApiError($company, $charge->quote_id, $e);
            throw new RuntimeException('O Mercado Pago não fez o estorno agora. Tente de novo ou estorne pelo app do Mercado Pago.');
        }

        // O Mercado Pago aceitou o estorno; a consulta logo depois pode ainda não mostrar — aplica como estornado
        if (($order['status'] ?? '') !== 'refunded' && ($order['status_detail'] ?? '') !== 'refunded') {
            $order['status'] = 'refunded';
            $order['status_detail'] = 'refunded';
        }

        return $this->apply($charge, $order);
    }

    /** O que a tela recebe (sem nada do Mercado Pago além do Pix/link em si). */
    public function present(PaymentCharge $charge): array
    {
        $open = $charge->isOpen();
        $isLink = $charge->method === PaymentCharge::METHOD_LINK;

        return [
            'id' => $charge->id,
            'quote_id' => $charge->quote_id,
            'method' => $charge->method,
            'origin' => $charge->origin,
            'amount' => (float) $charge->amount,
            'quote_ids' => array_keys($charge->parts()),
            'status' => $charge->status === PaymentCharge::STATUS_PENDING && ! $open ? PaymentCharge::STATUS_EXPIRED : $charge->status,
            'open' => $open,
            'qr_code' => $open && ! $isLink ? $charge->qr_code : null,
            'qr_svg' => $open && ! $isLink && $charge->qr_code ? $this->qrSvg($charge->qr_code) : null,
            'checkout_url' => $open && $isLink ? $charge->ticket_url : null,
            'expires_at' => $charge->expires_at?->toIso8601String(),
            'paid_at' => $charge->paid_at?->toIso8601String(),
            'created_at' => $charge->created_at?->toIso8601String(),
        ];
    }

    /** QR Code do "copia e cola" como imagem SVG (data URI) — gerado aqui, não guardado. */
    public function qrSvg(string $code): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280, 1), new SvgImageBackEnd)))->writeString($code);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** QR Code do "copia e cola" em PNG (para mandar como imagem no WhatsApp). */
    public function qrPng(string $code): string
    {
        return (new Writer(new GDLibRenderer(480, 2)))->writeString($code);
    }

    /** Forma do pagamento no registro: cartão (crédito/débito) ou Pix, conforme o que o cliente usou. */
    private function paymentMethod(array $payment, string $chargeMethod): string
    {
        $type = (string) ($payment['payment_method']['type'] ?? '');
        $id = (string) ($payment['payment_method']['id'] ?? '');

        return match (true) {
            $type === 'credit_card' => Quote::PAYMENT_CREDIT,
            in_array($type, ['debit_card', 'prepaid_card'], true) => Quote::PAYMENT_DEBIT,
            $id === 'pix' || $type === 'bank_transfer' => Quote::PAYMENT_PIX,
            default => $chargeMethod === PaymentCharge::METHOD_LINK ? Quote::PAYMENT_CREDIT : Quote::PAYMENT_PIX,
        };
    }

    /** Volta do link pago: o "Meus pedidos" da loja (ou o catálogo). */
    private function returnUrl(Company $company): ?string
    {
        return $company->hasCatalogActive() ? rtrim($company->catalogUrl(), '/').'/meus-pedidos?pagamento=1' : null;
    }

    /** Acesso recusado (401): a loja revogou no Mercado Pago — marca para conectar de novo. */
    private function handleApiError(?Company $company, int $quoteId, \Throwable $e): void
    {
        Log::warning("Mercado Pago (venda {$quoteId}): ".Str::limit($e->getMessage(), 300));
        if ($company && $e instanceof MercadoPagoException && $e->getCode() === 401 && ($account = $this->accounts->account($company))) {
            $this->accounts->revokeByProviderUser($account->provider_user_id);
        }
    }
}
