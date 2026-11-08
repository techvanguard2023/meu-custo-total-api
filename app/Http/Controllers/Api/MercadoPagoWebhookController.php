<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\PaymentCharge;
use App\Services\MercadoPago;
use App\Services\MercadoPagoAccounts;
use App\Services\PixCharges;
use App\Support\Background;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/mercadopago — avisos do Mercado Pago para o app da plataforma (todas as lojas conectadas).
 *  - Confere o x-signature com a assinatura secreta (sem ela configurada, só fora de produção).
 *  - "order": nunca confia no corpo — consulta o pedido no Mercado Pago com o acesso da loja e aplica.
 *    O mesmo pagamento pode avisar mais de uma vez (e pelo tópico legado "payment"): a baixa é idempotente.
 *  - "mp-connect" (application.deauthorized): o lojista revogou o acesso → a conta fica para conectar de novo.
 * Responde 200 rápido (o Mercado Pago repete se não tiver resposta em 22 s).
 */
class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $type = (string) ($request->query('type') ?? $request->query('topic') ?? $request->input('type') ?? '');
        // A assinatura usa o "data.id" do endereço, como veio (o PHP troca o ponto por "_"); o corpo só completa o id
        $signedId = (string) ($request->query('data_id') ?? '');
        $dataId = $signedId !== '' ? $signedId : (string) ($request->input('data.id') ?? '');

        $secret = (string) config('services.mercadopago.webhook_secret');
        if ($secret === '') {
            if (app()->isProduction()) {
                Log::error('Mercado Pago: aviso recebido sem MERCADOPAGO_WEBHOOK_SECRET configurado.');

                return response()->json(['ok' => false], 503);
            }
        } elseif (! MercadoPago::validSignature($request->header('x-signature'), $request->header('x-request-id'), $signedId, $secret)) {
            Log::warning('Mercado Pago: aviso com assinatura inválida ('.$type.' '.$dataId.').');

            return response()->json(['ok' => false], 401);
        }

        if ($type === 'order' && $dataId !== '') {
            $charge = PaymentCharge::where('provider', 'mercadopago')->where('external_id', $dataId)->first();
            $userId = (string) $request->input('user_id', '');
            // O aviso tem de ser da conta conectada pela loja dona do Pix
            $owner = $charge ? PaymentAccount::where('company_id', $charge->company_id)->value('provider_user_id') : null;
            if ($charge && ($userId === '' || $owner === null || $owner === $userId)) {
                $chargeId = $charge->id;
                Background::run(static fn () => app(PixCharges::class)->sync(PaymentCharge::findOrFail($chargeId), force: true));
            }
        } elseif ($type === 'mp-connect' && str_contains((string) $request->input('action'), 'deauthorized')) {
            $userId = (string) $request->input('user_id', $request->input('data.id', ''));
            if ($userId !== '') {
                app(MercadoPagoAccounts::class)->revokeByProviderUser($userId);
            }
        }

        return response()->json(['ok' => true]);
    }
}
