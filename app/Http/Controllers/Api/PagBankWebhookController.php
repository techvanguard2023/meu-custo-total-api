<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentCharge;
use App\Services\PagBank;
use App\Services\PagBankAccounts;
use App\Services\PixCharges;
use App\Support\Background;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pagbank — avisos do PagBank (pedidos do Pix e links pagos, de todas as lojas conectadas).
 *  - Confere a autenticidade: x-authenticity-token = SHA-256 (hex) de "{token}-{corpo bruto}" com o token da loja
 *    dona da cobrança (ou o da plataforma), ou o x-payload-signature (ECDSA, chave pública da plataforma). Sem
 *    nenhum dos dois, só fora de produção e sem token da plataforma (ambiente local).
 *  - Nunca confia no corpo: usa só para achar a cobrança e consulta o pedido no PagBank com o acesso da loja.
 *  - Link pago: o aviso traz o pedido ORDE_... criado pelo checkout; o id dele fica em meta.order_id (é por ele
 *    que a consulta e o estorno seguem). A cobrança é achada pelo reference_id "mct-{venda}-{cobrança}" que o
 *    sistema mandou, ou pelo x-product-id (CHEC_...).
 */
class PagBankWebhookController extends Controller
{
    public function __invoke(Request $request, PagBank $api)
    {
        $raw = $request->getContent();
        $body = json_decode($raw, true) ?: [];
        $id = (string) ($body['id'] ?? '');
        $charge = $this->findCharge($id, $body, (string) $request->header('x-product-id', ''));
        if (! $charge) {
            // Nada do sistema: não há o que conferir nem atualizar
            return response()->json(['ok' => true]);
        }

        $check = $this->authentic($request, $raw, $charge, $api);
        if ($check !== true) {
            Log::warning('PagBank: aviso recusado ('.$check.').');

            return response()->json(['ok' => false], $check === 'chave pública indisponível' ? 503 : 401);
        }

        // Link pago: guarda o pedido gerado por ele (a consulta e o estorno seguem por esse pedido)
        if (str_starts_with($id, 'ORDE_') && $charge->external_id !== $id && ($charge->meta['order_id'] ?? null) !== $id) {
            $charge->update(['meta' => array_merge($charge->meta ?? [], ['order_id' => $id])]);
        }
        $chargeId = $charge->id;
        Background::run(static fn () => app(PixCharges::class)->sync(PaymentCharge::findOrFail($chargeId), force: true));

        return response()->json(['ok' => true]);
    }

    /** true = aviso autêntico; senão, o motivo da recusa. */
    private function authentic(Request $request, string $raw, PaymentCharge $charge, PagBank $api): bool|string
    {
        $platform = (string) config('services.pagbank.platform_token');

        $hash = strtolower(trim((string) $request->header('x-authenticity-token', '')));
        if ($hash !== '') {
            $tokens = array_filter([$this->storeToken($charge), $platform]);
            foreach ($tokens as $token) {
                if (hash_equals(hash('sha256', $token.'-'.$raw), $hash)) {
                    return true;
                }
            }

            return 'x-authenticity-token não confere';
        }

        $signature = (string) $request->header('x-payload-signature', '');
        if ($signature !== '' && $platform !== '') {
            try {
                return $api->validSignature($signature, $raw) ?: 'assinatura inválida';
            } catch (\Throwable $e) {
                Log::warning('PagBank: chave pública dos avisos indisponível: '.$e->getMessage());

                return 'chave pública indisponível';
            }
        }

        return ! app()->isProduction() && $platform === '' ? true : 'sem assinatura';
    }

    /** Token da loja dona da cobrança (o mesmo que criou o pedido). */
    private function storeToken(PaymentCharge $charge): ?string
    {
        try {
            $company = \App\Models\Company::find($charge->company_id);

            return $company ? app(PagBankAccounts::class)->token($company) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findCharge(string $id, array $body, string $productId): ?PaymentCharge
    {
        $pagbank = PaymentCharge::where('provider', PagBankAccounts::PROVIDER);
        if ($id !== '' && ($charge = (clone $pagbank)->where('external_id', $id)->first())) {
            return $charge;
        }
        if (str_starts_with($productId, 'CHEC_') && ($charge = (clone $pagbank)->where('external_id', $productId)->first())) {
            return $charge;
        }

        // reference_id que o sistema mandou: "mct-{venda}-{cobrança}" (no pedido ou na cobrança dele)
        $references = array_filter([$body['reference_id'] ?? null, ...array_map(fn ($c) => $c['reference_id'] ?? null, $body['charges'] ?? [])]);
        foreach ($references as $reference) {
            if (preg_match('/^mct-(\d+)-(\d+)$/', (string) $reference, $m)) {
                $charge = (clone $pagbank)->where('id', (int) $m[2])->where('quote_id', (int) $m[1])->first();
                if ($charge) {
                    return $charge;
                }
            }
        }

        return null;
    }
}
