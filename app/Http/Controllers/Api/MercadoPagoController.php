<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\EnforcesPlanLimits;
use App\Http\Controllers\Controller;
use App\Services\MercadoPago;
use App\Services\MercadoPagoAccounts;
use App\Services\PixCharges;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Integrações → Mercado Pago (Pro, só o dono): status, "Conectar com Mercado Pago" (OAuth) e desconectar.
 * A volta do Mercado Pago (callback) é pública e redireciona para o app com o resultado.
 */
class MercadoPagoController extends Controller
{
    use EnforcesPlanLimits;

    public function __construct(private MercadoPagoAccounts $accounts) {}

    public function show(Request $request)
    {
        $company = $request->user()->company;
        $account = $this->accounts->account($company);
        $test = (bool) config('services.mercadopago.test_access_token') && ! app()->isProduction();

        return response()->json([
            'available' => MercadoPago::configured() || $test,
            'pro' => $company->isPro(),
            'test_mode' => $test,
            'connected' => (bool) $account?->isActive(),
            'rules' => PixCharges::rules($company),
            'account' => $account ? [
                'connected_at' => $account->connected_at?->toIso8601String(),
                'live_mode' => $account->live_mode,
                'revoked' => ! $account->isActive(),
                'error' => $account->revoked_at ? 'O acesso foi revogado ou venceu. Conecte de novo.' : null,
            ] : null,
        ]);
    }

    public function connect(Request $request)
    {
        $this->requirePro($request, 'Pagamento online');
        try {
            return response()->json(['url' => $this->accounts->connectUrl($request->user()->company, $request->user()->id)]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** Regra do Pix que o bot manda no pedido do WhatsApp: valor total, sinal de X% nas encomendas ou nada. */
    public function updateRules(Request $request)
    {
        $this->requirePro($request, 'Pagamento online');
        $data = $request->validate([
            'bot_pix' => ['required', 'in:total,deposit,off'],
            'deposit_percent' => ['required_if:bot_pix,deposit', 'nullable', 'integer', 'min:10', 'max:90'],
        ]);
        $setting = $request->user()->company->setting;
        $setting->update(['payment_rules' => ['bot_pix' => $data['bot_pix'], 'deposit_percent' => (int) ($data['deposit_percent'] ?? 50)]]);

        return response()->json(PixCharges::rules($request->user()->company->fresh('setting')));
    }

    public function disconnect(Request $request)
    {
        $this->accounts->disconnect($request->user()->company);

        return response()->json(['connected' => false]);
    }

    /** GET /mercadopago/callback?code=...&state=... (o navegador do lojista volta do Mercado Pago). */
    public function callback(Request $request)
    {
        $target = rtrim((string) config('services.frontend_url'), '/').'/integrations';
        try {
            $this->accounts->finish($request->query('state'), $request->query('code'));

            return redirect()->away($target.'?mercadopago=conectado#mercadopago');
        } catch (RuntimeException $e) {
            return redirect()->away($target.'?'.http_build_query(['mercadopago' => 'erro', 'motivo' => $e->getMessage()]).'#mercadopago');
        }
    }
}
